<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StoreAttachmentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAttachment;
use App\Services\PurchaseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adjuntos de PO (factura, remisión, soporte de pago).
 *
 * - GET    /api/v1/purchases/{id}/attachments               — listar.
 * - POST   /api/v1/purchases/{id}/attachments               — subir (multipart).
 * - GET    /api/v1/purchases/{id}/attachments/{aid}/url      — URL temporal (preview/descarga).
 * - GET    /api/v1/purchases/{id}/attachments/{aid}/download — stream (fallback local).
 * - DELETE /api/v1/purchases/{id}/attachments/{aid}         — soft-delete.
 */
class PurchaseAttachmentController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly PurchaseAttachmentService $attachments) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $po = $this->resolvePO($request, $id);

        return response()->json([
            'data' => $po->attachments->map(fn (PurchaseOrderAttachment $a) => $this->serialize($a))->all(),
        ]);
    }

    public function store(StoreAttachmentRequest $request, string $id): JsonResponse
    {
        $po = $this->resolvePO($request, $id);
        $validated = $request->validated();

        $attachment = $this->attachments->store(
            $po,
            $request->file('file'),
            $validated['type'],
            $this->actingUser($request),
        );

        return response()->json(['data' => $this->serialize($attachment)], 201);
    }

    /**
     * Devuelve una URL **temporal** para previsualizar o descargar el adjunto.
     *
     * En S3 (qa/pdn) firma con TTL corto (10 min) y deja que el objeto se sirva
     * directo desde el bucket — sin streamear por la EC2 (N-instance safe). El
     * `disposition` controla si el navegador lo abre inline (preview) o lo baja.
     * En disco local (dev sin S3) cae al endpoint `download` propio, que sí
     * streamea, porque el driver local no soporta `temporaryUrl`.
     */
    public function url(Request $request, string $id, string $attachmentId): JsonResponse
    {
        $po = $this->resolvePO($request, $id);
        $attachment = $po->attachments()->findOrFail($attachmentId);

        $disk = (string) config('purchases.attachment_disk', 'local');
        $disposition = $request->query('disposition') === 'attachment' ? 'attachment' : 'inline';

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            $expiresAt = now()->addMinutes(10);
            $url = Storage::disk($disk)->temporaryUrl(
                $attachment->path,
                $expiresAt,
                [
                    'ResponseContentDisposition' => $disposition.'; filename="'.addslashes($attachment->original_name).'"',
                    'ResponseContentType' => $attachment->mime,
                ],
            );

            return response()->json(['data' => ['url' => $url, 'expires_at' => $expiresAt->toIso8601String()]]);
        }

        // Fallback dev: ruta de streaming propia (auth por cookie de sesión).
        $url = route('api.purchases.attachments.download', [
            'id' => $id,
            'attachmentId' => $attachmentId,
            'inline' => $disposition === 'inline' ? 1 : null,
        ]);

        return response()->json(['data' => ['url' => $url, 'expires_at' => null]]);
    }

    public function download(Request $request, string $id, string $attachmentId): StreamedResponse
    {
        $po = $this->resolvePO($request, $id);
        $attachment = $po->attachments()->findOrFail($attachmentId);

        // El path vive en el disco configurado por `config/purchases.php` (en
        // QA/PDN = `s3_documents`). Antes leíamos con `storage_path('app/'...)`
        // → siempre del filesystem EC2-local, lo cual rompía multi-instancia
        // ASG y dejaba la descarga 404 cuando el archivo estaba en S3.
        $disk = (string) config('purchases.attachment_disk', 'local');

        // `?inline=1` → previsualización inline; por defecto fuerza descarga.
        if ($request->boolean('inline')) {
            return Storage::disk($disk)->response(
                $attachment->path,
                $attachment->original_name,
                ['Content-Type' => $attachment->mime],
            );
        }

        return Storage::disk($disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime],
        );
    }

    public function destroy(Request $request, string $id, string $attachmentId): JsonResponse
    {
        $po = $this->resolvePO($request, $id);
        $attachment = $po->attachments()->findOrFail($attachmentId);

        $this->attachments->destroy($attachment, $this->actingUser($request));

        return response()->json(['data' => ['id' => $attachmentId, 'deleted' => true]]);
    }

    private function resolvePO(Request $request, string $id): PurchaseOrder
    {
        $companyNit = $this->activeCompanyNit($request);

        return PurchaseOrder::forCompany($companyNit)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function serialize(PurchaseOrderAttachment $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'uploaded_by' => $a->uploaded_by,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
