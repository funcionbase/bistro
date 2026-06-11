<?php

use App\Http\Controllers\Auth\AccountEmailChangeController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\FrontendRedirectController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\StorageProxyController;
use App\Http\Controllers\Web\KdsStandaloneController;
use App\Http\Controllers\Web\PublicMenuPageController;
use App\Models\Company;
use Illuminate\Support\Facades\Route;

/*
 * resolveWebJwt() y resolveActiveCompany() ahora viven en
 * app/Support/web_helpers.php (autoloaded via composer.json `files`).
 *
 * Antes estaban acá, pero `route:cache` serializa las closures sin preservar
 * funciones top-level del file → 500 con "Call to undefined function" cuando
 * Laravel ejecuta rutas desde el caché. Moverlas a un archivo autoloaded
 * garantiza que estén disponibles antes de cualquier dispatch.
 */

// Health checks. /health/ready es el endpoint que el ALB usa (issue #43 T4).
// /health (nginx, "ok" estatico) sigue funcionando como fallback si PHP-FPM cae.
// throttle:60,1 (#174 P3-2) en defensa en profundidad: la SG ya bloquea
// trafico fuera del ALB, pero si alguien pivota dentro del cluster, esto
// limita la enumeracion. El ALB hace ~1 healthcheck cada 30s -> holgado.
Route::get('health/live', [HealthController::class, 'live'])
    ->middleware('throttle:60,1')
    ->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])
    ->middleware('throttle:60,1')
    ->name('health.ready');

// Landing pública — migrada al shell SPA (#220).
Route::get('/', FrontendRedirectController::class)->name('home');

// PWA Web App Manifest dinámico: si el visitante tiene JWT válido con empresa
// activa, el manifest hereda nombre y colores de esa empresa; si no, devuelve
// el branding flexyflow por defecto. Una sola URL evita lógica condicional en
// el blade y mantiene el `<link rel="manifest">` estático.
Route::get('manifest.webmanifest', [PwaManifestController::class, 'show'])
    ->name('pwa.manifest');

// Service Worker servido desde la raíz para que el `scope` por defecto sea `/`
// (Workbox lo emite dentro de public/build/). El header `Service-Worker-Allowed`
// no es necesario aquí porque el archivo se entrega desde el origen raíz.
Route::get('sw.js', [PwaManifestController::class, 'serviceWorker'])
    ->name('pwa.sw');

// Apple Touch Icon dinámico: iOS Safari NO consulta el manifest para esto;
// lee `<link rel="apple-touch-icon">` del HTML, que apunta fijo a esta URL.
// El controller resuelve la versión rasterizada del logo de la empresa
// activa (si existe) o cae al logo flexyflow black-font por defecto.
Route::get('apple-touch-icon.png', [PwaManifestController::class, 'appleTouchIcon'])
    ->name('pwa.apple-touch-icon');

// Proxy de firma para assets en S3 (issue #172). El bucket `assets` ya no
// permite acceso anónimo: este endpoint firma con TTL de 60 min y redirige
// (302) a la URL temporal. Sin auth: los logos y QR se incrustan en páginas
// públicas (menú del comensal); la autorización fina vive en el prefijo
// permitido (companies/, menus/, chat/) — ver StorageProxyController.
Route::get('storage-proxy/{path}', [StorageProxyController::class, 'show'])
    ->where('path', '.*')
    ->middleware('throttle:120,1')
    ->name('storage-proxy');
// throttle:120,1 por IP (#174 P1-2): el proxy oculta el bucket pero firmar URLs
// y enrutar 302 cuesta CPU al EC2 + S3 GET. 120/min cubre carga de dashboard
// y galerias del menu publico (logos, QR, fotos de producto) sin estorbar.

Route::get('dashboard', FrontendRedirectController::class)->name('dashboard');

