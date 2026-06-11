<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentProofUploadRequest;
use App\Models\PaymentProof;
use App\Notifications\PaymentProofSubmittedNotification;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobantes de pago manuales del cliente.
 *
 * Endpoints (todos bajo `permission:billing.read,read` — solo owner+admin del
 * template tienen ese permiso por default, lo que restringe el acceso a los
 * comprobantes a esos dos roles. Endpoint `show` además aplica gate
 * explícito a `user_role ∈ {owner, admin}` como defensa en profundidad si
 * un cliente decide otorgar `billing.read` a un rol custom):
 *
 *  - POST /api/v1/billing/payment-proofs            (upload, max 10MB, mime pdf|jpg|png)
 *  - GET  /api/v1/billing/payment-proofs            (historial del NIT activo)
 *  - GET  /api/v1/billing/payment-proofs/{uuid}     (stream inline del archivo, #193)
 *
 * El upload NO muta `companies.status` — la decisión sigue siendo manual de
 * ops, validando el comprobante y marcando las invoices como `paid`. El cron
 * diario detecta la liquidación y mueve la empresa a `active`.
 *
 * Notifica a `BILLING_OPS_EMAIL` con URL firmada (TTL 24h) al S3.
 *
 * Identificador externo: el endpoint `show` recibe el UUID del comprobante,
 * NO el id numérico, para evitar enumeración del orden y volumen de
 * comprobantes de la empresa propia (`/1`, `/2`, …) por un atacante con
 * cookie autenticada. El id BIGSERIAL se mantiene como PK interno.
 */
class PaymentProofController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function store(PaymentProofUploadRequest $request): JsonResponse
    {
        if ($guard = $this->ensureOwnerOrAdmin($request)) {
            return $guard;
        }

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload') ?? [];
        $userId = $jwtPayload['user_id'] ?? null;

        if (! $companyNit) {
            return response()->json(['message' => 'Empresa activa no resuelta.'], 422);
        }

        $disk = config('billing.payment_proof_disk', 's3_documents');
        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $path = "companies/{$companyNit}/payment-proofs/".Str::uuid()->toString().'.'.$extension;

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private']
        );

        $proof = DB::transaction(function () use ($request, $companyNit, $userId, $path, $file) {
            $proof = PaymentProof::create([
                'company_nit' => $companyNit,
                'invoice_ids' => $request->input('invoice_ids', []),
                'uploaded_by_user_id' => $userId,
                'file_path' => $path,
                'mime' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(),
                'status' => 'submitted',
            ]);

            $this->auditService->log('payment_proof.submitted', null, $proof, [
                'company_nit' => $companyNit,
                'file_size' => $proof->size_bytes,
                'mime' => $proof->mime,
                'invoice_ids' => $proof->invoice_ids,
                'notes' => $request->input('notes'),
            ]);

            return $proof;
        });

        $opsEmail = config('billing.ops_email');
        if (! empty($opsEmail)) {
            Notification::route('mail', $opsEmail)
                ->notify(new PaymentProofSubmittedNotification($proof));
        }

        return response()->json([
            // Devolvemos el UUID en `id` para que el frontend nunca toque el
            // BIGSERIAL interno y el contrato externo sea consistente con
            // los demás endpoints (index/show).
            'id' => $proof->uuid,
            'status' => $proof->status,
            'created_at' => $proof->created_at->toIso8601String(),
            'message' => 'Comprobante recibido. Te avisaremos al validarlo.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        if ($guard = $this->ensureOwnerOrAdmin($request)) {
            return $guard;
        }

        $companyNit = $request->attributes->get('active_company_nit');

        if (! $companyNit) {
            return response()->json(['message' => 'Empresa activa no resuelta.'], 422);
        }

        $proofs = PaymentProof::query()
            ->where('company_nit', $companyNit)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['uuid', 'invoice_ids', 'original_name', 'mime', 'size_bytes', 'status', 'reviewed_at', 'review_notes', 'created_at']);

        // Serializamos `uuid` como `id` público + adjuntamos `preview_url`
        // apuntando al endpoint `show` para que el frontend renderice
        // thumbnail/modal sin adivinar la ruta. La URL exige sesión
        // autenticada (cookie HttpOnly) y se valida por empresa activa.
        $data = $proofs->map(fn (PaymentProof $proof): array => [
            'id' => $proof->uuid,
            'invoice_ids' => $proof->invoice_ids,
            'original_name' => $proof->original_name,
            'mime' => $proof->mime,
            'size_bytes' => $proof->size_bytes,
            'status' => $proof->status,
            'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
            'review_notes' => $proof->review_notes,
            'created_at' => $proof->created_at->toIso8601String(),
            'preview_url' => route('api.billing.payment-proofs.show', ['proof' => $proof->uuid]),
        ])->all();

        return response()->json(['data' => $data]);
    }

    /**
     * Stream del archivo del comprobante para previsualización inline.
     *
     * Recibe el UUID del proof (no el id numérico, ver docblock de la clase).
     * Valida que el proof pertenezca a la empresa activa — sin ese check, un
     * usuario con cookie válida de empresa A podría leer comprobantes de B
     * con sólo conocer el UUID.
     *
     * Responde con `Content-Disposition: inline` y el mime stored para que
     * el browser lo renderice (imagen, PDF) sin descarga forzada.
     */
    public function show(Request $request, string $proof): StreamedResponse|JsonResponse
    {
        if ($guard = $this->ensureOwnerOrAdmin($request)) {
            return $guard;
        }

        $companyNit = $request->attributes->get('active_company_nit');

        if (! $companyNit) {
            return response()->json(['message' => 'Empresa activa no resuelta.'], 422);
        }

        /** @var PaymentProof|null $record */
        $record = PaymentProof::query()
            ->where('uuid', $proof)
            ->where('company_nit', $companyNit)
            ->first();

        if ($record === null) {
            return response()->json(['message' => 'Comprobante no encontrado.'], 404);
        }

        $disk = config('billing.payment_proof_disk', 's3_documents');

        if (! Storage::disk($disk)->exists($record->file_path)) {
            return response()->json(['message' => 'El archivo del comprobante no está disponible.'], 410);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $record->original_name) ?: "comprobante-{$record->uuid}";

        return Storage::disk($disk)->response($record->file_path, $safeName, [
            'Content-Type' => $record->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$safeName.'"',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Gate explícito: sólo owner (`is_system=true`) o admin (rol cuyo nombre
     * coincide con `config('roles.role_names.admin')`) pueden ver/subir
     * comprobantes de pago. El template seed ya restringe `billing.read` a
     * estos dos roles, pero si un cliente decide otorgar ese permiso a un
     * rol custom, este gate adicional previene el acceso.
     */
    private function ensureOwnerOrAdmin(Request $request): ?JsonResponse
    {
        $isSystem = (bool) $request->attributes->get('company_role_is_system', false);
        if ($isSystem) {
            return null;
        }

        $jwtPayload = $request->attributes->get('jwt_payload') ?? [];
        $roleName = strtolower((string) ($jwtPayload['role']['name'] ?? ''));
        $adminRoleName = strtolower((string) config('roles.role_names.admin', 'Administrador'));

        if ($roleName === $adminRoleName || $roleName === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'Solo el propietario o un administrador pueden acceder a los comprobantes de pago.',
            'code' => 'payment_proofs_owner_admin_only',
        ], 403);
    }
}
