<?php

use App\Http\Middleware\AllowConsolidatedBranches;
use App\Http\Middleware\CacheControlGet;
use App\Http\Middleware\EnsureBranchAccess;
use App\Http\Middleware\EnsureBusinessCapability;
use App\Http\Middleware\EnsureCompanyAccess;
use App\Http\Middleware\EnsureCompanyNotBlocked;
use App\Http\Middleware\EnsureCompanyVerified;
use App\Http\Middleware\EnsureFeaturePermission;
use App\Http\Middleware\EnsureKdsDeviceToken;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\NormalizeStrings;
use App\Http\Middleware\ResolveTableGuest;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateBotJwt;
use App\Http\Middleware\ValidateJwt;
use App\Services\JwtService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // El ALB termina TLS y reenvia HTTP al EC2 con X-Forwarded-Proto=https.
        // Sin trustProxies, Laravel ve scheme=http y genera URLs/assets http://
        // → mixed-content en el navegador. La SG del EC2 solo deja entrar
        // trafico del ALB, asi que confiar en '*' es seguro aqui.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);

        // Normaliza Unicode (NFC) en strings del payload. Aplica a TODAS las
        // rutas web y api salvo webhooks externos (whatsapp, csp-report) que
        // dependen de la firma byte-exact del payload. Ver
        // `docs/wiki/SECURITY_INPUT_HANDLING.md`.
        $middleware->web(prepend: [
            NormalizeStrings::class,
        ]);

        $middleware->api(prepend: [
            NormalizeStrings::class,
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
            // #193 — bloqueo comercial por mora en rutas web.
            EnsureCompanyNotBlocked::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Las rutas /api/* siempre negocian JSON: sin esto, los clientes que
        // olvidan el header Accept reciben 302 + HTML cuando el validador falla.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // La cookie HttpOnly del JWT (`flexyflow_jwt`) se excluye del cifrado de Laravel
        // porque sólo se cifra automáticamente en rutas web; las rutas API no tienen
        // EncryptCookies en su stack y la recibirían cifrada (ilegible). El JWT viene
        // ya cifrado AES-256 + firmado HMAC por JwtService, así que no se pierde
        // confidencialidad — y la cookie sigue siendo HttpOnly + Secure + SameSite=Lax.
        //
        // #115 — `kds_device_token` también se excluye por la MISMA razón: el flujo
        // del KDS pasa por dos stacks (web para `/kds/{stationSlug}`, api para
        // `/api/v1/kds/{stationSlug}/*`). Si el stack web encripta la cookie y el
        // stack api la recibe sin EncryptCookies (no aplica al grupo api), la
        // resolución por hash falla y el dispositivo queda en 401 perpetuo. El
        // token ya es un secreto aleatorio de 48 chars guardado solo como SHA-256
        // en BD; la confidencialidad vive en HttpOnly + Secure según TLS, no en
        // el cifrado simétrico extra de Laravel.
        // #191 — `tdt_*` (table device token) se excluye del cifrado por la
        // MISMA razón que `kds_device_token`: el flujo público de mesa con QR
        // se sirve íntegramente desde el stack API (`/api/v1/public/table/*`),
        // que no monta EncryptCookies. La cookie se escribe y se lee sin
        // cifrar — consistente en ambos extremos. El valor es el `device_token`
        // (string aleatorio de 40 chars, también persistido en BD); la
        // confidencialidad vive en HttpOnly + Secure, no en el cifrado extra.
        // El patrón `tdt_*` usa wildcard (cada QR tiene su propio sufijo hash).
        $middleware->encryptCookies(except: [
            JwtService::COOKIE_NAME,
            'kds_device_token',
            'tdt_*',
        ]);

        $middleware->alias([
            'jwt' => ValidateJwt::class,
            'company.access' => EnsureCompanyAccess::class,
            'company.verified' => EnsureCompanyVerified::class,
            'company.not_blocked' => EnsureCompanyNotBlocked::class,
            'branch.access' => EnsureBranchAccess::class,
            'branch.consolidate' => AllowConsolidatedBranches::class,
            'business.capability' => EnsureBusinessCapability::class,
            'bot.jwt' => ValidateBotJwt::class,
            'permission' => EnsureFeaturePermission::class,
            'cache.get' => CacheControlGet::class,
            'table.guest' => ResolveTableGuest::class,
            'kds.device' => EnsureKdsDeviceToken::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('billing:generate-monthly-invoices')
            ->cron('0 3 20 * *')
            ->withoutOverlapping()
            ->onOneServer();

        // billing:mark-overdue-invoices se programa diario en routes/console.php
        // (#175). Antes era mensual (día 16), ahora debe correr a diario para que
        // los countdowns de past_due → suspended y las liquidaciones se reflejen
        // dentro de las 24h. onOneServer + cache_locks garantiza ejecución única.

        $schedule->command('billing:expire-discounts')
            ->cron('0 4 1 * *')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Requests que esperan JSON (API o cliente que envía Accept: application/json)
        // reciben un payload estructurado sin trace/file/line aunque APP_DEBUG=true.
        // Las web requests caen a las vistas en resources/views/errors/{status}.blade.php.
        //
        // ValidationException mantiene su comportamiento default (errors flashed/422
        // con campo `errors`). AuthenticationException también queda en el handler
        // nativo para preservar el redirect a /auth en web.
        $exceptions->render(function (Throwable $e, Illuminate\Http\Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            // Auth handler manages web redirect + API 401 correctly in both envs
            if ($e instanceof AuthenticationException) {
                return null;
            }

            // In debug mode keep full field-level validation detail for DX
            if (config('app.debug') && $e instanceof ValidationException) {
                return null;
            }

            $status = 500;
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
            } elseif ($e instanceof ValidationException) {
                $status = 422;
            }

            $message = match (true) {
                $status === 401 => 'No autenticado.',
                $status === 403 => 'No tienes permiso para realizar esta acción.',
                $status === 404 => 'Recurso no encontrado.',
                $status === 405 => 'Método no permitido.',
                $status === 419 => 'Tu sesión expiró. Recarga la página.',
                $status === 422 => 'Datos de entrada inválidos.',
                $status === 429 => 'Demasiadas solicitudes. Intenta más tarde.',
                $status >= 500 => config('app.debug')
                    ? ($e->getMessage() ?: 'Error interno del servidor.')
                    : 'Algo se rompió de nuestro lado. Ya estamos investigando.',
                default => config('app.debug') ? ($e->getMessage() ?: 'Error en la solicitud.') : 'Error en la solicitud.',
            };

            $payload = ['message' => $message];

            if (! config('app.debug') && $status >= 500) {
                $ref = strtoupper(bin2hex(random_bytes(5)));
                $payload['ref'] = $ref;
                logger()->error("API {$status} [{$ref}]", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ]);
            }

            return response()->json($payload, $status, [
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        });
    })->create();