// Página pública del menú (destino del QR impreso). Sin auth.
// El QR codifica /menus/{nit}; al montar el cliente guarda el NIT en localStorage
// y reemplaza la URL a /menus/ para una vista más limpia. /menus (sin NIT) lo
// resuelve desde localStorage al recargar.
Route::get('menus', PublicMenuPageController::class)->name('public.menu.alias');
Route::get('menus/{nit}', PublicMenuPageController::class)
    ->where('nit', '[A-Za-z0-9._-]+')
    ->name('public.menu');

Route::get('me', FrontendRedirectController::class)->name('me');

Route::middleware('throttle:oauth')->group(function () {
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google');

    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');
});

// Recuperación de cuenta por cambio de correo (cédula ya registrada).
// Ruta pública: la autorización es el token enviado al correo viejo. GET solo
// muestra (no muta, para que escáneres de correo no disparen el cambio); POST
// ejecuta el movimiento. throttle:oauth comparte el límite del flujo de login.
Route::middleware('throttle:oauth')->group(function () {
    Route::get('auth/email-change/confirm', [AccountEmailChangeController::class, 'show'])
        ->name('auth.email-change.confirm');

    Route::post('auth/email-change/confirm', [AccountEmailChangeController::class, 'confirm'])
        ->name('auth.email-change.confirm.execute');
});

// Selectores de empresa/sede migrados al shell SPA (#220, Fase 2).
// Sirven `view('spa')`; React Router monta la ruta y obtiene su data vía
// GET /api/v1/bootstrap. El JWT viaja en la cookie HttpOnly seteada por el
// callback OAuth — el shell no necesita props server-side.
Route::get('auth/company-selector', FrontendRedirectController::class)->name('auth.company-selector');

// Selector de sede (multi-sede #117). Se llega aquí cuando el usuario tiene
// empresa activa pero N sedes y debe elegir cuál operar.
Route::get('auth/branch-selector', FrontendRedirectController::class)->name('auth.branch-selector');

Route::get('company/preferences', FrontendRedirectController::class)->name('company.preferences');

// Pantalla "Cuenta en revisión" (#154). Punto de aterrizaje cuando una
// operación de negocio devuelve 403 `code=company_not_verified`, o cuando el
// frontend detecta que la empresa activa no está en `config('companies.verified')`.
// Sin JWT redirige al home.
Route::get('company/under-review', FrontendRedirectController::class)->name('company.under-review');

// Multi-sede (#117): gestión de sedes (CRUD, asignación de usuarios, copy-menu).
Route::get('company/branches', FrontendRedirectController::class)->name('company.branches');

// Multi-bodega (#120): gestión de bodegas dentro de cada sede (CRUD).
Route::get('company/warehouses', FrontendRedirectController::class)->name('company.warehouses');

Route::get('company/settings', FrontendRedirectController::class)->name('company.settings');

Route::get('company/printers', FrontendRedirectController::class)->name('company.printers');

// #115 — Settings KDS: CRUD de estaciones y device-tokens. Gate
// kds_stations.read (sensible de sede — owner por default, admin
// asignable; owner-bypass por is_system).
Route::get('company/kds', FrontendRedirectController::class)->name('company.kds');

Route::get('company/metrics', FrontendRedirectController::class)->name('company.metrics');

Route::get('company/reports', FrontendRedirectController::class)->name('company.reports');

// #235 — Facturación electrónica DIAN: configuración (perfil fiscal,
// resoluciones, plantillas) y consulta de documentos emitidos. Ambas
// rutas existen en el SPA router pero faltaba el named route en Laravel,
// lo que forzaba al sidebar a hardcodear las URLs.
Route::get('company/dian', FrontendRedirectController::class)->name('company.dian');

Route::get('dian/documents', FrontendRedirectController::class)->name('dian.documents');

Route::get('identities/users', FrontendRedirectController::class)->name('identities.users');

Route::get('identities/roles', FrontendRedirectController::class)->name('identities.roles');

