<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Proxy de firma para assets públicos en S3.
 *
 * Recibe un path bajo `/storage-proxy/{path}` (registrado en `routes/web.php`),
 * verifica que el prefijo sea uno de los permitidos para el bucket de assets,
 * firma con TTL corto y redirige (302) a la URL temporal de S3.
 *
 * Por qué proxy y no URL firmada directa al cliente: el cliente nunca ve la
 * URL del bucket → cambios de bucket, región o storage backend son transparentes.
 * Además, el dominio propio permite políticas de Cache-Control y aislar la
 * superficie de acceso (en el futuro podríamos agregar autorización, rate
 * limit, métricas, etc.).
 *
 * El bucket `documents` (PDFs DIAN, comprobantes financieros) **no** usa este
 * proxy: tiene su propio flujo con `temporaryUrl()` desde controllers
 * específicos y políticas de retención de 5–10 años.
 */
class StorageProxyController extends Controller
{
    /**
     * Prefijos permitidos en `path`. Cualquier path fuera de esta lista
     * devuelve 403, aún si existe en el bucket.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'companies/',
        'menus/',
        // `chat-media/` se retiró de este proxy anónimo. La media de
        // chats es privada (conversaciones de clientes) y ahora se sirve por el
        // endpoint autenticado `GET /api/v1/chats/{id}/messages/{messageId}/media`
        // (scope de empresa + chats.read). Solo quedan assets genuinamente
        // públicos (logos, QR, fotos de menú).
    ];

    /**
     * TTL de la URL firmada que devuelve S3 (60 min). El header `Cache-Control: max-age` del redirect se setea
     * con un margen para evitar entregar URLs caducadas a clientes que cachean.
     */
    private const SIGNED_URL_TTL_MINUTES = 60;

    /**
     * Margen de seguridad: el browser cachea el redirect por TTL - 5 min para
     * garantizar que la URL firmada siga vigente cuando el browser la consuma.
     */
    private const REDIRECT_CACHE_TTL_SECONDS = (self::SIGNED_URL_TTL_MINUTES - 5) * 60;

    public function show(string $path): RedirectResponse
    {
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new HttpException(400, 'Invalid path');
        }

        if (! $this->isAllowed($path)) {
            throw new HttpException(403, 'Forbidden');
        }

        $disk = (string) config('filesystems.default');
        $url = Storage::disk($disk)->temporaryUrl(
            $path,
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
        );

        return redirect()->away($url, 302)->withHeaders([
            'Cache-Control' => 'public, max-age='.self::REDIRECT_CACHE_TTL_SECONDS,
        ]);
    }

    private function isAllowed(string $path): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
