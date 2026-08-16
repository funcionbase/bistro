<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resuelve URLs para assets almacenados en S3 (logos, QR, imágenes de menú,
 * íconos PWA, media de chat) emitiendo una URL **estable del dominio propio**
 * que pasa por `StorageProxyController`.
 *
 * Motivación: el bucket de assets dejó de ser de acceso público
 * anónimo. El proxy firma con TTL corto en cada hit y redirige (302) a una
 * URL temporal de S3 con `X-Amz-Signature`. Para el cliente, la URL es siempre
 * del dominio del ALB → ningún detalle del bucket queda expuesto.
 *
 * Para discos locales (`local`, `public`) se sigue devolviendo `Storage::url()`
 * sin firma para mantener el flujo de desarrollo sin docker / sin S3.
 */
class SignedAssetUrl
{
    /**
     * Devuelve la URL pública (estable) para un path en el disco de assets.
     *
     * @param  string|null  $path  Ruta del objeto en el disco (ej. `companies/123/logo.png`).
     * @param  string|null  $disk  Disco origen. `null` = `config('filesystems.default')`.
     */
    public static function for(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk ??= (string) config('filesystems.default');

        if (in_array($disk, ['local', 'public'], true)) {
            return Storage::disk($disk)->url($path);
        }

        return url('/storage-proxy/'.ltrim($path, '/'));
    }
}