// Back-compat: enlaces viejos a /roles redirigen al canónico /identities/roles,
// preservando el query param `token` para mantener la UX de auth via cookie.
Route::get('roles', function () {
    return redirect()->route('identities.roles', request()->query());
});

// Gate RBAC alineado con la API (#174 P2-1). Antes /menu, /coupons,
// /coupons/{id} renderizaban el shell sin validar permisos: el cliente
// hacia el primer fetch a la API y recibia 401/403, dejando un shell vacio
// con toast. Ahora redirigen a /dashboard sin renderizar nada.
Route::get('menu', FrontendRedirectController::class)->name('menu');

Route::get('coupons', FrontendRedirectController::class)->name('coupons');

Route::get('coupons/{id}', FrontendRedirectController::class)->name('coupons.show');

Route::get('orders/deliveries', FrontendRedirectController::class)->name('orders.deliveries');

// Back-compat: /deliveries → 302 → /orders/deliveries (preserva ?token=).
Route::get('deliveries', function () {
    return redirect()->route('orders.deliveries', request()->query());
});

// #119: vista mobile-first para domiciliarios. Visible para cualquier rol
// con `deliveries.read` — el filtro `user_id = actor` del backend asegura
// que solo se vean entregas propias. Si no hay sede activa, redirige al
// dashboard.
Route::get('my-deliveries', FrontendRedirectController::class)->name('deliveries.mine');

Route::get('inventory', FrontendRedirectController::class)->name('inventory');

Route::get('purchases', FrontendRedirectController::class)->name('purchases');

Route::get('suppliers', FrontendRedirectController::class)->name('suppliers');

// Horarios migrado al shell SPA (#220, Fase 3). Sirve view('spa'); el
// control de acceso `hours.read` lo aplica el endpoint API GET /api/v1/hours
// (middleware permission:hours.read) — el shell se sirve siempre y el
// contenido se gatea en el backend, única autoridad de permisos.
Route::get('hours', FrontendRedirectController::class)->name('hours');

// Admin de mesas físicas (#191 Fase 8). Auth + gate company.update.
Route::get('company/tables', FrontendRedirectController::class)->name('company.tables');

// Caja para mesa con QR (#191 Fase 6). Auth + gate orders.update.
Route::get('caja/table-sessions/{id}', FrontendRedirectController::class)->name('caja.table-session');

// Kitchen Display System (#191 F5 + #115). Auth + gate kds.read.
Route::get('kds', FrontendRedirectController::class)->name('kds');

// #115 — KDS standalone por estación. Layout full-screen sin sidebar.
// Autentica con device-token (cookie HttpOnly `kds_device_token` o query
// `?device=`). El controller `KdsStandaloneController` setea la cookie en
// el primer acceso por query y redirige a URL limpia. No comparte props
// con HandleInertiaRequests porque opera en kiosk-mode.
Route::get('kds/{stationSlug}', FrontendRedirectController::class)->name('kds.station');

// Pantalla del mesero — sesiones de mesa con QR (#191 Fase 4).
Route::get('orders/table-sessions', FrontendRedirectController::class)->name('orders.table-sessions');

Route::get('orders/table-sessions/{id}', FrontendRedirectController::class)->name('orders.table-sessions.show');

Route::get('orders/tables', FrontendRedirectController::class)->name('orders.tables');

Route::get('orders/board', FrontendRedirectController::class)->name('orders.board');

Route::get('chats', FrontendRedirectController::class)->name('chats');

// CRM básico de clientes (#123 + refactor #235). Gate RBAC alineado con la
// API (clients.read); listado y perfil viven en /clients y /clients/{contact}
// donde {contact} es contacts.id (canónico). El UNIQUE parcial sobre
// doc_number cubre la unicidad real; phone puede repetirse entre familiares.
Route::get('clients', FrontendRedirectController::class)->name('clients');

Route::get('clients/{contact}', FrontendRedirectController::class)
    ->name('clients.show');

