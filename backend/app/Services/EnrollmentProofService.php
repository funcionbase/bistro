<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\EnrollmentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persiste el documento de propiedad subido durante el
 * enrolamiento.
 *
 * El archivo se almacena en el disk privado `s3_documents` bajo la key
 * `enrollment-proofs/{company_nit}/{timestamp}-{slug}.{ext}`. La fila en
 * `enrollment_proofs` guarda sólo metadatos para localizarlo y auditarlo.
 *
 * Reglas:
 *  - Se asume que el caller (Form Request) ya validó mime y tamaño. Aquí no
 *    se re-valida: el servicio confía en el boundary HTTP.
 *  - Ejecuta dentro de la transacción del controller — la caller decide el
 *    scope transaccional para que el upload de S3 y la fila de BD se
 *    persistan/reviertan juntos con el resto del enrolamiento.
 *  - Auditoría: action `enrollment.proof_uploaded` con metadatos del archivo.
 *
 * No mutación: la app no expone endpoints para reemplazar la evidencia. Un
 * eventual flujo de re-upload (otro issue) hará soft-delete del row anterior
 * y creará uno nuevo.
 */
class EnrollmentProofService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function store(Company $company, UploadedFile $file, User $uploader): EnrollmentProof
    {
        $disk = 's3_documents';
        $extension = $file->getClientOriginalExtension() ?: $this->extensionFromMime((string) $file->getMimeType());
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        if ($slug === '') {
            $slug = 'documento';
        }

        $key = sprintf(
            'enrollment-proofs/%s/%s-%s.%s',
            $company->nit,
            now()->format('YmdHis'),
            Str::limit($slug, 60, ''),
            strtolower($extension),
        );

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $stored = Storage::disk($disk)->put($key, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        abort_if($stored === false, 500, 'No se pudo almacenar la evidencia de enrolamiento.');

        $proof = EnrollmentProof::create([
            'company_nit' => $company->nit,
            'disk' => $disk,
            's3_key' => $key,
            'mime_type' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'original_filename' => (string) $file->getClientOriginalName(),
            'uploaded_by_user_id' => $uploader->id,
            'uploaded_at' => now(),
        ]);

        $this->auditService->log('enrollment.proof_uploaded', $uploader, $proof, [
            'company_nit' => $company->nit,
            'disk' => $disk,
            's3_key' => $key,
            'mime_type' => $proof->mime_type,
            'file_size' => $proof->file_size,
            'original_filename' => $proof->original_filename,
        ]);

        return $proof;
    }

    /**
     * URL firmada de vida corta para que el dueño del documento previsualice
     * lo que adjuntó. No se expone a terceros — el caller (controller) debe
     * autorizar antes de invocar.
     */
    public function temporaryUrl(EnrollmentProof $proof, int $minutes = 15): string
    {
        return Storage::disk($proof->disk)->temporaryUrl($proof->s3_key, now()->addMinutes($minutes));
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }
}
