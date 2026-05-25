# Autenticación

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

flexyflow autentica **únicamente con Google OAuth** desde HU #231. Las páginas y endpoints heredados de Laravel Breeze (email + contraseña, reset, verify) siguen existiendo como named routes para no romper código que llame a `route('login')`, pero su comportamiento es:

- `GET /login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/verify-email`, `/confirm-password` → redirigen `302` a `/auth/google?reason=email_auth_disabled` (preservan `?intended=` si venía).
- `POST /login`, `/register`, `/forgot-password`, `/reset-password`, `/email/verification-notification`, `PUT /settings/password`, `PUT /api/v1/account/password` → responden `410 Gone` con `{ code: "email_auth_disabled" }` y emiten `Log::info('auth.legacy_endpoint_hit', ...)`.
- `verify-email/{id}/{hash}` (signed) y `POST /logout` se conservan operativos.

El frontend de cada ruta legacy carga un único componente `GoogleOnlyAuthGate` (ver `application/frontend/src/components/auth/google-only-auth-gate.tsx`) que muestra mensaje contextual + CTA Google + auto-redirect 4s (respeta `prefers-reduced-motion`).

El frontend SPA opera con un **JWT custom** que viaja en `localStorage` y como `Authorization: Bearer` o `?token=` en cada request a `/api/v1/*`. La sesión Laravel (cookie) sobrevive en paralelo y la usa Inertia para los props compartidos.

---

## Flujo OAuth Google (paso a paso)

```
1. Frontend                   → GET /auth/google
2. GoogleAuthController       → redirect a accounts.google.com
3. Usuario autentica en Google
4. Google                     → GET /auth/google/callback?code=...
5. GoogleAuthController       → consume code → obtiene email + sub
6. Si usuario nuevo           → crea User en estado pending_enrollment
7. Si ya existe               → JwtService::issue(...)
8. Backend redirige           → /enrollment/user (si pending)
                              → /company-selector (si tiene N empresas)
                              → /dashboard?token=... (si tiene 1 empresa activa)
9. app.tsx                    → setToken(token) → localStorage
10. apiFetch en cada llamada  → Authorization: Bearer + ?token=
```

**Rate limit:** `throttle:oauth` = 10 intentos/minuto por IP.

### Endpoints

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/auth/google` | — | Redirige al consent screen |
| `GET` | `/auth/google/callback` | — | Recibe el code, emite JWT |
| `POST` | `/api/v1/auth/select-company` | `jwt` | Selección final de empresa activa |
| `POST` | `/api/v1/auth/switch-company` | `jwt` | Cambio de empresa en sesión activa |
| `POST` | `/api/v1/auth/logout` | `jwt` | Invalida JWT activo |

---

## Estructura del JWT

El JWT está compuesto por header (HS256), body (AES-256-CBC encriptado) y signature (HMAC). El payload **encriptado** garantiza que el cliente no pueda leer roles, permisos ni metadatos sensibles.

### Payload

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `sub` | `string` | ID del usuario Laravel |
| `email` | `string` | Email del usuario |
| `enrollment_step` | `string` | `user` \| `company` \| `complete` |
| `active_company_nit` | `?string` | NIT de empresa activa (null si no hay) |
| `active_company_name` | `?string` | Nombre comercial de la empresa activa |
| `active_company_logo_url` | `?string` | URL absoluta del logo (si hay) |
| `active_company_plan` | `?string` | Plan: `free` \| `pro` \| `enterprise` |
| `role` | `?{id,name,is_system}` | Rol en empresa activa |
| `permissions` | `string[]` | Slugs de features con `can_read=true` |
| `companies` | `array` | Lista de empresas accesibles `{nit, name, status, linked, logo_url, plan}` |
| `iat`, `exp` | `int` | Timestamps Unix |
| `issued_at`, `expires_at` | `string` | Mismas fechas en ISO 8601 |

### TTL y refresh

- **TTL por defecto:** 3600 s (configurable vía `JWT_TTL`).
- **Auto-refresh:** si `exp - now() < 300s`, `ValidateJwt` reemite el token y lo devuelve en el header `X-Refresh-Token`. `apiFetch` del frontend lo captura y persiste vía `setToken()`.
- **Cuando se reemite explícitamente:** `select-company`, `switch-company`, actualización de `commercial_name` en `/api/v1/company`, asignación de rol a usuario, ajuste de membresía.

### Blacklist y registro activo

- `UserActiveToken` registra el último signature por usuario; permite invalidar la sesión actual cuando el admin desactiva la membresía.
- Blacklist opcional vía cache (`Cache::put('jwt_blacklist:{signature}', ...)`) habilitada con `JWT_BLACKLIST_ENABLED=true`.

---

## Middleware Stack

| Alias | Clase | Responsabilidad |
|-------|-------|-----------------|
| `jwt` | `App\Http\Middleware\ValidateJwt` | Verifica firma, expiración, blacklist; auto-refresca si <300 s |
| `bot.jwt` | `App\Http\Middleware\ValidateBotJwt` | JWT separado para el bot (otra clave/TTL) |
| `company.access` | `App\Http\Middleware\EnsureCompanyAccess` | Verifica membresía activa en `active_company_nit`; inyecta `active_company_nit`, `user_role`, `company_role_id`, `company_role_is_system` en el request |
| `permission:{feature},{action}` | `App\Http\Middleware\EnsureFeaturePermission` | RBAC. Bypass si el rol activo es `is_system=true` |
| — | `App\Http\Middleware\SecurityHeaders` | CSP, X-Frame-Options, X-Content-Type-Options |
| — | `App\Http\Middleware\HandleInertiaRequests` | Comparte `auth.user`, `companies`, `activeCompany`, `role`, `permissions` con el SPA |

---

## Cookies y CSRF

- Las rutas `/api/v1/*` aceptan tanto el JWT (Bearer header) como la sesión Laravel (cookie). El JWT es la fuente de verdad para autorización por empresa.
- CSRF de Laravel **no se exige** en `/api/v1/*` porque la SPA usa Bearer + `credentials: include`.
- El token CSRF de Inertia se inyecta automáticamente en cualquier request POST/PUT/PATCH/DELETE hecho con `useForm` o `router`.

---

## Ejemplo de request autenticado

```http
GET /api/v1/menus HTTP/1.1
Host: app.flexyflow.com
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Accept: application/json
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Refresh-Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...   # solo si <300s al exp

{
  "menus": [
    { "id": 12, "name": "Carta del día", "status": "active", "active_days": [1,2,3,4,5] }
  ]
}
```

---

## Notas de seguridad

- **Nunca incluir el JWT en URLs compartidas.** El query param `?token=` solo se usa para hidratar el SPA en navegación interna.
- El payload está encriptado AES-256-CBC; cambiar `JWT_PAYLOAD_ENCRYPTION_KEY` invalida todos los tokens existentes.
- Cambiar `JWT_SECRET` también los invalida (cambia la firma esperada).
- La blacklist solo opera con `JWT_BLACKLIST_ENABLED=true` (default `false` por costo de cache).
- El JWT del bot (`bot.jwt`) usa una clave distinta (`BOT_JWT_SECRET`) y un TTL más largo; no rota igual que el JWT de usuario.
