# MIDDLEWARE_MAP — Fuente única de verdad

> **Antes de añadir un middleware al stack o cambiar un alias, lee este archivo.**
> **Después de modificar, actualiza este archivo + `bootstrap/app.php` en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `application/bootstrap/app.php` — registración y alias (Laravel 12)
- [ ] `application/app/Http/Middleware/*` — implementaciones
- [ ] `application/routes/web.php`, `application/routes/api.php` — uso de aliases
- [ ] `application/constants/BRANCH_RBAC.md` — scopes activos
- [ ] `application/constants/PERMISSIONS_CATALOG.md` — uso de `permission:<slug>,<action>`

---

## Stack de middleware por contexto

### Rutas `web/*` (Inertia + Blade)

Orden de aplicación (`bootstrap/app.php:50-69`):

```
[prepend]  NormalizeStrings           ← NFC + control chars strip
           …routing…
[append]   SecurityHeaders            ← CSP, X-Frame-Options, etc.
           HandleInertiaRequests      ← shared props (auth, orders config, etc.)
           EnsureCompanyNotBlocked    ← #193 bloqueo comercial por mora
           AddLinkHeadersForPreloadedAssets
```

### Rutas `api/*`

```
[prepend]  ForceJsonResponse          ← evita 302 + HTML cuando falta header Accept
           NormalizeStrings
           …routing…
```

Las rutas API NO usan `EncryptCookies` (la cookie JWT viene exenta — `JwtService::COOKIE_NAME` en `encryptCookies(except: [...])`).

---

## Aliases canónicos (orden lógico recomendado en rutas)

```
jwt              → ValidateJwt              (autenticación JWT, inyecta jwt_payload)
company.access   → EnsureCompanyAccess      (inyecta active_company_nit, company_role_id, company_role_is_system)
company.verified → EnsureCompanyVerified    (valida onboarding completo)
company.not_blocked → EnsureCompanyNotBlocked  (bloqueo comercial #193)
branch.access    → EnsureBranchAccess       (inyecta active_branch_id, valida acceso)
branch.consolidate → AllowConsolidatedBranches  (permite ?branch=all si tiene permiso)
bot.jwt          → ValidateBotJwt           (auth para callbacks del bot)
permission       → EnsureFeaturePermission  (parametrizado: permission:<slug>,<action>)
cache.get        → CacheControlGet          (CDN-friendly headers)
table.guest      → ResolveTableGuest        (sesión de invitado en flujo de mesa QR)
```

Fuente: `bootstrap/app.php:86-97`.

### Orden típico en una ruta API protegida

```php
Route::middleware([
    'jwt',                        // 1. Autenticar
    'company.access',             // 2. Inyectar empresa
    'company.not_blocked',        // 3. (si aplica) no en mora
    'branch.access',              // 4. Inyectar sede (si operativo)
    'permission:orders.read,read' // 5. Validar permiso específico
])->get('/orders', ...);
```

---

## Detalle por middleware

### `ValidateJwt` (`jwt`)

- Lee la cookie `flexyflow_jwt` (HttpOnly + Secure + SameSite=Lax) cifrada AES-256 + HMAC por `JwtService`.
- Inyecta `jwt_payload` en `$request->attributes`.
- Si falta o es inválido → 401.

### `EnsureCompanyAccess` (`company.access`)

- Lee `jwt_payload['active_company_nit']` o `jwt_payload['companies'][0]` (membresía única).
- Valida que el usuario es miembro vigente vía `company_users`.
- Inyecta `active_company_nit`, `company_role_id`, `company_role_is_system`.
- Si no es miembro → 403.

### `EnsureCompanyVerified` (`company.verified`)

- Valida que el onboarding está completo (datos legales mínimos, plan activo).
- Si no → redirect a flujo de onboarding.

### `EnsureCompanyNotBlocked` (`company.not_blocked`) (#193)

- Si la empresa está en estado bloqueado por mora, redirige a `/billing/blocked` (web) o 403 (api), salvo rutas de allow-list (`dashboard`, auth, billing).

### `EnsureBranchAccess` (`branch.access`)

- Lee `?branch=<id>` o cookie `flexyflow_branch`.
- Valida que el usuario tiene acceso vía `branch_users` (o `is_system=true` → bypass).
- Inyecta `active_branch_id`.
- Si no tiene acceso → 403.

### `AllowConsolidatedBranches` (`branch.consolidate`)

- Permite el query param `?branch=all` si:
  - El rol tiene `metrics.view_all_branches` asignado, o
  - El usuario tiene cobertura total runtime (`FeaturePermissionService::userCanViewConsolidated`).
- Usado en reportes y dashboards.

### `EnsureFeaturePermission` (`permission:<slug>,<action>`)

- Lee `company_role_id` y `company_role_is_system` (inyectados por `EnsureCompanyAccess`).
- Bypass si `is_system = true`.
- Consulta `CompanyRolePermission` filtrando por `feature.slug = <slug>` y `can_<action> = true`.
- Acciones válidas: `read | create | update | delete` (`EnsureFeaturePermission::VALID_ACTIONS`).
- 403 si no tiene; 500 si la acción no es válida.

Implementación: `application/app/Http/Middleware/EnsureFeaturePermission.php`.

### `ValidateBotJwt` (`bot.jwt`)

- JWT corto-vivido emitido para callbacks del bot WhatsApp.
- Inyecta `bot_payload`.

### `NormalizeStrings`

- NFC normalization + strip de control chars en todos los strings del payload.
- **NO se aplica a webhooks** (firma byte-exact requerida): allow-list en `application/app/Http/Middleware/NormalizeStrings.php`.

### `ForceJsonResponse`

- Inyecta `Accept: application/json` en rutas API si el cliente no lo manda — evita 302 + HTML cuando el validador falla.

### `SecurityHeaders`

- CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, etc.
- Configurable vía `config/security.php`.

### `HandleInertiaRequests`

- Comparte props globales: `auth.user`, `auth.user.permissions`, `auth.company`, `auth.active_branch`, `shared.orders` (config completo).

### `ResolveTableGuest` (`table.guest`)

- Resuelve sesión efímera del invitado escaneando QR (mesa).
- Sin JWT — inyecta `table_session_id` en attributes.

### `CacheControlGet` (`cache.get`)

- Aplica `Cache-Control` permisivo para GET con assets estáticos.

---

## Anti-patrones

- ❌ Aplicar `permission:<slug>` sin `company.access` antes (no hay `company_role_id`).
- ❌ Aplicar `branch.access` sin `company.access` antes (no hay `active_company_nit`).
- ❌ Mover `NormalizeStrings` después de un middleware que ya tocó el payload (debe ser prepend).
- ❌ Quitar `ForceJsonResponse` de la API (el manejo de errores asume JSON).

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202)_

---

## Referencias cruzadas

- `application/bootstrap/app.php:28-156` — registración Laravel 12.
- `application/constants/PERMISSIONS_CATALOG.md` — uso de `permission:<slug>,<action>`.
- `application/constants/BRANCH_RBAC.md` — orden empresa→sede.
- `docs/wiki/Errores-API.md` — manejo de errores cross-cutting.
- `docs/wiki/SECURITY_INPUT_HANDLING.md` — `NormalizeStrings`, sanitización.