// Fidelización con puntos (#122). Reportes y panel staff. El perfil por
// cliente se ve embebido en /clients/{contact} y no necesita ruta propia.
// Gate por loyalty.read.
Route::get('loyalty/reports', FrontendRedirectController::class)->name('loyalty.reports');

Route::get('orders/cashier', FrontendRedirectController::class)->name('orders.cashier');

Route::get('caja', function () {
    return redirect()->route('orders.cashier', request()->query());
});

Route::get('deliveries/metrics', FrontendRedirectController::class)->name('deliveries.metrics');

Route::get('menu/{id}', FrontendRedirectController::class)->name('menu.show');

Route::get('enrollment/user', FrontendRedirectController::class)->name('enrollment.user');

Route::get('enrollment/company', FrontendRedirectController::class)->name('enrollment.company');

Route::get('company/whatsapp', FrontendRedirectController::class)->name('company.whatsapp');

Route::get('billing', FrontendRedirectController::class)->name('billing');

// Colaboradores y planificador de turnos (HU #182).
// El gate RBAC se evalúa con la misma firma de la API (`employees.read` /
// `shifts.read` / `workforce.reports`). Si el rol no tiene permiso, se
Route::get('employees', FrontendRedirectController::class)->name('employees.index');
Route::get('employees/new', FrontendRedirectController::class)->name('employees.create');
Route::get('employees/reports', FrontendRedirectController::class)->name('employees.reports');
Route::get('employees/{id}', FrontendRedirectController::class)->name('employees.show');

Route::get('planner', FrontendRedirectController::class)->name('planner.week');
Route::get('planner/calendar', FrontendRedirectController::class)->name('planner.month');

Route::get('me/agenda', FrontendRedirectController::class)->name('me.agenda');

Route::get('me/perfil', FrontendRedirectController::class)->name('me.perfil');

/*
 * DEV-ONLY: preview de las páginas de error (resources/views/errors/{code}.blade.php)
 * sin tener que provocar el status real. Útil para iterar el branding sin levantar
 * Ignition ni tocar `APP_DEBUG`.
 *
 * Doble gate: APP_ENV=local + APP_DEBUG=true. En QA y PDN nunca se registra porque
 * `aws/ec2/install.sh` fuerza `APP_ENV=production` y `APP_DEBUG=false` al instalar.
 *
 * Se usa `response()->view(...)` (no `abort(...)`) para que el handler de errores
 * NO intercepte la respuesta — renderiza el Blade puro igual que en producción.
 *
 * Limitación: esta ruta es una closure y no soporta `php artisan route:cache`
 * (cosa que en local no se suele hacer). Si necesitas cachear rutas, coméntalo.
 */
if (app()->environment('local') && config('app.debug') === true) {
    Route::get('__errors/{code}', function (string $code) {
        abort_unless(in_array($code, ['404', '403', '419', '500', '503'], true), 404);

        return response()->view("errors.{$code}", [], (int) $code);
    })->name('dev.errors.preview');
}

// Mesa con QR (#191) — flujo público sin auth. Migrado al shell SPA: el QR
// físico apunta a `/t/{qr_token}`, que ahora es una ruta del frontend SPA
// (`pages/table/join.tsx`). El backend solo sirve la API REST equivalente
// bajo `/api/v1/public/table/{qr_token}` — ver routes/api.php.
//
// Estas dos rutas web existen únicamente como back-compat: un QR escaneado o
// un enlace viejo que llegue al backend se reenvía al SPA conservando path y
// query (FrontendRedirectController). Las acciones puras del flujo (join,
// contact-lookup, carrito) ya no viven en web.php: son endpoints API.
Route::get('t/{qr_token}', FrontendRedirectController::class)
    ->where('qr_token', '[A-Za-z0-9]+')
    ->name('public.table.join');

Route::get('t/{qr_token}/menu', FrontendRedirectController::class)
    ->where('qr_token', '[A-Za-z0-9]+')
    ->name('public.table.menu');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
