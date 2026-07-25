<?php

namespace App\Providers;

use App\Events\OrderItemSubmittedForApproval;
use App\Listeners\AbortIfSuppressed;
use App\Listeners\NotifyPendingApprovalListener;
use App\Models\CompanyRolePermission;
use App\Models\User;
use App\Notifications\Channels\DedupedMailChannel;
use App\Observers\CompanyRolePermissionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ImageManager con driver GD para rasterización de logos PWA (#140).
        // GD viene con PHP por defecto en la mayoría de hosts; si en producción
        // se prefiere Imagick, basta swappear el driver aquí.
        $this->app->singleton(ImageManager::class, fn () => new ImageManager(new GdDriver));
    }

    public function boot(): void
    {
        // Observers de modelos.
        CompanyRolePermission::observe(CompanyRolePermissionObserver::class);

        // #257 — Canal de correo idempotente para notifs billing. Deduplica a
        // nivel (notif, user) en el momento del envio (dentro del worker), de
        // modo que un reintento de cola no dispare un segundo correo. Las notifs
        // billing devuelven ['deduped_mail'] en via().
        $this->app->resolving(ChannelManager::class, function (ChannelManager $manager): void {
            $manager->extend('deduped_mail', fn ($app) => $app->make(DedupedMailChannel::class));
        });

        // Alinea la sesion de Postgres con APP_TIMEZONE para que las columnas
        // timestamptz interpreten correctamente los timestamps "naive" que
        // Eloquent envia en escrituras y bindings de queries. Sin esto, `now()`
        // (Bogota) se guardaba como UTC y se mostraba 5h atras al releer.
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'pgsql') {
                $tz = config('app.timezone', 'UTC');
                $event->connection->statement("SET TIME ZONE '".$tz."'");
            }
        });

        // Listener queued para push notifications de pending approvals.
        // El registro explícito (en lugar de depender de auto-discovery) deja
        // explícita la dependencia evento → listener y sobrevive cambios de
        // estructura de directorios.
        Event::listen(
            OrderItemSubmittedForApproval::class,
            NotifyPendingApprovalListener::class,
        );

        // SES bounce/complaint handling: aborta envío si el
        // destinatario está en la suppression list. Sincronico porque
        // necesitamos devolver `false` ANTES de que el transport entregue.
        // Una consulta al índice parcial por envío — barata.
        Event::listen(
            MessageSending::class,
            AbortIfSuppressed::class,
        );

        RateLimiter::for('oauth', function (Request $request) {
            $limit = (int) config('auth.oauth_rate_limit', 10);

            return Limit::perMinute($limit)->by($request->ip());
        });

        // Acceso con correo/contraseña (complementario a Google). Techo global
        // por IP contra credential stuffing multi-correo; el lockout fino por
        // email+IP (5 intentos / 60s, patrón Fortify) vive en EmailAuthController.
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(20)->by('auth-login-ip:'.$request->ip());
        });

        // Registro: escritura + envío de correo → límite apretado por IP.
        // 5 cada 15 min frena bots de creación masiva sin estorbar a un
        // humano legítimo (1 registro por visita).
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinutes(15, 5)->by('auth-register-ip:'.$request->ip());
        });

        // Forgot/reset: cada request puede disparar un correo (SES) — 5 cada
        // 15 min por IP + límite fino por email+IP en el controller.
        RateLimiter::for('auth-forgot', function (Request $request) {
            return Limit::perMinutes(15, 5)->by('auth-forgot-ip:'.$request->ip());
        });

        // Contraseñas: mínimo 8 + rechazo de contraseñas filtradas (HIBP via
        // k-anonymity — nunca se envía la contraseña). Aplica a registro,
        // reset y cambio en ajustes vía Password::defaults().
        Password::defaults(function () {
            return Password::min(8)->uncompromised();
        });

        // Rate limit general para rutas API autenticadas. Llave preferida es
        // JWT.sub (usuario), fallback a IP cuando no hay sesión. 240 req/min
        // cubre dashboard polling (cada 10-30s) + interacción humana sin
        // estorbar; flujos batch (sync offline) van por endpoints específicos.
        RateLimiter::for('api', function (Request $request) {
            $payload = $request->attributes->get('jwt_payload');
            $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;
            $key = $userId !== null ? "user:{$userId}" : "ip:{$request->ip()}";

            return Limit::perMinute(240)->by($key);
        });

        // Telemetría pública del menú: 30 req/min por IP+nit. El POST de scan no
        // se reintenta en cliente (es fire-and-forget), así que un 429 esporádico
        // simplemente descarta ese evento — aceptable para analítica.
        RateLimiter::for('menu-scan-public', function (Request $request) {
            $nit = $request->route('nit') ?? 'unknown';

            return Limit::perMinute(30)->by($request->ip().':'.$nit);
        });

        // Fidelización pública (#122): consulta/canje desde menú público.
        // 10 req/min por IP+nit — protege contra enumeración de phones.
        RateLimiter::for('loyalty-public', function (Request $request) {
            $nit = $request->route('nit') ?? 'unknown';

            return Limit::perMinute(10)->by($request->ip().':'.$nit);
        });

        // Pedido público sin mesa desde el QR de sede: escritura → límite
        // apretado. 5/min por IP+token frena spam de órdenes basura sin
        // estorbar a un cliente legítimo (1 pedido por visita).
        RateLimiter::for('branch-order-public', function (Request $request) {
            $token = $request->route('menu_qr_token') ?? 'unknown';

            return Limit::perMinute(5)->by($request->ip().':'.$token);
        });

        // Sesión de carta pública (/menus?cart={uuid}): pings de actividad,
        // estado de órdenes (polling ~12s) y append de items. 30/min por
        // IP+token cubre el polling legítimo sin invitar enumeración.
        RateLimiter::for('cart-public', function (Request $request) {
            $token = $request->route('token') ?? 'unknown';

            return Limit::perMinute(30)->by($request->ip().':'.$token);
        });

        // KDS device-token (#115): tablets de cocina con polling cada 2s
        // pueden hacer hasta ~30 req/min en lectura + acciones manuales del
        // cocinero. 60/min per token da margen sin invitar abuso. La key es
        // el token_hash inyectado por EnsureKdsDeviceToken — sin token, se
        // cae al IP del request (caso pre-auth).
        RateLimiter::for('kds-device', function (Request $request) {
            $tokenId = $request->attributes->get('kds_device_token_id');
            $key = $tokenId !== null ? "kds-token:{$tokenId}" : "kds-ip:{$request->ip()}";

            return Limit::perMinute(60)->by($key);
        });

        // Mesa pública (#191): endpoint /t/{qr_token}/*. Doble bucket — uno por
        // IP para frenar abuso individual y otro por QR para tolerar el pico
        // legítimo de un grupo grande de comensales escaneando la misma mesa.
        // Defaults (30/IP y 200/QR) configurables vía config/tables.php.
        RateLimiter::for('table-public', function (Request $request) {
            $qr = $request->route('qr_token') ?? 'unknown';
            $perIp = (int) config('tables.rate_limit.per_ip_per_minute', 30);
            $perQr = (int) config('tables.rate_limit.per_qr_per_minute', 200);

            return [
                Limit::perMinute($perIp)->by('table-ip:'.$request->ip()),
                Limit::perMinute($perQr)->by('table-qr:'.$qr),
            ];
        });

        // Mensaje de prueba de WhatsApp (F5): envía un WhatsApp REAL. Límite POR
        // CANAL (`{id}`), no por IP/usuario — así "probar que conectó" no comparte
        // cubeta entre canales de la misma empresa ni entre operadores, y un canal
        // ruidoso no le gasta el cupo a otro. 6/min sobra para el caso legítimo.
        RateLimiter::for('whatsapp-test-message', function (Request $request) {
            return Limit::perMinute(6)->by('wa-test:'.($request->route('id') ?? 'unknown'));
        });

        Gate::define('manage-company-roles', function (User $user, Request $request) {
            $role = $request->attributes->get('jwt_payload')['role'] ?? null;

            return is_array($role) && ($role['is_system'] ?? false);
        });

        $slowQueryThreshold = (int) env('DB_SLOW_QUERY_THRESHOLD', 0);

        if ($slowQueryThreshold > 0) {
            DB::listen(function ($query) use ($slowQueryThreshold) {
                if ($query->time > $slowQueryThreshold) {
                    Log::warning('Slow query detected', [
                        'time_ms' => $query->time,
                        'sql' => $query->sql,
                    ]);
                }
            });
        }
    }
}
