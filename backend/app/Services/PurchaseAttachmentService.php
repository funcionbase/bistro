<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de adjuntos PDF/imagen para órdenes de compra. Storage local en
 * `storage/app/purchases/{po_id}/{hash}.{ext}`.
 *
 * Conservación: soft-delete por exigencia DIAN (5–10 años). El archivo
 * físico SÍ se elimina al hacer soft-delete porque el row del modelo y los
 * AuditLogs preservan la metadata; si se requiere mantener bytes, ajustar
 * aquí. Reglas de validación viven en `config/purchases.php`.
 */
class PurchaseAttachmentService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function store(PurchaseOrder $po, UploadedFile $file, string $type, ?User $actor): PurchaseOrderAttachment
    {
        $allowedTypes = (array) config('purchases.attachment_types');
        if (! in_array($type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de adjunto inválido.']);
        }

        $allowedMimes = (array) config('purchases.attachment_mimes');
        $maxBytes = (int) config('purchases.attachment_max_bytes');

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => "Tipo MIME no permitido: {$mime}."]);
        }
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Archivo excede el tamaño máximo ('.($maxBytes / 1024 / 1024).' MB).']);
        }

        $directory = "purchases/{$po->id}";
        $disk = (string) config('purchases.attachment_disk', 'local');
        $path = $file->store($directory, $disk);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo.']);
        }

        $attachment = PurchaseOrderAttachment::create([
            'purchase_order_id' => $po->id,
            'branch_id' => $po->branch_id,
            'type' => $type,
            'path' => $path,
            'original_name' => (string) $file->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $actor?->id,
        ]);

        $this->auditService->log('purchases.attachment.uploaded', $actor, $attachment, [
            'purchase_order_id' => $po->id,
            'type' => $type,
            'original_name' => $attachment->original_name,
            'size_bytes' => $attachment->size_bytes,
        ]);

        return $attachment;
    }

    /**
     * Soft-delete del adjunto. Físicamente borra el archivo del storage —
     * la metadata (nombre, tipo, tamaño, quién lo subió) sobrevive en la fila
     * soft-deleted y en AuditLog para trazabilidad.
     */
    public function destroy(PurchaseOrderAttachment $attachment, ?User $actor): void
    {
        Storage::disk((string) config('purchases.attachment_disk', 'local'))->delete($attachment->path);

        $attachment->delete(); // soft

        $this->auditService->log('purchases.attachment.deleted', $actor, $attachment, [
            'purchase_order_id' => $attachment->purchase_order_id,
            'original_name' => $attachment->original_name,
        ]);
    }
}
