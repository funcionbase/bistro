# FUNCIONALIDADES_APP.md — Manual funcional de la aplicación

> Documento canónico, técnico y exhaustivo del sistema. Describe **qué hace**, **cómo lo hace**, **qué endpoints toca**, **qué valida**, **qué responde** y **qué pasa cuando algo falla**, con paths y line numbers cuando aplica.
> Cubre cada feature desde la UI hasta la BD: gates web, FormRequest, controlador, servicio, modelo, scope multi-tenant, audit log, cache, jobs y vista (Inertia o blade).
> Sincronizado con `FRONTEND_FILES.md` + `BACKEND_FILES.md`. Cualquier cambio relevante debe actualizar los tres.

---

## 0. Resumen técnico del sistema

**flexyflow Restaurante** es una plataforma SaaS multi-empresa multi-sede multi-bodega para la gestión operativa de restaurantes en Colombia. Stack monolito server-rendered con Inertia.js v2 (React en el cliente, Laravel en el servidor; sin REST público para la SPA — los endpoints `/api/v1/*` son contratos para datos asíncronos y para integraciones externas como bots de WhatsApp).

**Módulos cubiertos en producción** (al 2026-05-11):

- **Operación**: pedidos kanban, POS con caja por turno y desglose tributario, mesas, domicilios con repartidores, menú con disponibilidad y programación, horarios con excepciones, cupones (incluye happy hour programado #125), impresión térmica ESC/POS (#116).
- **Multi-tenancy**: una empresa (NIT) → N sedes (#117) → N bodegas por sede (#120). Aislamiento garantizado por `BranchScope` global y `EnsureBranchAccess` middleware.
- **Inventario y compras**: insumos con costo promedio ponderado (#111), recetas BOM con consumo automático al cerrar orden (#112), proveedores con alias × precio histórico, órdenes de compra con flujo draft→received→paid + anulación con nota crédito (#118).
- **Inteligencia operativa**: food cost en tiempo real (#113), matriz de menu engineering popularidad × margen (#114), alertas accionables de margen/costos/stock/volumen (#124).
- **Relación con el cliente**: chats WhatsApp Cloud API con bot integrable, CRM básico cross-sede con segmentación heurística (#123), programa de fidelización con puntos y tiers cross-sede (#122), carrito público con JWT anónimo.
- **Administración**: RBAC con 60 features y overrides por usuario, multi-empresa con switch dinámico, configuración por empresa, facturación recurrente con mora, auditoría exhaustiva.
- **PWA y modo offline**: manifest dinámico por empresa con iconos rasterizados (#103), modo offline real con IndexedDB y sincronización idempotente (#140).

### 0.1 Stack y versiones

| Componente | Versión exacta | Origen |
|---|---|---|
| PHP | 8.2 | `composer.json` `require.php` |
| Laravel | 12.x | `composer.json` `laravel/framework` |
| `inertiajs/inertia-laravel` | v2 | `composer.json` |
| `tightenco/ziggy` | v2 | usado para `route('name')` en JS |
| `laravel/socialite` | v5 | OAuth Google únicamente habilitado |
| `barryvdh/laravel-dompdf` | configurable | motor PDF, `config/dompdf.php` |
| React | 19 | `resources/js/package.json` |
| `@inertiajs/react` | v2 | mismo |
| TypeScript | 5.x | strict mode |
| Tailwind CSS | v4 | utility-first |
| Vite | 5.x | bundler, entry `resources/js/app.tsx` |
| `@dnd-kit/core` + `@dnd-kit/utilities` | — | drag-drop kanban + menú |
| `lucide-react` | — | iconos |
| Radix UI primitives | varios | Dialog, Select, Tooltip, etc. |
| ESLint v9 + Prettier v3 | — | flat config |
| PostgreSQL | 14+ | conexión `pgsql` en `config/database.php` |
| Pint | v1 | `vendor/bin/pint --dirty --format agent` (obligatorio antes de commit) |

### 0.2 Arquitectura del request

```
Cliente HTTP
   │
   ▼
[Nginx]  ── sirve /public/build/* (assets), reenvía resto a php-fpm
   │
   ▼
[Laravel router] (bootstrap/app.php registra middleware globales)
   │
   ├── routes/web.php ────────────► Inertia::render('page', $props)
   │     middleware globales:        ─► resources/js/pages/{page}.tsx
   │     - SecurityHeaders             se hidrata client-side
   │     - HandleInertiaRequests
   │
   └── routes/api.php ────────────► Controller@method → JsonResponse
         middleware del grupo:       (consumido por hooks/lib/api.ts)
         - jwt (ValidateJwt)
         - company.access (EnsureCompanyAccess)
         - permission:slug,verb (EnsureFeaturePermission)
```

| Aspecto | Detalle |
|---|---|
| Arquitectura | Laravel 12 streamlined (sin `app/Console/Kernel.php`, sin `app/Http/Kernel.php`; todo en `bootstrap/app.php`) |
| Routing servidor | `routes/web.php` (rutas Inertia + auth Breeze) y `routes/api.php` (REST API) |
| Routing cliente | Inertia visit/`<Link>` para navegación SPA; `router.reload({ only: [...] })` para refresh parcial |
| Autenticación | Google OAuth → JWT custom (HS256 + payload AES-256) → cookie HttpOnly `flexyflow_jwt` |
| Autorización | RBAC feature-based: tabla `features` × `company_roles` × `company_role_permissions` (CRUD) + overrides por `CompanyUser.custom_permissions` |
| Multi-empresa | Un usuario en N empresas con roles distintos; el JWT lleva `active_company_nit` y la lista completa de membresías |
| Moneda | COP (configurable `BILLING_CURRENCY`); `Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })` |
| Idioma UI | Español Colombia (`es-CO`) hardcoded; sin i18n |
| Mensajería externa | WhatsApp Cloud API oficial (Meta) — opcional por empresa, MVP funcional para flow inbound |
| Storage | Disco `public` para logos/QR (URL pública), `private` para PDFs de facturas (URL firmada) |
| Cola | `database` por defecto (tabla `jobs`); sólo `Jobs/{GenerateReportPdf,DownloadWhatsappMediaJob,MarkWhatsappMessageReadJob}` la usan |
| Cache | `database` (tabla `cache`) por defecto; Redis opcional (no requerido) |
| Comandos schedule | Registrados en `routes/console.php`; ejecutar con `php artisan schedule:work` o systemd timer |

### 0.3 Convenciones de URL y breadcrumb

- **URLs siempre en inglés** y en kebab-case (`/orders/cashier`, `/identities/users`).
- **Breadcrumbs en español** y reflejan jerarquía de la URL (`Dashboard › Órdenes › Caja`).
- Aliases de back-compat con redirect 302 que **preserva `?token=`**: `/caja → /orders/cashier`, `/deliveries → /orders/deliveries`, `/roles → /identities/roles`.
- Páginas Inertia viven en `resources/js/pages/` y son resueltas por la convención del primer arg de `Inertia::render()` (ej. `Inertia::render('orders/board', [...])` → `pages/orders/board.tsx`).

### 0.4 Roles del sistema (`is_system=true`)

Definidos como plantillas en la tabla `permission_templates` (seedeada por `PermissionTemplateSeeder`). Cuando una empresa hace enrollment, `CompanyEnrollmentController` crea automáticamente los 3 roles del sistema en `company_roles` con todas las filas correspondientes en `company_role_permissions` copiadas desde el template.

| Rol canónico | Default name (config/roles.php) | Override env | Color seedeado | Permisos por defecto |
|---|---|---|---|---|
| `owner` | `Propietario` | `ROLE_OWNER_NAME` | `#C0FD79` (lima) | full CRUD en todas las features (incluye `whatsapp.swap_phone` y `whatsapp.disconnect`) |
| `admin` | `Administrador` | `ROLE_ADMIN_NAME` | `#8B5CF6` (violeta) | full CRUD en todas las features **excepto** las `is_owner_only` (`whatsapp.swap_phone`, `whatsapp.disconnect`) |
| `employee` | `Empleado` | `ROLE_EMPLOYEE_NAME` | `#F59E0B` (ámbar) | sólo `orders.read`, `chats.read` (configurable por empresa) |

**Bypass crítico:** Si `CompanyRole.is_system=true`, `FeaturePermissionService::hasPermission()` devuelve `true` inmediatamente (sin consultar la matriz). Ver `app/Services/FeaturePermissionService.php`. Esto significa que cualquier rol con `is_system=true` **omite** la validación granular y tiene acceso ilimitado al feature pedido.

Cada empresa puede crear adicionalmente **roles personalizados** (`is_system=false`) con cualquier combinación de permisos. El seeder de SuperPasas crea dos:
- `Domiciliario` (`#0EA5E9`, azul cielo): `orders.read/update`, `deliveries.read/create/update`, `hours.read`, `menu.read`, `chats.read`.
- `Cocina` (`#EF4444`, rojo): `orders.read/update`, `menu.read`, `hours.read`.

### 0.5 Matriz CRUD por feature × rol (default)

`✓` = puede; `✗` = no puede; `🔒` = `is_owner_only` (no degradable, exige OTP). Roles del sistema bypassean la matriz en runtime, pero los templates definen lo que la UI muestra y lo que se replicaría en una empresa nueva.

| Feature | Owner C/R/U/D | Admin C/R/U/D | Employee C/R/U/D | Notas |
|---|---|---|---|---|
| `orders` | ✓✓✓✓ | ✓✓✓✓ | ✗✓✗✗ | Empleado configurable; default `orders.read` |
| `menu` | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | — |
| `coupons` | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | Happy hour (#125) via `valid_days`+`valid_hours_*`+`auto_apply` |
| `deliveries` | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | Reasignar requiere `update` |
| `hours` | —/✓/✓/— | —/✓/✓/— | —/✓/—/— | No hay create/delete; sólo update del horario semanal y CRUD de excepciones |
| `users` | —/✓/✓/✓ | —/✓/✓/✓ | ✗✗✗✗ | Crear miembros = enviar invitación (`POST /api/v1/invitations`) |
| `roles` | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | No se puede modificar/eliminar roles `is_system=true` (bloqueado en `RoleController` y `CompanyRolePolicy`) |
| `chats.read` | —/✓/—/— | —/✓/—/— | —/✓/—/— | Configurable Employee |
| `chats.update` | —/—/✓/— | —/—/✓/— | ✗✗✗✗ | Permite responder; configurable Employee |
| `clients.read` (#123) | —/✓/—/— | —/✓/—/— | ✗✗✗✗ | CRM cross-sede |
| `clients.update` (#123) | —/—/✓/— | —/—/✓/— | ✗✗✗✗ | Notas + tags |
| `clients.delete` (#123) | —/—/—/✓ | —/—/—/✓ | ✗✗✗✗ | Eliminar notas/tags |
| `loyalty.read` (#122) | —/✓/—/— | —/✓/—/— | —/✓/—/— | En `DEFAULT_EMPLOYEE_PERMISSIONS` |
| `loyalty.update` (#122) | —/—/✓/— | —/—/✓/— | ✗✗✗✗ | Ajustar puntos + canjear a nombre del cliente |
| `metrics.view_all_branches` (#117) | —/✓/—/— | ✗ | ✗ | Owner only por default; permite consolidar reportes cross-sede |
| `reports` (alias `reports.read`) | —/✓/—/— | —/✓/—/— | ✗✗✗✗ | Cubre `/dashboard`, `/company/metrics`, `/company/reports` y alertas (#124) |
| `branches.manage` (#117) | ✓✓✓✓ | ✗ | ✗ | Crear/editar/archivar sedes |
| `branches.assign_users` (#117) | —/✓/✓/— | —/✓/✓/— | ✗✗✗✗ | Pivot `branch_users` |
| `branches.copy_menu` (#117) | —/—/✓/— | —/—/✓/— | ✗✗✗✗ | Duplica menú activo a otra sede como draft |
| `branches.view_all` (#117) | —/✓/—/— | —/✓/—/— | ✗✗✗✗ | Necesario para `BranchSwitcher` |
| `warehouses.manage` (#120) | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | Bodegas por sede; archivar bloqueado si stock > 0 |
| `inventory.{read/create/update/delete}` (#111) | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | Insumos + movimientos (entries, mermas, ajustes, transferencias) |
| `suppliers.{read/create/update/delete}` (#118) | ✓✓✓✓ | ✓✓✓✓ | ✗✗✗✗ | Catálogo de proveedores con alias × precio histórico |
| `purchases.{read/create/update/receive/pay/delete}` (#118) | ✓✓✓✓✓✓ | ✓✓✓✓✓✓ | ✗✗✗✗✗✗ | OC con flujo draft→submitted→received→paid. `delete` = anular post-recepción con nota crédito + reverso inventario |
| `company` | —/✓/✓/— | —/✓/✓/— | ✗✗✗✗ | Update incluye logo/QR (5 MB), banco, BREB, settings, impresoras (#116) |
| `billing.read` | —/✓/—/— | —/✓/—/— | ✗✗✗✗ | — |
| `whatsapp.read` | —/✓/—/— | —/✓/—/— | —/configurable/—/— | — |
| `whatsapp.connect` | ✓—— | ✓—— | ✗✗✗✗ | Crear conexión; exige OTP |
| `whatsapp.update` | —/—/✓/— | —/—/✓/— | ✗✗✗✗ | Editar metadata cuenta |
| `whatsapp.swap_phone` 🔒 | —/—/—/✓ | ✗ | ✗ | **Owner only**, exige OTP + `WhatsappAccountPolicy::swapPhone` |
| `whatsapp.disconnect` 🔒 | —/—/—/✓ | ✗ | ✗ | **Owner only**, exige OTP + `WhatsappAccountPolicy::disconnect` |

Leyenda C/R/U/D = `can_create / can_read / can_update / can_delete`. Cuando una columna no aplica (ej. `hours` no tiene create), se marca `—`. Para `purchases` hay 6 acciones porque incluye `receive` y `pay` además de CRUD.

### 0.6 Overrides por usuario

`CompanyUser.custom_permissions` es JSONB. Si el usuario tiene overrides, sobrescriben los permisos del rol base por la siguiente regla en `FeaturePermissionService::hasPermission()`:

1. Si el rol es `is_system=true` → bypass total (return `true`).
2. Si hay override para el `feature.id` y la acción solicitada → usa el override.
3. Si no hay override → consulta `company_role_permissions` para ese `(company_role_id, feature_id)`.

**Restricción crítica al editar overrides** (`UserRoleController::updatePermissions`):

> El actor sólo puede otorgar permisos que él mismo posee. Si el actor es admin y no tiene `whatsapp.swap_phone`, no puede otorgárselo a ningún empleado.

Validado calculando el "scope" del actor antes de aplicar el patch al usuario afectado. Si el patch incluye un permiso fuera del scope → 403 con mensaje `"No puedes otorgar permisos que no posees."`.

Adicionalmente, el actor **no puede modificarse a sí mismo** (rol, permisos, estado, ni desvincularse de la empresa) — protección contra escalada accidental.

### 0.7 Idempotencia y operaciones seguras

- **Webhook WhatsApp** (`POST /api/v1/webhooks/whatsapp`): idempotente por `meta_message_id`. Si llega el mismo `wamid` dos veces, el segundo se ignora.
- **JWT reissue**: si quedan <300s de TTL, el middleware emite uno nuevo. La cookie se rota silenciosamente; el JS no se entera.
- **Comandos billing**: `billing:generate-monthly-invoices` chequea `Invoice::where(subscription_id, period_from, period_to, status != voided)->exists()` antes de crear — correr el cron varias veces el mismo día no duplica facturas.
- **Seeders de QA**: todos hacen `updateOrCreate` o limpian con `clearOperationalData()` antes de re-insertar — se pueden correr cuantas veces se quiera.

---

## 1. Autenticación y acceso

### 1.0 Acceso dual: Google OAuth + correo/contraseña

Una cuenta = un correo: `google_id` y `password` son dos credenciales de la misma fila `users`. El registro por correo (`POST /api/v1/auth/register`) crea la cuenta `pending_enrollment` **sin verificar**, envía un enlace firmado de verificación (60 min, SES) y **bloquea el registro de empresa** hasta verificar (`CompanyEnrollmentRequest::authorize()` → 403 `email_not_verified`). Tras tocar el enlace, el flujo continúa solo hacia el enrollment (misma pestaña vía poll de `/verify-email`, u otra pestaña → `/login?verified=1`); si el usuario sale y vuelve a entrar, `PostLoginService` lo retoma donde quedó. Una cuenta Google fija contraseña vía "olvidé mi contraseña" o Ajustes › Contraseña; una cuenta de correo que entra con Google queda vinculada por email verificado (callback). Anti-abuso nativo: lockout 5 intentos/60s por email+IP + techo 20/min por IP (login), 5/15min por IP (registro y forgot), honeypot en registro, `Password::min(8)->uncompromised()` (HIBP), reenvío de verificación 3/10min. Login y forgot responden genérico (anti-enumeración).

### 1.1 Inicio de sesión por Google OAuth

Implementación con `laravel/socialite` v5, complementaria al acceso por correo/contraseña (§1.0). El callback persiste `email_verified_at` (Google ya verificó; backfill para cuentas legacy).

#### Flujo step-by-step

1. **Click "Continuar con Google"** en `/` o `/login` (componente `resources/js/components/google-auth-button.tsx`).
2. **Browser → `GET /auth/google`** (`GoogleAuthController::redirect`, `routes/web.php` con middleware `throttle:oauth`).
   - `throttle:oauth` = 10 intentos/min por IP (definido en `routes/api.php` y `routes/web.php` via `RateLimiter::for('oauth', ...)`).
   - Construye URL OAuth de Google con `client_id`, `redirect_uri`, `scope=openid+profile+email`, `state` (CSRF) y `prompt=select_account` (fuerza el selector de cuentas de Google; sin él, con una sola sesión activa Google hace auto-login sin dejar elegir cuenta).
   - Retorna 302 a `https://accounts.google.com/o/oauth2/v2/auth?...`.
3. **Usuario autoriza en Google** y Google redirige a `GET /auth/google/callback?code=...&state=...`.
4. **`GoogleAuthController::callback`** ejecuta:
   ```php
   $googleUser = Socialite::driver('google')->user();
   $user = User::firstOrCreate(['email' => $googleUser->email], [
       'name' => $googleUser->name,
       'first_name' => $googleUser->user['given_name'] ?? null,
       'last_name' => $googleUser->user['family_name'] ?? null,
       'google_id' => $googleUser->id,
       'status' => 'pending_enrollment',  // si es nuevo
       'email_verified_at' => now(),       // ya verificado por Google
       'password' => null,                 // sin password
   ]);
   ```
5. **Decisión de redirect** según membresías y enrollment:
   ```
   user.status == 'pending_enrollment'  → /enrollment/user
   user.companies.count() == 0          → /enrollment/company (crear primera) o /enrollment/invited (si tiene invitación pendiente)
   user.companies.count() == 1          → JwtService::issue($user, $companyNit) → cookie HttpOnly → 302 /dashboard
   user.companies.count() > 1           → JwtService::issue($user, null) (sin active_company_nit) → 302 /auth/company-selector
   ```
6. **Audit log:** `auth.login` con metadata `{provider: 'google', companies_count: N}`.

#### Configuración requerida (`.env`)

```
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://bistro.flexyflow.co/auth/google/callback
```

`config/services.php` mapea estos a `services.google.*`.

#### Errores posibles

| Causa | Respuesta | Audit |
|---|---|---|
| Usuario cancela en Google | redirect a `/` con flash `error: 'No completaste el inicio de sesión'` | `auth.login_cancelled` |
| `state` mismatch (CSRF) | 419 | `auth.login_state_mismatch` |
| `code` expirado | redirect a `/` con flash error | — |
| `GOOGLE_CLIENT_*` no configurado | 500 (excepción Socialite) | — |
| Rate limit excedido | 429 con header `Retry-After` | — |

### 1.2 Registro (creación de cuenta)

No hay formulario de registro. La creación de cuenta es side-effect del login con Google:

- Si `User::firstOrCreate` no encuentra el email, crea el registro con `status=pending_enrollment` y `password=null`.
- El siguiente request va a `/enrollment/user` (sección 2.1).
- El campo `cedula` se completa en el wizard, no en el registro.
- Audit log: `user.created` con `{email, domain: 'gmail.com', google_id}`.

**Endpoints `POST /register` y `POST /login` están deshabilitados** — devuelven 302 a `/`. Esto es intencional: el flujo de password-less con Google reduce vectores de ataque (sin brute-force, sin phishing de password) y simplifica el onboarding.

### 1.3 Verificación de email

Para usuarios creados antes de Google OAuth (legacy con password). Los usuarios de Google ya tienen `email_verified_at = now()` desde el callback porque Google ya verificó el email.

#### Endpoints

| Método | URL | Controlador | Middleware |
|---|---|---|---|
| GET | `/verify-email` | `EmailVerificationPromptController@__invoke` | `auth` |
| GET | `/verify-email/{id}/{hash}` | `VerifyEmailController@__invoke` | `auth, signed, throttle:6,1` |
| POST | `/email/verification-notification` | `EmailVerificationNotificationController@store` | `auth, throttle:6,1` |

- El enlace `verify-email/{id}/{hash}` es URL firmada (Laravel `signed` middleware): el `hash` es `sha1($user->getEmailForVerification())` y la firma incluye `expires` (default: TTL de `auth.verification.expire` config).
- `throttle:6,1` = 6 intentos/min para reenvío y para abrir el enlace (anti-replay).

#### Comportamiento

- Si `email_verified_at == null`, todos los gates web que requieren `auth` redirigen a `/verify-email` (Laravel `verified` middleware aplicado en rutas Inertia).
- Botón "Reenviar verificación" llama `POST /email/verification-notification` → envía mail con link firmado válido por 60 min.

### 1.4 Recuperación de contraseña (legacy)

Sólo aplica a usuarios con `password` no nulo (registrados antes de Google OAuth). Los usuarios Google no tienen password.

| Método | URL | Controlador |
|---|---|---|
| GET | `/forgot-password` | `PasswordResetLinkController@create` |
| POST | `/forgot-password` | `PasswordResetLinkController@store` (`throttle:6,1`) |
| GET | `/reset-password/{token}` | `NewPasswordController@create` |
| POST | `/reset-password` | `NewPasswordController@store` |

- `POST /forgot-password` valida `email: required|email|exists:users,email` y crea un token en la tabla `password_reset_tokens` con TTL `auth.passwords.users.expire` (default 60 min).
- Email enviado vía `Illuminate\Auth\Notifications\ResetPassword` con link a `/reset-password/{token}?email=...`.
- `POST /reset-password` valida `token, email, password (confirmed, min:8)` y reescribe `users.password = Hash::make($password)`.

### 1.5 Confirmación de password

Antes de acciones sensibles (cambiar email, eliminar cuenta) Laravel pide reconfirmar.

| Método | URL | Controlador |
|---|---|---|
| GET | `/confirm-password` | `ConfirmablePasswordController@show` |
| POST | `/confirm-password` | `ConfirmablePasswordController@store` |

- Marca `password_confirmed_at` en sesión por `auth.password_timeout` segundos (default 10 800 s = 3 horas).
- Las rutas que requieren confirmación usan middleware `password.confirm`.
- **No aplica a Google OAuth users** porque no tienen password — esas rutas se gating sólo por `auth`.

### 1.6 Multi-empresa: selector y switch

#### Selector inicial (post-login)

- Ruta web: `GET /auth/company-selector` (`routes/web.php`).
- Cuando: el JWT post-OAuth no tiene `active_company_nit` y `companies.count() > 1`.
- Página: `resources/js/pages/auth/company-selector.tsx`. Renderiza tarjetas con NIT, nombre comercial y rol del usuario en cada empresa.
- Al elegir empresa:
  ```
  POST /api/v1/auth/select-company
  { "nit": "1" }
  ```
  → `AuthController::selectCompany()`:
  1. Valida que `nit` está en la lista de membresías del JWT.
  2. Llama `JwtService::issue($user, $nit)` con `active_company_nit` poblado.
  3. Setea cookie HttpOnly nueva y devuelve `{ token: '__authenticated__' }` (marker opaco).
  4. Audit log: `auth.company.selected` con `{from_nit: null, to_nit: $nit}`.
- Frontend recibe el response → `setToken('__authenticated__')` → `router.visit('/dashboard')`.

#### Switch en sesión activa

- Llamado desde el selector de empresa en el sidebar (componente `restaurant-identity.tsx`).
- Endpoint:
  ```
  POST /api/v1/auth/switch-company
  { "nit": "OTRO_NIT" }
  ```
- `AuthController::switchCompany()`:
  1. Valida membresía con la empresa target.
  2. Llama `JwtService::invalidateUserActiveSession($user)` que pone el `jti` actual en blacklist con TTL = `exp - now` segundos.
  3. Emite nuevo JWT con `active_company_nit = $nit`.
  4. Audit log: `auth.company.switched` con `{from_nit, to_nit}`.
  5. Frontend hace `router.visit('/dashboard')` y toda la app se re-renderiza con el contexto nuevo.

#### Estructura del payload JWT

```json
{
  "sub": 22,
  "email": "cristianmarint@gmail.com",
  "enrollment_step": "completed",
  "active_company_nit": "1",
  "companies": [
    {
      "nit": "1",
      "commercial_name": "SuperPasas",
      "role": {
        "name": "Propietario",
        "is_system": true,
        "permissions": "<base64-compressed-snapshot>"
      }
    }
  ],
  "iat": 1714999999,
  "exp": 1715003599
}
```

- Firma: HS256 con `JWT_SECRET`.
- Payload cifrado AES-256-CBC con `JWT_PAYLOAD_ENCRYPTION_KEY` (el JWT externo es opaco, no decodificable sin la clave).
- TTL: `JWT_TTL` segundos (default 21 600 s = 6 horas — ventana de inactividad para cubrir un turno de trabajo).
- Tope absoluto: `JWT_MAX_LIFETIME` segundos (default 43 200 s = 12 h); el auto-refresh nunca extiende la sesión más allá de `auth_time + JWT_MAX_LIFETIME`.
- Auto-refresh: cuando middleware `ValidateJwt` ve `exp - now < 300s`, llama `JwtService::reissue($payload)` y rota la cookie sin que el cliente lo note.
- Blacklist: `Cache::put("jwt_blacklist:{$signature}", true, $remainingTtl)` cuando se revoca; `verify()` lo consulta en cada request si `JWT_BLACKLIST_ENABLED=true`.

#### Cookie HttpOnly `flexyflow_jwt`

| Atributo | Valor |
|---|---|
| Nombre | `flexyflow_jwt` (config `JWT_COOKIE_NAME`) |
| Valor | el JWT completo (3 partes: `header.payload.signature`) |
| HttpOnly | `true` |
| Secure | `true` en producción (`config('session.secure')`) |
| SameSite | `lax` |
| Path | `/` |
| Domain | `config('session.domain')` (suele ser `.flexyflow.co`) |
| Max-Age | `ceil(JWT_TTL / 60) * 60` segundos |

**Excluida de `EncryptCookies`:** Laravel cifra cookies por defecto, pero esta ya viene cifrada por `JwtService`. Excluida en `bootstrap/app.php` o `app/Http/Middleware/EncryptCookies.php` para evitar doble cifrado.

#### Extracción del JWT en cada request (`JwtService::extractTokenFromRequest`)

Orden de prioridad (primer match gana):
1. Cookie `flexyflow_jwt`.
2. Header `Authorization: Bearer ...` (back-compat con tokens legacy).
3. Session flash `jwt_token` (después de redirect interno con `with('jwt_token', $token)`).
4. Query param `?token=...` (deep links de notificaciones email).

Cuando un token llega por #2/#3/#4 y no hay cookie aún, `ValidateJwt` siembra la cookie automáticamente (migración progresiva del Bearer legacy a HttpOnly).

### 1.6.1 Multi-sede (#117): selector y switch de sede

Una empresa (NIT) puede tener N sedes (locales físicos) bajo el mismo NIT. Cada sede tiene operación, inventario, caja, ingresos y reportes independientes.

#### JWT extendido

- `active_branch_id` (uuid \| null): sede activa. Auto-seleccionada si el usuario tiene 1 sola sede en la empresa; null si tiene N (debe elegir).
- `active_branch_name`, `active_branch_slug`: informativos.
- `branches[]`: lista de sedes accesibles para `active_company_nit` (id, name, slug, is_default, address, city).

#### Flujos

**Login con 1 sede en la empresa**: `JwtService::issue` auto-asigna `active_branch_id`. El usuario va directo al dashboard.

**Login con N sedes**: `active_branch_id` queda null. El frontend redirige a `/auth/branch-selector`, que llama `POST /api/v1/auth/switch-branch` para reemitir el JWT con la sede elegida. Persiste última en `localStorage['flexyflow.last_branch_id:<nit>']` para auto-selección posterior si la sede sigue accesible.

**Cambio de sede en sesión**: el `<BranchSwitcher>` en el sidebar (debajo de `RestaurantIdentity`) abre un dropdown con todas las sedes accesibles + acceso rápido a "Gestionar sedes" si tiene `branches.manage`. Al seleccionar, llama `POST /api/v1/auth/switch-branch` y refresca el dashboard.

**Endpoints**:
- `GET /api/v1/auth/branches-available` — lista sedes accesibles.
- `POST /api/v1/auth/switch-branch` body `{branch_id: uuid}` — valida acceso, no archivada, empresa coincidente. Audita `auth.branch.switched`.

#### Aislamiento de datos (regla innegociable)

- Toda mutación operativa (orders, payment_receipts, inventory, coupons, deliveries, hours, purchases, cash) ocurre dentro de un grupo `branch.access` middleware. El `BranchScope` global aplica `WHERE branch_id = active_branch_id` a 27 modelos automáticamente.
- `branch_id` se inyecta desde `request()->attributes->get('active_branch_id')`, **nunca** del payload del cliente.
- Refunds: deben ejecutarse en la sede del receipt original (forzado por el global scope al resolver la orden).
- Caja por sede: `cash_register_sessions` con UNIQUE INDEX `(company_nit, branch_id) WHERE status='open'` — cada sede su propia caja simultánea.
- Cupones cross-sede: `coupons.scope='company'` + `valid_in_branches` json (NULL = todas). La redención se ancla a la sede de la orden, no la del cupón.

#### Onboarding y default branch

`CompanyEnrollmentController::store` crea automáticamente una `Branch` con `slug='principal'`, `is_default=true`, y la fila correspondiente en `branch_users` para el creador. Sin esto, ninguna mutación operativa funcionaría (todas las tablas tienen `branch_id` NOT NULL).

El owner puede crear/editar/archivar sedes y asignar usuarios desde `/company/branches`. La copia de menú entre sedes está disponible vía `POST /api/v1/company/branches/{branch}/menu/copy` (permiso `branches.copy_menu`); tras copiar, los menús son independientes.

#### Permisos

- `branches.manage` — crear/editar/archivar sedes.
- `branches.assign_users` — asignar/quitar usuarios a sedes.
- `branches.copy_menu` — duplicar menú activo entre sedes.
- `branches.view_all` — ver listado completo (placeholder).
- `metrics.view_all_branches` — reportes consolidados cross-sede (modo `?branch=all` aún no expuesto en endpoints; reservado).

Asignados a `owner` por default en `PermissionTemplateSeeder`.

#### Auditoría

`AuditService::log` agrega automáticamente `branch_id` (sede del recurso auditado) y `actor_active_branch_id` (sede que el usuario tenía activa al ejecutar la acción). Permite reconstruir intentos cross-sede aunque ocurran entre sedes distintas.

### 1.7 Cerrar sesión

#### Web (`POST /logout`)

- `AuthenticatedSessionController::destroy`.
- Invalida la sesión Laravel (`Auth::logout()`, `session()->invalidate()`, `session()->regenerateToken()`).
- **No** invalida el JWT — esa rama es para usuarios legacy con sesión Laravel.
- Redirige a `/`.

#### API (`POST /api/v1/auth/logout`)

- `AuthController::logout`.
- Llama `JwtService::revoke($token)`:
  ```php
  if (config('auth.jwt.blacklist_enabled', true)) {
      $payload = $this->verify($token, allowExpired: true);
      $remaining = max(0, ($payload['exp'] ?? 0) - time());
      Cache::put("jwt_blacklist:{$signature}", true, $remaining);
  }
  ```
- Cookie HttpOnly se borra emitiendo `Cookie::forget(JWT_COOKIE_NAME)` en la respuesta.
- Audit log: `auth.logout` con `{blacklist_enabled, jti}`.
- Frontend: `apiFetch('/api/v1/auth/logout', { method: 'POST' })` → al recibir 200, llama `clearToken()` y `router.visit('/')`.

#### Logout forzado (admin desactiva miembro)

Cuando un admin pone `status=inactive` a un miembro vía `PATCH /api/v1/users/{id}/status`:
- `JwtService::invalidateUserActiveSession($user)` busca el último `UserActiveToken` activo del usuario y lo blacklistea.
- El próximo request del usuario afectado falla con 401 y mensaje `"Sesión revocada por administrador"`.
- `lib/api.ts` detecta el patrón "revoc" en el message → llama `clearToken()` y redirige a `/`.

---

## 2. Onboarding

### 2.1 Onboarding de usuario (`/enrollment/user`)

Página: `resources/js/pages/enrollment/user.tsx`. Gate: el closure de la ruta web verifica `users.status == 'pending_enrollment'`; si ya completó redirige a `/dashboard`.

#### Wizard de 3 pasos

**Paso 1 — Datos personales:** captura `first_name`, `last_name`, `cedula`.

**Paso 2 — Aceptación legal:** muestra TOS y Política de Privacidad como links que abren el sitio institucional (`flexyflow.co`) en una pestaña nueva. Las URLs llegan desde `useBootstrap().data.legalUrls`:
```json
{
  "type": "tos",
  "version": "1.2.0",
  "content": "<markdown>",
  "published_at": "2026-01-15T00:00:00Z"
}
```
El frontend guarda `tos_version` y `privacy_version` para enviarlos en el siguiente paso.

> **Fuente de verdad:** TOS (`https://flexyflow.co/terms-conditions/`) y privacidad (`https://flexyflow.co/privacy-policy/`) viven en el sitio institucional, fuera de este repo. El contrato de servicio vive en el repo (`bistro/frontend/src/data/legal/contrato.md`) y se sirve en el propio SPA en `/legal/contract`. `useBootstrap().data.legalUrls` expone las 3 URLs; el enrollment las abre en pestaña nueva.

**Paso 3 — Vinculación:** dos opciones:
- **Crear nueva empresa** → al hacer "Continuar" envía a `/enrollment/company`.
- **Aceptar invitación pendiente** → si el `email` del usuario coincide con `company_invitations.email` y `status=pending`, la UI muestra el banner "Invitación pendiente para empresa X" y al confirmar va a `/enrollment/invited`.

#### Endpoint de cierre

```
POST /api/v1/enrollment/user
Headers: Authorization: Bearer <jwt>  (o cookie HttpOnly)
Body:
{
  "first_name": "Cristian",
  "last_name": "Marín",
  "cedula": "1112792674",
  "accepted_documents": [
    {"type": "tos",     "version": "1.2.0"},
    {"type": "privacy", "version": "1.0.5"}
  ]
}
```

**FormRequest**: `App\Http\Requests\Enrollment\UserEnrollmentRequest` valida:
- `first_name`: `required|string|max:100`
- `last_name`: `required|string|max:100`
- `cedula`: `required|string|max:20|unique:users,cedula,{$user->id}`
- `accept_tos`: `required|boolean|accepted`
- `accept_privacy`: `required|boolean|accepted`

**Controller** (`UserEnrollmentController::store`):
1. Valida que el usuario está en `status=pending_enrollment` (si no, 422 con `enrollment.already_completed`).
2. En transacción:
   - Update `users` con `first_name`, `last_name`, `cedula`, `status='active'`.
   - Insert en `user_acceptances` por cada documento (`terms` y `privacy`): `(user_id, document_type, accepted_at=now(), ip, user_agent)`. Sin snapshot — TOS/privacidad versionadas en el sitio institucional, fuera de este repo.
3. Reissue JWT con el nuevo `enrollment_step`.
4. Audit log: `user.enrolled` con `{accepted_documents: ['terms','privacy']}`.

**Respuesta (200)**:
```json
{
  "data": { "id": 22, "enrollment_step": "pending_company" },
  "next_step": "/enrollment/company"
}
```

**Errores**:
| Status | Código aplicación | Causa |
|---|---|---|
| 422 | `enrollment.already_completed` | El usuario ya completó enrollment |
| 422 | `validation` | Falla de FormRequest (cedula duplicada, campos faltantes, checkboxes legales sin aceptar) |

### 2.2 Onboarding de empresa (`/enrollment/company`)

Página: `resources/js/pages/enrollment/company.tsx`. Gate: `users.status == 'pending_company'`. Recibe prop Inertia `availableBanks` con `Bank::orderBy('name')->get(['id','name','code'])`.

#### Wizard de 2 pasos

**Paso 1 — Contrato de servicio:**
- El contrato vive en el repo (`contrato.md`) y se sirve en `/legal/contract` (`bootstrap.legalUrls.contract`, resuelto contra `app.frontend_url` del ambiente). El link del checkbox abre el documento en una pestaña nueva.
- Checkbox obligatorio "He leído y acepto el contrato de servicio".

**Paso 2 — Datos de empresa:**
- `nit`: input de texto, máx 20 chars (acepta dígitos y guion para verificador, ej. `900123456-7`).
- `commercial_name`: máx 100 chars.
- `legal_name`: máx 150 chars.
- `bank_id`: select desde `availableBanks` prop.
- `account_number`: máx 30 chars (numérico).
- `account_type`: radio `corriente` / `ahorros`.
- `breb_key` (opcional): máx 50 chars.
- `qr_code`: file input opcional (PNG/JPG, **máx 5 MB**).
- **`proof_document` (obligatorio, #154)**: documento de propiedad de la empresa. Formatos PDF, Word (`.doc`, `.docx`), JPG, PNG. Máx **10 MB**. Drag/drop + selector. Validado tanto en cliente como en backend (`mimetypes:` lee MIME real). Sin archivo válido, el botón "Registrar empresa" queda deshabilitado.

#### Endpoint de cierre

```
POST /api/v1/enrollment/company
Content-Type: multipart/form-data (porque tiene archivo)
```

**FormRequest**: `App\Http\Requests\Enrollment\CompanyEnrollmentRequest` valida:
- `nit`: `required|string|max:20|unique:companies,nit`
- `commercial_name`: `required|string|max:100`
- `legal_name`: `required|string|max:150`
- `bank_id`: `required|integer|exists:banks,id`
- `account_number`: `required|string|max:30`
- `account_type`: `required|in:corriente,ahorros`
- `breb_key`: `nullable|string|max:50`
- `qr_code`: `nullable|file|mimes:png,jpg,jpeg|max:5120` (KB)
- `accept_contract`: `required|boolean|accepted`

**Controller** (`CompanyEnrollmentController::store`):
1. En transacción:
   - **Insert `companies`** con `status='pending_activation'`. La columna `plan` fue eliminada en #257 — el plan vive en `subscriptions` (snapshot inmutable) y se crea al aprobar el registro vía `php artisan companies:approve`.
   - **Crea 3 roles del sistema** copiando templates desde `permission_templates`:
     ```php
     foreach (['owner', 'admin', 'employee'] as $type) {
         $role = CompanyRole::create([
             'company_nit' => $nit,
             'name' => config("roles.role_names.{$type}"),
             'is_system' => true,
             'color' => match($type) { 'owner'=>'#C0FD79', 'admin'=>'#8B5CF6', 'employee'=>'#F59E0B' },
         ]);
         foreach (PermissionTemplate::where('role_type', $type)->get() as $tpl) {
             CompanyRolePermission::create([
                 'company_role_id' => $role->id,
                 'feature_id' => $tpl->feature_id,
                 'can_create' => $tpl->can_create,
                 'can_read' => $tpl->can_read,
                 'can_update' => $tpl->can_update,
                 'can_delete' => $tpl->can_delete,
             ]);
         }
     }
     ```
   - **Insert membership** en `company_users`: `(user_id, company_nit, company_role_id=ownerRoleId, status='active')`.
   - **Insert `user_acceptances`** del contrato (sin snapshot — contenido vive en el wiki externo).
   - **Si hay QR:** `Storage::disk('public')->putFile("companies/qr-codes", $request->file('qr_code'))` → guarda `qr_code_path` en `companies`.
   - **Update `users.status='completed'`**.
2. Reissue JWT con `active_company_nit=$nit` y `companies=[{...}]` poblado.
3. Audit log: `company.created` con `{nit, owner_id}`, `user.enrolled` final, `auth.company.selected`.

**Respuesta (200)**:
```json
{
  "data": {
    "company": { "nit": "900123456-7", "commercial_name": "SuperPasas", "status": "pending_activation" },
    "role": "Propietario"
  }
}
```
Frontend redirige a `/dashboard`.

**Errores comunes**:
| Status | Código | Causa |
|---|---|---|
| 422 | `validation.nit.unique` | NIT ya existe globalmente |
| 422 | `validation.qr_code.max` | QR > 5 MB |
| 422 | `validation.qr_code.mimes` | QR no es PNG/JPG |
| 422 | `legal_document.version_mismatch` | Versión del contrato cambió mientras llenaba el wizard |

### 2.3 Onboarding por invitación (`/enrollment/invited`)

Para usuarios cuya cuenta es nueva pero ya tienen `company_invitations` pendiente con su email.

#### Endpoint

```
POST /api/v1/enrollment/invited
Body: { "invitation_token": "abc123..." }
```

**FormRequest** valida:
- `invitation_token`: `required|string|exists:company_invitations,token`

**Controller** (`InvitedEnrollmentController::store`):
1. Busca `CompanyInvitation::where('token', $token)->first()`.
2. Valida:
   - `invitation.email == auth()->user()->email` (mismatch → 403).
   - `invitation.status == 'pending'` (si `accepted` → 422 `invitation.already_accepted`; si `expired` → 422 `invitation.expired`).
   - `invitation.expires_at >= now()` (si pasó → marca `status='expired'` y devuelve 422).
3. En transacción:
   - Insert membership en `company_users` con `company_role_id=invitation.company_role_id`.
   - Update `company_invitations.status='accepted'`, `accepted_at=now()`.
   - Update `users.status='completed'` si estaba `pending_company`.
4. Reissue JWT con la empresa nueva.
5. Audit logs: `invitation.accepted`, `auth.company.selected`.

#### Validez y expiración

- Default TTL invitación: 7 días (configurable `INVITATION_TTL_DAYS`).
- Las invitaciones `pending` cuya `expires_at < now()` no se aceptan; el comando programado (no implementado actualmente, sólo verificación lazy en el endpoint) podría marcarlas `expired` automáticamente.
- Reuso: si el email ya es miembro, la invitación se rechaza con `invitation.user_already_member` (validado en `InvitationController::store` cuando se crea).

---

## 3. Dashboard operativo (`/dashboard`)

Página: `resources/js/pages/dashboard.tsx`. Render server-side: `App\Http\Controllers\Web\DashboardController::index` (`routes/web.php`).

### 3.0 Gate y permisos

`DashboardController::index` construye un `Request` sintético con `active_company_nit` del JWT y consulta `ReportsPermissionService::hasPermission()`:

- Si el usuario **no tiene** `reports.read` → renderiza igual la página, pero todas las deferred props devuelven `null` y los paneles se ocultan en el frontend (no hay redirect ni error).
- Si **sí tiene** `reports.read` → ejecuta `MetricsService::getSummary()` y otros métodos en lazy (Inertia `defer`).

JWT extraction: el controller usa `JwtService::extractTokenFromRequest()` para aceptar el token vía `?token=` (deep links de email), Bearer header, o cookie HttpOnly. Si quedan <300s de TTL, reissue automático y rota la cookie con `Cookie::queue(buildCookie($newToken))`.

### 3.1 Panel `summary` — KPIs principales

Servido como **deferred prop Inertia** (no como REST). Esto significa: la página renderiza inmediatamente con un skeleton, y cuando `MetricsService::getSummary()` retorna, los props se hidratan client-side. **No existe endpoint `/api/v1/metrics/kpis`** — es un alias mental; el dashboard consume `summary` directamente.

#### Datos retornados por `MetricsService::getSummary($companyNit, $period, $branchId = null)`

> `$branchId` (activo desde v1.16.0) filtra por sede activa. `DashboardController` lo extrae de `request()->attributes->get('active_branch_id')` y lo pasa a `getSummary`, `getOrderHeatmap` y `getCartAbandonment`.

```typescript
{
  // Período actual
  total_orders: number,            // count de orders en period_from..period_to
  revenue_count: number,            // count(orders) WHERE status IN revenue_statuses
  total_revenue: number,            // sum(orders.total) WHERE status IN revenue_statuses
  avg_ticket: number,               // total_revenue / revenue_count (0 si revenue_count == 0)
  active_count: number,             // count WHERE status IN config('orders.operational') = ['pending','in_kitchen','ready','in_transit']
  cancelled_count: number,          // count WHERE status = 'cancelled'
  abandoned_count: number,          // count WHERE status = 'abandoned'

  // Período anterior (mismo tamaño desplazado hacia atrás)
  comparison: {
    total_orders: number,
    total_revenue: number,
    avg_ticket: number,
    revenue_count: number,
  },

  // Deltas (porcentajes, null si comparison da 0)
  deltas: {
    orders: number | null,           // ((current - prev) / prev) * 100
    revenue: number | null,
    avg_ticket: number | null,
  },

  // Metadata
  period: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' },
  generated_at: 'ISO 8601',
}
```

**`revenue_statuses`** — fuente única de verdad: `config('orders.revenue')` = `['completed']`. La doc anterior mencionaba `['completed', 'in_delivery', 'successful']` (esquema legacy); la migración `2026_05_07_192524_migrate_order_statuses_to_canonical_set` unificó `successful → completed` y renombró `in_delivery → in_transit`. **Solo `completed` cuenta como ingreso confirmado** (un pedido `in_transit` aún no se cobró). `config/metrics.php` queda como alias deprecado del valor canónico.

#### Cálculo del `avg_ticket`

```sql
SELECT
  COUNT(*) FILTER (WHERE status IN ('completed')) AS revenue_count,
  SUM(total) FILTER (WHERE status IN ('completed')) AS total_revenue
FROM orders
WHERE company_nit = ? AND ordered_at BETWEEN ? AND ?
```
PHP:
```php
$avgTicket = $revenueCount > 0 ? $totalRevenue / $revenueCount : 0;
```

**Por qué dividir por `revenue_count` y no por `total_orders`:** el ticket promedio sólo tiene sentido sobre órdenes que generaron ingreso. Si dividiéramos por `total_orders` (que incluye `cancelled` y `abandoned`), un día con muchos abandonos parecería tener "ticket bajo" cuando en realidad sólo fue un día con baja conversión. Esta corrección se aplica también en `comparison.avg_ticket`.

#### Cálculo del delta vs período anterior

```php
function delta(float $current, float $prev): ?float {
    if ($prev == 0.0) return null;  // oculta el delta — no se puede calcular %
    return round((($current - $prev) / $prev) * 100, 2);
}
```

Si `prev = 0` (ej. empresa nueva sin histórico) → `delta = null` y la UI **no muestra el badge**, evitando "ingresos crecieron ∞%". Validado para `total_orders`, `total_revenue`, `avg_ticket`.

#### Cálculo del período anterior

```php
$periodLength = $dateTo->diffInDays($dateFrom) + 1;  // inclusivo
$prevTo = $dateFrom->copy()->subDay()->endOfDay();
$prevFrom = $prevTo->copy()->subDays($periodLength - 1)->startOfDay();
```

Ejemplo: si actual = `2026-05-01..2026-05-07` (7 días), anterior = `2026-04-24..2026-04-30`.

#### Cache

Key: `metrics:summary:{nit}:{periodHash}` donde `periodHash = sha1($from.$to)`. TTL: `config('metrics.dashboard_summary_cache_ttl', 60)` segundos.

Invalidación: las órdenes nuevas no invalidan caché — el TTL corto (60s) es suficiente para ser percibido como real-time. Si necesitas frescura inmediata, el filtro de período fuerza un reload sin cache (Inertia partial reload sin ETag).

### 3.2 Panel "Órdenes activas"

Único panel con polling REST directo (no Inertia deferred): `useWidgetFetch('/api/v1/metrics/orders/active', { interval: 30_000 })`.

#### Endpoint

```
GET /api/v1/metrics/orders/active
Middleware: jwt + company.access + permission:reports.read,read
```

**Respuesta**:
```json
{
  "data": {
    "by_status": {
      "pending": 3,
      "in_kitchen": 5,
      "ready": 2,
      "in_transit": 4
    },
    "total": 14,
    "generated_at": "2026-05-06T22:14:30Z"
  }
}
```

#### Implementación

```php
public function activeOrders(Request $request): JsonResponse {
    $nit = $request->attributes->get('active_company_nit');
    $data = Cache::remember(
        "metrics:active_orders:{$nit}",
        30,
        fn () => $this->buildActiveOrders($nit)
    );
    return response()->json(['data' => $data]);
}

private function buildActiveOrders(string $nit): array {
    $rows = DB::table('orders')
        ->where('company_nit', $nit)
        // Lista canónica: config('orders.operational')
        ->whereIn('status', ['pending', 'in_kitchen', 'ready', 'in_transit'])
        ->groupBy('status')
        ->selectRaw('status, COUNT(*) as cnt')
        ->pluck('cnt', 'status')
        ->all();

    return [
        'by_status' => array_merge(
            ['pending' => 0, 'in_kitchen' => 0, 'ready' => 0, 'in_transit' => 0],
            $rows
        ),
        'total' => array_sum($rows),
        'generated_at' => now()->toIso8601String(),
    ];
}
```

Cache TTL hardcoded a 30s (no toma `METRICS_CACHE_TTL`) porque este endpoint debe ser muy fresco. El polling client-side es 30s también, así que en el peor caso un hit de cache vence justo cuando llega la petición — máxima latencia 60s entre cambios reales y UI.

### 3.3 Heatmap horario (deferred prop `heatmap`)

#### Datos

```typescript
{
  buckets: Array<{ hour: 0..23, count: number }>,  // 24 elementos siempre
  total: number,
  peak_hour: number,                                // hora con max count
  generated_at: 'ISO 8601',
}
```

#### Implementación (`MetricsService::getOrderHeatmap`)

```sql
SELECT
  EXTRACT(HOUR FROM ordered_at)::int AS hour,
  COUNT(*) AS cnt
FROM orders
WHERE company_nit = ? AND ordered_at BETWEEN ? AND ?
GROUP BY hour
ORDER BY hour
```

PHP normaliza a 24 buckets (rellena horas con 0 cuando no hay data) — el front siempre recibe `[{hour:0,count:0}, {hour:1,count:0}, ..., {hour:23,count:n}]`.

Cache key: `metrics:heatmap:{nit}:{periodHash}`. TTL: `dashboard_heatmap_cache_ttl` (default 600s). Más largo porque el heatmap es estable: cambia poco entre minutos.

### 3.4 Heatmap semanal `weekly_heatmap` (separado del heatmap diario)

`GET /api/v1/metrics/orders/heatmap/weekly` retorna matriz 7×24:

```sql
SELECT
  EXTRACT(DOW FROM ordered_at)::int AS dow,    -- 0=domingo, 6=sábado
  EXTRACT(HOUR FROM ordered_at)::int AS hour,
  COUNT(*) AS cnt
FROM orders
WHERE company_nit = ? AND ordered_at BETWEEN ? AND ?
GROUP BY dow, hour
```

Output: `Array<{dow: 0..6, hour: 0..23, count: number}>` (168 elementos teóricos; el frontend rellena los faltantes con 0).

### 3.5 Panel "Abandono de carritos" (deferred prop `abandonment`)

#### Datos

```typescript
{
  total_carts: number,           // count(cart_sessions) en periodo
  abandoned_count: number,        // count WHERE status = 'abandoned'
  converted_count: number,        // count WHERE status = 'converted' (tiene Order asociada)
  rate: number,                   // abandoned_count / total_carts
  conversion_rate: number,        // converted_count / total_carts
  estimated_lost_revenue: number, // sum(cart_items.price * quantity) WHERE session.status='abandoned'
  generated_at: string,
}
```

#### Implementación

```php
public function getCartAbandonment(string $nit, Carbon $from, Carbon $to): array {
    $sessions = CartSession::where('company_nit', $nit)
        ->whereBetween('created_at', [$from, $to])
        ->get(['id', 'status']);

    $abandoned = $sessions->where('status', 'abandoned');
    $converted = $sessions->where('status', 'converted');

    $lostRevenue = CartItem::whereIn('cart_session_id', $abandoned->pluck('id'))
        ->sum(DB::raw('price * quantity'));

    return [
        'total_carts' => $sessions->count(),
        'abandoned_count' => $abandoned->count(),
        'converted_count' => $converted->count(),
        'rate' => $sessions->count() > 0 ? $abandoned->count() / $sessions->count() : 0,
        'conversion_rate' => $sessions->count() > 0 ? $converted->count() / $sessions->count() : 0,
        'estimated_lost_revenue' => (float) $lostRevenue,
    ];
}
```

Cache TTL: 300s (`dashboard_chart_cache_ttl`).

**Nota:** `cart_sessions.status` puede ser `active|converted|abandoned|expired`. Una sesión queda `abandoned` cuando se cumple alguna de las siguientes condiciones (definido por el bot/job no implementado en este repo, o manualmente):
- TTL del JWT del carrito expiró (default 70 min) sin conversión.
- El cliente cerró la conversación sin confirmar.

### 3.6 Panel "Entregas" (deferred prop `deliveries`)

#### Gate

`DeliveryService::getCompanyMetrics()` se llama sólo si el usuario tiene `deliveries.read`. Si no tiene, la deferred prop retorna `null` y el panel se oculta silenciosamente en el frontend.

#### Datos

```typescript
{
  total: number,                   // count(deliveries) en periodo (incluye soft-deleted? No, sin .withTrashed)
  by_status: { pending: n, completed: n, cancelled: n },
  avg_duration_minutes: number,    // AVG(duration_minutes) WHERE delivered_at NOT NULL
  on_time_rate: number,             // % completadas en < threshold (default 45 min)
  by_courier: Array<{
    user_id: number,
    name: string,
    deliveries: number,
    avg_minutes: number,
    success_rate: number,
  }>,
  generated_at: string,
}
```

### 3.7 Filtros de período y polling

#### Períodos válidos

Validados por `App\Services\PeriodResolver::resolve($period, $dateFrom, $dateTo)`:

| Period | from | to |
|---|---|---|
| `today` | `now->startOfDay()` | `now->endOfDay()` |
| `week` | `now->startOfWeek()` | `now->endOfWeek()` (Carbon::MONDAY como inicio configurable) |
| `month` | `now->startOfMonth()` | `now->endOfMonth()` |
| `custom` | `Carbon::parse($from)` | `Carbon::parse($to)` |

Si `period=custom`, **`date_from` y `date_to` son obligatorios** (FormRequest `required_if:period,custom|date_format:Y-m-d`). Rango máximo: `REPORT_MAX_DATE_RANGE_DAYS` (default 90).

#### Polling Inertia

En `dashboard.tsx`:
```tsx
import { usePoll } from '@inertiajs/react';
usePoll(60_000, { only: ['summary', 'heatmap', 'abandonment', 'deliveries'] });
```

Cada 60 s Inertia ejecuta `router.reload({ only: [...] })` que **NO** re-renderiza la página entera — sólo re-fetch de los props especificados. El backend re-ejecuta los closures de `Inertia::defer(fn () => ...)` para esas props.

#### Filtro de período (hook `usePeriodFilter`)

```tsx
const { period, setPeriod, isLoading } = usePeriodFilter('today');

// onChange:
setPeriod('week');
// → router.reload({
//     only: ['summary','heatmap','abandonment','deliveries'],
//     data: { period: 'week' }
//   });
```

`router.reload` con `data` propaga los query params al closure del controller, que recalcula las métricas con el nuevo período. La URL no cambia (Inertia preserva la URL actual), sólo los props.

### 3.8 Empty states

- **Empresa sin órdenes:** `MetricsService::getSummary` devuelve todos los KPIs en `0`, deltas en `null`, `avg_ticket=0`. La UI muestra `$0` y oculta el badge de delta.
- **Empresa sin carritos:** `getCartAbandonment` devuelve todo en `0` con `rate=0`.
- **Empresa sin entregas o sin permiso:** `deliveries` prop = `null` → panel oculto.
- **Empresa sin órdenes activas hoy:** `by_status` con todos en 0; UI muestra "Sin pedidos en curso".
- **Errores de red en polling:** `useWidgetFetch` reintenta con backoff exponencial (2s, 4s, 8s; máx 3 reintentos). Tras agotar, muestra `WidgetErrorState` con botón "Reintentar".

---

## 4. Métricas operativas (`/company/metrics`)

Página dedicada con vista detallada y exportación. Breadcrumb: `Dashboard › Mi Empresa › Métricas`. Página: `resources/js/pages/metrics/index.tsx`. Render: closure en `routes/web.php` con name `company.metrics`.

### 4.0 Gate web

Antes de renderizar la página, el closure construye un `Request` sintético:
```php
$synthetic = Request::create('/');
$synthetic->attributes->set('active_company_nit', $companyNit);
$synthetic->attributes->set('jwt_payload', $payload);

if (!$featurePermission->hasPermission($synthetic, 'reports', 'read')) {
    return redirect()->route('dashboard')->with('jwt_token', $token);
}
```

**Comportamiento sin permiso:** redirect a `/dashboard` (no 403). Esto evita la situación previa donde la página renderizaba para cualquier rol y los 12+ endpoints de métricas devolvían 403 dejando una UI vacía con toasts. Ahora el gate web se alinea con el RBAC de la API.

### 4.1 Endpoints REST consumidos (10 endpoints)

Todos requieren `permission:reports.read,read`. Tabla con TTL de cache, query params, response shape:

| Endpoint | TTL | Query params | Response key |
|---|---|---|---|
| `GET /api/v1/metrics/summary` | 60s | `period`, `date_from?`, `date_to?` | `data: { kpis, comparison, deltas }` |
| `GET /api/v1/metrics/kpis/today` | 60s | — | `data: KpisToday` (snapshot fijo de hoy) |
| `GET /api/v1/metrics/orders/active` | 30s | — | `data: { by_status, total }` |
| `GET /api/v1/metrics/orders/heatmap` | 600s | `period`, `date_from?`, `date_to?` | `data: { buckets[24], peak_hour }` |
| `GET /api/v1/metrics/orders/heatmap/weekly` | 600s | mismo | `data: { matrix: 7x24, dow_totals[7] }` |
| `GET /api/v1/metrics/items/top` | 300s | `period`, `limit` (max 50) | `data: [{ item_id, name, qty, revenue }]` |
| `GET /api/v1/metrics/dishes/ranking` | 300s | mismo | similar a items/top, ordenado distinto |
| `GET /api/v1/metrics/dishes/margin` | 300s | `period`, `date_from?`, `date_to?`, `limit` (1..100, def 50) | `data: { items: [{ item_id, name, units_sold, avg_price, avg_cost, gross_revenue, gross_cost, margin_amount, margin_pct }], total_unique_items }`. Solo platos con `cost` registrado en el menú. Issue #107 |
| `GET /api/v1/metrics/cart/abandonment` | 300s | `period` | `data: { rate, abandoned, converted, lost_revenue }` |
| `GET /api/v1/metrics/carts/abandonment` | 300s | mismo (alias plural, mismo handler) | mismo shape pero key `abandonment_rate` en lugar de `rate` |
| `GET /api/v1/metrics/activity/heatmap` | 600s | `period` | actividad general (incluye carritos, no solo órdenes) |
| `GET /api/v1/metrics/foodcost/summary` | 300s (flexible) | `period`, `date_from?`, `date_to?`, `limit` (1..200) | `data: { totals: { gross_revenue, gross_cost, cost_ratio_pct, margin_pct, units_sold, units_with_cost, coverage_pct }, items: [{ item_id, name, units_sold, avg_price, avg_cost, margin_pct, cost_ratio, has_cost, ... }], snapshot_meta: { last_snapshot_at, scheduler_lag_hours } }`. Items con `cost IS NULL` o `=0` se excluyen del cost_ratio agregado y se reportan vía `coverage_pct`. Issue #113 |
| `GET /api/v1/metrics/foodcost/items/{menuItemId}/history` | sin cache | `period`, `date_from?`, `date_to?` | `data: { menu_item_id, name, archived, points: [{ date, cost, source: recipe\|manual }] }`. Sparkline desde `menu_item_cost_history`. Issue #113 |
| `GET /api/v1/metrics/menu-engineering` | 300s (flexible) | `period` (default `month`), `date_from?`, `date_to?`, `limit` (1..200) | `data: { thresholds: { popularity_pct, contribution_margin }, summary: { stars, cows, puzzles, dogs, unknown, classifiable, total_units, unknown_units }, dishes: [{ item_id, name, units_sold, popularity_pct, contribution_margin, total_contribution, quadrant, recommendation, ... }] }`. Cuadrantes calculados con mediana como umbral. Items sin costo conocido se excluyen del matrix y se reportan en `summary.unknown`. Issue #114 |

### 4.2 FormRequest de validación

`App\Http\Requests\Metrics\GetMetricsSummaryRequest` (y similares por endpoint):

```php
public function rules(): array {
    return [
        'period' => ['required', Rule::in(['today', 'week', 'month', 'custom'])],
        'date_from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
        'date_to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
    ];
}

public function after(): array {
    return [function (Validator $v) {
        if ($this->validated()['period'] === 'custom') {
            $diff = Carbon::parse($this->date_from)->diffInDays($this->date_to);
            if ($diff > config('reports.max_date_range_days', 90)) {
                $v->errors()->add('date_to', "Rango máximo {$max} días.");
            }
        }
    }];
}
```

**Importante**: SQL injection imposible — `period` validado por `Rule::in` y `date_from`/`date_to` por `date_format:Y-m-d` antes de llegar al servicio. Strings con payloads caen en 422.

### 4.3 Live mode (toggle controlado por usuario)

Componente `LivePollingToggle` + hook `useLivePolling`:

```tsx
const { enabled, toggle, autoOffMs } = useLivePolling({
  intervalMs: 60_000,
  autoOffMs: 5 * 60_000,  // 5 min
  onTick: () => router.reload({ only: ['summary', 'top_items', 'abandonment'] }),
});
```

- **Desactivado por defecto** para no cargar el backend.
- Si el usuario lo activa, dispara `setInterval` cada 60s.
- **Auto-off a los 5 min** (timer interno desde `activatedAt`). Evita que un operador deje la pestaña abierta toda la noche generando carga innecesaria.
- Botón muestra contador regresivo "Live: 4:23".

### 4.4 Exportar PDF de métricas

```
POST /api/v1/exports/metrics/pdf
Content-Type: application/json
Body:
{
  "filters": {
    "date_from": "2026-04-01",
    "date_to": "2026-04-30"
  }
}
```

**Permission:** `permission:reports.read,read` (`PdfExportController::metrics` línea 56).

**Servicio:** `PdfExportService::exportMetrics()`:
1. Resuelve dateFrom/dateTo (caen en valid range, valida).
2. Llama `MetricsService::getSummary()` y top items para el rango.
3. Renderiza blade `pdf.metrics` con DomPDF.
4. Cap de filas: `pdf.max_rows` (default 500). Aviso `limitApplied=true` si supera.
5. Si no hay órdenes en rango → throws `PdfEmptyDataException` → controller responde 422 con `{message: "No hay datos para exportar"}`.

**Respuesta exitosa:** binary PDF con `Content-Type: application/pdf`. El componente `ExportPdfButton` abre `URL.createObjectURL(blob)` en pestaña nueva.

### 4.5 Caché por endpoint

| Endpoint | TTL config | Default | Toggle |
|---|---|---|---|
| `metrics/summary` | `dashboard_summary_cache_ttl` | 60s | `DASHBOARD_CACHE_ENABLED` |
| `metrics/orders/active` | hardcoded | 30s | mismo toggle |
| `metrics/orders/heatmap` | `dashboard_heatmap_cache_ttl` | 600s | mismo |
| `metrics/items/top` | `dashboard_chart_cache_ttl` | 300s | mismo |
| ... | | | |

**Toggle master `DASHBOARD_CACHE_ENABLED`**: si `false`, todos los métodos de `MetricsService` ejecutan la query directa sin pasar por `Cache::remember`. Útil para QA y troubleshooting.

**Patrón**:
```php
public function getSummary(string $nit, ...): array {
    if (!config('metrics.dashboard_cache_enabled', true)) {
        return $this->buildSummary($nit, ...);
    }
    return Cache::remember(
        "metrics:summary:{$nit}:" . sha1($from . $to),
        config('metrics.dashboard_summary_cache_ttl', 60),
        fn () => $this->buildSummary($nit, ...)
    );
}
```

### 4.6 Top items / Top dishes — diferencias

Ambos endpoints retornan rankings de platos pero con criterios distintos:

- **`metrics/items/top`** ordena por **revenue** (`SUM(items.price * items.quantity)` extraído del JSON `orders.items`).
- **`metrics/dishes/ranking`** ordena por **cantidad** (`SUM(items.quantity)`).

Implementación con PostgreSQL JSONB:
```sql
SELECT
  item->>'id' AS item_id,
  item->>'name' AS name,
  SUM((item->>'quantity')::int) AS qty,
  SUM((item->>'price')::numeric * (item->>'quantity')::int) AS revenue
FROM orders, jsonb_array_elements(items) AS item
WHERE company_nit = ? AND ordered_at BETWEEN ? AND ?
  AND status IN ('completed')  -- config('orders.revenue') — MetricsService::getTopItems
GROUP BY item_id, name
ORDER BY revenue DESC  -- o qty DESC
LIMIT ?
```

Cache key: `metrics:top_items:{nit}:{periodHash}:{limit}`. TTL: `dashboard_chart_cache_ttl` (300s).

### 4.7 Heatmap de actividad (incluye carritos)

`GET /api/v1/metrics/activity/heatmap` mezcla órdenes y carritos para un heatmap de "actividad total" (incluyendo abandonos):
```sql
SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS cnt FROM (
  SELECT ordered_at AS created_at FROM orders WHERE company_nit=? AND ordered_at BETWEEN ? AND ?
  UNION ALL
  SELECT created_at FROM cart_sessions WHERE company_nit=? AND created_at BETWEEN ? AND ?
) t GROUP BY hour ORDER BY hour
```

Útil para detectar tráfico que no convierte (ej. mucha actividad en cart_sessions a las 14h pero pocas órdenes → posible problema de menú o bot).

---

## 5. Informes de pedidos (`/company/reports`)

Listado paginado con filtros y exportación. Breadcrumb: `Dashboard › Mi Empresa › Informes`. Página: `resources/js/pages/reports/index.tsx`. Render: closure en `routes/web.php` con name `company.reports`.

### 5.0 Gate web

Idéntico patrón que `company.metrics`:
```php
if (!$featurePermission->hasPermission($synthetic, 'reports', 'read')) {
    return redirect()->route('dashboard')->with('jwt_token', $token);
}
```
Sin permiso → redirect a `/dashboard` (no 403). Antes la página renderizaba para todos y los endpoints `/api/v1/reports/*` devolvían 403, dejando UI vacía con toasts.

### 5.1 Endpoint principal `GET /api/v1/reports/orders`

Controller: `App\Http\Controllers\Reports\OrderReportController::index`. FormRequest: `OrderReportRequest`.

#### Query params

```
period:        daily | weekly | monthly | custom (required)
date_from:     YYYY-MM-DD (required_if:period,custom)
date_to:       YYYY-MM-DD (required_if:period,custom, after_or_equal:date_from)
status:        all | cualquiera de config('orders.all') — pending_approval | pending |
                in_kitchen | ready | in_transit | completed | failed |
                cancelled | refunded | abandoned (default: all)
page:          int min:1 (offset pagination)
per_page:      int min:1 max:100 (default 25)
cursor_based:  boolean (default false)
cursor:        opaque string (cursor pagination cuando cursor_based=true)
```

#### Resolución de período

```php
match ($validated['period']) {
    'daily'   => [Carbon::today(), Carbon::today()],
    'weekly'  => [Carbon::today()->subDays(6), Carbon::today()],
    'monthly' => [Carbon::today()->subDays(29), Carbon::today()],
    'custom'  => [Carbon::parse($from), Carbon::parse($to)],
}
```

`weekly` = últimos 7 días (no semana ISO). `monthly` = últimos 30 días.

#### Validación adicional (after callback)

Si `period=custom`, valida rango ≤ `REPORT_MAX_DATE_RANGE_DAYS` (default 90). Excedido → 422 con `date_to: "El rango máximo permitido es de 90 días."`.

#### Query base

```php
$baseQuery = Order::where('company_nit', $companyNit)
    ->whereBetween('ordered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

// Si status != 'all': $baseQuery->where('status', $status);
// Para summary se clona sin el filtro de status (todos cuentan en el summary).
```

#### Pagination

**Offset (default):**
```php
$paginated = $orderedQuery->paginate(min($perPage, 100));
// Response: { current_page, last_page, per_page, total }
```

**Cursor (`cursor_based=true`)**: usa `cursorPaginate` para listas largas. Más eficiente cuando page >> 1 (no recalcula offset, usa ID como índice). Limit `per_page` por `mobile.api_max_page_size` (default 100).

#### Response shape

```json
{
  "period": { "from": "2026-04-01", "to": "2026-04-30" },
  "summary": {
    "total_orders": 1240,
    "successful": 980,
    "cancelled": 45,
    "abandoned": 215,
    "total_revenue": 38540000
  },
  "orders": [
    {
      "id": 12345,
      "status": "completed",
      "order_type": "delivery",
      "total": 47000,
      "discount_amount": 0,
      "coupon_code": null,
      "client_phone": "573001112233",
      "delivery_address": "Cra 43A #18-95",
      "table_number": null,
      "items": [...JSON...],
      "ordered_at": "2026-04-15T19:32:00Z"
    }
  ],
  "pagination": { "current_page": 1, "last_page": 50, "per_page": 25, "total": 1240 }
}
```

### 5.2 KPIs del período (key `summary`)

Cálculo en `OrderReportController` (agrupación por status en SQL + refunds desde `payment_receipts`):

```php
// COUNT + SUM(total) GROUP BY status, keyBy('status').
// Devoluciones: SUM(-amount) de payment_receipts method=refund del período.
// Bruto incluye completed Y refunded (la devolución es asiento aparte —
// excluir la venta original provocaría doble descuento).
$grossRevenue = completed.total_sum + refunded.total_sum;

return [
    'total_orders', 'completed', 'failed', 'cancelled', 'refunded', 'abandoned',
    'total_revenue'  => $grossRevenue,
    'total_refunded' => $totalRefunded,
    'net_revenue'    => $grossRevenue - $totalRefunded,
];
```

**Importante**:
- El summary muestra **gross / refunds / net explícitos** (regla contable `.claude/contabilidad.md`). No existe `successful` en el código — quedó unificado en `completed` por la migración `2026_05_07_192524`.
- `total_expenses` **se eliminó** del summary y de la UI en su momento; hoy `orders.cost` sí se calcula (food cost #107) y el margen vive en `/api/v1/metrics/dishes/margin`.

### 5.3 Estados y colores

El frontend NO tiene mapa local: `pages/reports/index.tsx` usa `statusLabel()` y `statusBadgeClass()` de `lib/order-status.ts` con los `orderStatuses` compartidos por el backend (fuente única `config/orders.php`). El selector de estado ofrece `all` + los 10 estados canónicos; el backend valida con `Rule::in(array_merge(['all'], config('orders.all')))` (`OrderReportRequest` / `ExportReportRequest`).

### 5.4 Tabla "Detalle de Pedidos"

#### Columnas (UI y PDF)

| # | Estado | Total (COP) | Fecha |
|---|---|---|---|
| `#{order.id}` | Badge con color | `formatCurrency(total)` | `formatDate(ordered_at)` |

- **Paginación**: 20/página default (configurable vía `?per_page=`).
- **Click en orden**: abre modal con detalle completo (items, cliente, dirección, descuento).
- **Sin filtro de búsqueda libre** (no hay buscador por #, teléfono o item — sólo período + estado).

### 5.5 Exportar PDF

#### Endpoint

```
POST /api/v1/exports/orders/pdf
Content-Type: application/json
Body:
{
  "filters": {
    "date_from": "2026-04-01",
    "date_to": "2026-04-30",
    "status": "completed"  // opcional; si falta, exporta todos
  }
}
```

Permission: `reports.read,read`. Controller: `PdfExportController::orders` → `PdfExportService::exportOrders`.

#### Implementación

```php
public function exportOrders(string $companyNit, array $filters): Response {
    $maxRows = config('pdf.max_rows', 500);

    $query = Order::where('company_nit', $companyNit)->orderByDesc('ordered_at');

    if (!empty($filters['date_from'])) {
        $query->where('ordered_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
    }
    if (!empty($filters['date_to'])) {
        $query->where('ordered_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
    }
    if (!empty($filters['status'])) {
        $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
        $query->whereIn('status', $statuses);
    }

    $total = $query->count();
    if ($total === 0) throw new PdfEmptyDataException;

    $limitApplied = $total > $maxRows;
    $orders = $query->limit($maxRows)->get();

    return $this->streamPdf('pdf.orders', [
        'orders' => $orders,
        'filters' => $filters,
        'totalRecords' => $total,
        'limitApplied' => $limitApplied,
        'maxRows' => $maxRows,
        // ... branding ...
    ], "pedidos_{$today}.pdf");
}
```

#### Cap de filas

- `pdf.max_rows` default 500. Si `count > 500`, el PDF muestra aviso: "⚠️ Limitado a primeras 500 filas; total real: 1 240. Usa CSV para descargar todo."
- **No bloquea**: el PDF se genera con las primeras 500. El usuario puede usar CSV si necesita todo.

#### Bug fix histórico (relevante para QA)

Antes (PR #85), el frontend `buildPdfFilters()` enviaba sólo `{period: 'weekly'}` al backend, pero `PdfExportService::exportOrders` sólo entiende `date_from/date_to/status` — `period` lo ignora. Resultado: para periods `daily/weekly/monthly` el filtro se descartaba y exportaba **todas** las órdenes históricas de la empresa.

**Fix actual:** `buildPdfFilters()` reusa `periodRange` (el rango ya resuelto por el backend para la consulta visible), garantizando que el PDF coincida exactamente con lo que ves.

### 5.6 Exportar CSV

```
POST /api/v1/exports/orders/csv
```

Permission: `reports.read,read`. Controller: `PdfExportController::ordersCsv` → `CsvExportService::exportOrders`.

**Diferencias vs PDF**:
- Sin cap de filas (`pdf.max_rows` no se aplica). Streaming chunked: `StreamedResponse` con `fputcsv` que escribe en `php://output`.
- Encoding UTF-8 con BOM (`EF BB BF`) prefijado para que Excel/LibreOffice abran sin problemas de tildes.
- Headers HTTP:
  ```
  Content-Type: text/csv; charset=UTF-8
  Content-Disposition: attachment; filename="pedidos_2026-05-06.csv"
  ```

#### Columnas del CSV

```
ID, Estado, Tipo, Mesa/Dirección, Cliente, Items, Total, Descuento, Cupón, Fecha
```

`Items` se serializa como `"item1 (x2), item2 (x1)"` (lista compacta). Tildes y caracteres especiales preservados.

### 5.7 Export asíncrono (legado: `POST /api/v1/reports/export`)

Existe un flujo asíncrono distinto en `OrderReportController::export` que dispara `GenerateReportPdf` job:

1. POST recibe filtros, genera token UUID.
2. Despacha `GenerateReportPdf::dispatch($nit, $from, $to, $status, $token)` a la cola.
3. Responde 202 con `{token, download_url, expires_in_minutes: 30}`.
4. El job ejecuta async, guarda HTML en disk, mete metadata en Cache con key `report_download:{token}` y TTL 30 min.
5. Cliente hace polling a `GET /api/v1/reports/download/{token}` hasta que disponible.
6. `OrderReportController::download` valida token contra cache, sirve archivo desde Storage::download(), elimina entrada de cache (one-shot).

**Estado actual**: este flujo coexiste con el síncrono `/api/v1/exports/orders/pdf`. La UI usa el síncrono. El asíncrono está disponible pero no expuesto activamente en el frontend.

### 5.bis Cierre de caja por fecha (`/api/v1/reports/cash-drawer`)

Componente `<CashDrawerCard>` en `/company/reports`. Modos:
- **Día específico** (default): selector de fecha único con flechas ◀/▶ para navegar día a día y atajos Hoy/Ayer. Etiqueta legible "Lunes, 7 de mayo de 2026".
- **Rango**: `Desde/Hasta` con quick-buttons "Hoy / Últimos 7 días / Últimos 30 días".

Filtra por `payment_receipts.paid_at` (no `orders.ordered_at`) en TZ `America/Bogota`. Tabla por método (cash/card/transfer/refund) con cobros, devoluciones, neto, propinas y conteo. Caja resaltada con **Efectivo esperado en caja física = cash_gross + cash_tips − cash_refunds** para conciliar contra el contado al cierre. Exportable a PDF.

### 5.ter Historial de sesiones de caja (`/api/v1/reports/cash-register/sessions`)

Componente `<CashSessionsCard>` en `/company/reports`. Listado paginado de turnos cerrados (+ el actual abierto si lo hay) con: estado, apertura (fecha + usuario), cierre (fecha + usuario), `opening_amount`, `expected_cash`, `closing_amount`, `cash_difference`. Diferencia coloreada para detectar sobrantes/faltantes recurrentes.

### 5.quater PDF de pedidos — desglose tributario y contable

`/api/v1/exports/orders/pdf` ahora incluye:
- **Columnas**: #, Estado, Medio de pago, Subtotal, Impuesto, Total, Fecha.
- **Resumen tributario**: subtotal base gravable + impuesto + total bruto + propinas (informativo).
- **Resumen por método de pago**: gross / refunds / net / count por `cash | card | transfer | refund`. Net usa `SUM(payment_receipts.amount)` signed.

`/api/v1/exports/metrics/pdf` agrega KPIs **Ingresos Brutos / Devoluciones / Ingresos Netos** y nota informativa de propinas recaudadas.

---

## 6.bis Órdenes — Mesas (`/orders/tables`) — Issue #89

Página: `resources/js/pages/orders/tables/index.tsx`. Breadcrumb: `Dashboard › Órdenes › Mesas`. Ruta web `orders.tables` con gate `orders.read`.

**Objetivo**: gestionar el servicio en sala con cuenta abierta. Permite ver mesas disponibles vs ocupadas, abrir una nueva mesa redirigiendo a `/orders/cashier?table=N`, agregar productos a la cuenta abierta sin cerrarla, y cerrar/cobrar al final.

### Vista grilla
- Cantidad de mesas configurable inline (input numérico). Persistido en `localStorage[tables.grid_size]`, default 12.
- Polling 8s a `GET /api/v1/orders/tables`.
- **Mesa Disponible** (verde): no tiene `Order` con `order_type='table'` y `status` en `pending|in_kitchen|ready`. Click → `/orders/cashier?table=N`.
- **Mesa Ocupada** (ámbar): muestra cantidad de ítems, total y estado operativo. Click → modal con detalle.

### Modal de detalle
- Lista todos los ítems con cantidades, notas y subtotal.
- Botón **"Agregar productos"** abre selector del menú activo y dispara `POST /api/v1/orders/{id}/items` (gate `orders.update`). El backend recalcula `total` desde precios del menú en DB; nunca confía en lo enviado por el cliente.
- Botón **"Cerrar y cobrar"** marca la orden como `completed` vía `PATCH /api/v1/orders/{id}/status`. Tras cerrar, la mesa vuelve a Disponible automáticamente.

### Mesas con sesión QR (grupal)
- **Mesa En sesión** (azul): click navega a `/orders/{order_id}` (detalle de orden/sesión) en vez del modal.
- `GET /api/v1/table-sessions/{id}` expone `items_by_status` con TODOS los items de la sesión (buffer + órdenes aprobadas, backend v1.30.1). Antes solo entraban los del buffer: al aprobarse desaparecían de los tabs y las transiciones del KDS (cocina/listo/servido) nunca se reflejaban en el detalle.
- El detalle de orden (`pages/orders/show.tsx`) tiene botón **"Agregar productos"** (frontend v1.39.7) para órdenes de mesa abiertas — tradicionales y operativas de sesión QR. Reutiliza `useAddItems` + `AddItemsSheet` contra el mismo `POST /api/v1/orders/{id}/items`; las filas nuevas nacen `status='approved'` y entran al pipeline de cocina. Permite que el mesero sume a la misma cuenta lo que el comensal pide en persona, sin pasar por el celular del cliente.
- El botón se oculta en órdenes terminales, en el buffer `pending_approval` (esos ítems entran por el flujo de aprobación del comensal) y con sesión cerrada. Gateado por `orders.update`.

### Versionamiento fv/bv (backend v1.30.2 / frontend v1.42.0)
- `GET /api/v1/bootstrap` expone `versions.backend` (composer.json leído en runtime, memoizado por worker PHP) — el footer `bv` refleja el backend realmente desplegado, no el horneado en el build del frontend (`__BACKEND_VERSION__` queda solo como fallback para backends viejos).
- `components/pwa-update-banner.tsx` (montado en `AppSidebarLayout`): escucha `pwa:update-available` y muestra barra fija "Nueva versión disponible — Actualizar" con reload. Cierra el gap de tabletas 24/7 que nunca rotaban de versión.
- `.github/workflows/version-guard.yml`: en push a `main`, falla si cambió código desplegable sin bump de version, y autosincroniza los badges del README (commit `[skip-badge-sync]`).

### Restricciones backend
- `appendItems` valida que la orden sea `order_type='table'` y no esté en estado terminal (`completed|successful|cancelled|abandoned`).
- Si el menú activo cambió, los ítems descontinuados no pueden agregarse (validación `available`).

### Fuera de alcance (futuro)
- Catálogo persistente de mesas con layout/posiciones (hoy `table_number` es texto libre).
- División de cuenta entre comensales.
- Transferir/fusionar mesas.

---

## 6. Órdenes — Tablero Kanban (`/orders/board`)

Página: `resources/js/pages/orders/board.tsx`. Breadcrumb: `Dashboard › Órdenes › Tablero`. Ruta: closure simple en `routes/web.php` con name `orders.board` (sin gate web — el gate efectivo está en la API).

**Código corto de orden (#275)**: las tarjetas, el detalle y las mesas QR muestran `Orden #019E7DA6-3C13` (dos primeros segmentos del UUID en mayúscula vía `lib/order-code.ts`, espejo de `Order::shortCode()`) en lugar del UUID completo, que queda accesible en el tooltip. Es una referencia visual, no una clave única.

**Notificación SMS al cliente (#275)**: al mover una orden con teléfono a `in_kitchen` / `ready` / `in_transit` / `completed` (o al cerrar con pago → `completed`), el cliente recibe **un** SMS (Amazon SNS) con nombre comercial + código corto + estado. Sin duplicados aún con N instancias EC2 (dedup por UNIQUE `(order_id, to_status)`, atómico). El SMS es **best-effort y desacoplado**: se registra y encola **fuera** de la transacción del cambio de estado/cobro, así un fallo del SMS nunca revierte el cambio en el tablero ni el cobro. Teléfono sin prefijo se asume `+57`. Cada SMS queda en el chat del cliente (`source='sms'`) visible en `/clients/{id}` y `/chats`, y se contabiliza por empresa/sede en `/company/reports` (card "SMS enviados"). **Aviso de fallo (Fase 4)**: si el envío async falla, el usuario que disparó la acción ve un toast informativo **una sola vez** (no se repite ni en otro dispositivo); los demás usuarios no lo ven. Detalle técnico en `BACKEND_FILES.md` (Pedidos › Notificaciones SMS).

**Coherencia KDS ↔ Tablero**: los estados del KDS (`order_items.status`) son solo de preparación/entrega y se reflejan únicamente en las columnas "En Cocina" / "Para Entrega". Además de la sincronización bidireccional base (`maybePromoteOrderStatus` item→orden y `syncItemsToOrderStatus` orden→items), tres caminos laterales mantienen la coherencia: (1) cancelar un plato (mesero/solicitud QR) re-evalúa la promoción — cancelar el último plato pendiente pasa la orden a "Para Entrega"; (2) agregar platos a una orden `ready` la regresa a `in_kitchen` (regresión operativa auditada, excepción al forward-only); (3) rechazar un domicilio cancela la orden y cierra sus items abiertos en bloque; (4) asignar repartidor (→`in_transit`) y completar la entrega (→`completed`) sirven los items abiertos (`OrderItem::serveOpenItems`) para que no queden tickets fantasma en el KDS; (5) un refund total cancela los items aún abiertos con `cancellation_reason=refunded` (los `served` quedan intactos — la venta ocurrió y el asiento negativo la compensa).

### 6.0 Estados de orden (modelo canónico)

`orders.status` acepta 9 valores. **Fuente única de verdad: `config/orders.php`** (compartido al frontend vía `HandleInertiaRequests::share()` como prop `orderStatuses`). Frontend usa `useOrderStatuses()` y helpers de `lib/order-status.ts`.

| Estado | Categoría | Kanban | Revenue | Cuándo se asigna |
|---|---|---|---|---|
| `pending` | Operativo | ✓ | — | Recién creada (caja/bot) |
| `in_kitchen` | Operativo | ✓ | — | Confirmada por cocina |
| `ready` | Operativo | ✓ | — | Lista para entrega |
| `in_transit` | Operativo | ✓ | — | Asignada a repartidor (antes `in_delivery`) |
| `completed` | Terminal éxito | ✓ | ✓ | Entrega/cobro confirmado (antes también `successful`) |
| `failed` | Terminal falla | — | — | Entrega fallida sin devolución |
| `cancelled` | Terminal falla | — | — | Anulada antes de pago |
| `refunded` | Terminal falla | — | — | Cobrada y luego devuelta |
| `abandoned` | Terminal falla | — | — | Carrito nunca confirmado |

**Migración** `2026_05_07_192524_migrate_order_statuses_to_canonical_set` unificó `successful → completed` y renombró `in_delivery → in_transit`.

### 6.1 Columnas (5 estados operativos)

Definidas en el array `ESTADOS` de `frontend/src/pages/orders/board.tsx` (colores con tokens del DS, `rank` espejo de `config('orders.kanban_rank')` — mantener en sincronía):

| key | label | rank |
|---|---|---|
| `pending` | Pendiente | 1 |
| `in_kitchen` | En cocina | 2 |
| `ready` | Para entrega | 3 |
| `in_transit` | En tránsito | 4 |
| `completed` | Completado | 5 |

(Snippet anterior con `in_delivery` era legacy — el rename `in_delivery → in_transit` vive en la migración `2026_05_07_192524`. Otras secciones de este documento aún muestran payloads legacy con `in_delivery`; el código no lo usa.)

### 6.2 Hook `useOrders` (polling + estado)

```ts
const { orders, loading, error, lastUpdated, refresh, updateStatus } = useOrders(token);
```

#### Implementación clave

```ts
const POLL_INTERVAL_MS = 5_000;

useEffect(() => {
    void fetchOrders();
    const interval = setInterval(() => void fetchOrders(), POLL_INTERVAL_MS);
    return () => clearInterval(interval);
}, [fetchOrders]);
```

- Polling **5s siempre activo** (no hay toggle live aquí — es operación crítica).
- Indicador `LiveIndicator` muestra punto verde pulsante con `lastUpdated` como tooltip.
- En 401 con `revoc`/`invalid` → `apiFetch` limpia token y redirige a `/`.

#### `updateStatus` con optimistic update

```ts
const updateStatus = async (orderId, status) => {
    setOrders(prev => prev.map(o => o.id === orderId ? { ...o, status } : o));  // optimista
    const res = await apiFetch(`/api/v1/orders/${orderId}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
    });
    if (!res.ok) {
        await fetchOrders();  // revierte refetcheando
        throw new Error(...);
    }
};
```

### 6.3 Tarjeta de orden (componente `OrderCard`)

Cada tarjeta muestra:
- **Header**: `Orden #{id}` + tiempo relativo (`Hace X min/h`).
- **Tipo de orden** con icono (función `inferOrderType`):
  ```ts
  function inferOrderType(order) {
    if (order.order_type) return order.order_type;
    if (order.table_number) return 'table';
    if (order.delivery_address || order.delivery) return 'delivery';
    return null;
  }
  ```
- **Mapeo de iconos** (`ORDER_TYPE_META`):
  | order_type | label | icon | className |
  |---|---|---|---|
  | `table` | Mesa | `Utensils` | `bg-amber-100 text-amber-800` |
  | `delivery` | Domicilio | `Bike` | `bg-purple-100 text-purple-800` |
  | `pickup` | Para llevar | `ShoppingBag` | `bg-sky-100 text-sky-800` |
- **Teléfono cliente** (sin formato, sólo `client_phone`).
- **Total** con `formatCurrency` (es-CO, COP, sin decimales).
- **Cantidad de ítems**: `{count} ítem` o `{count} ítems` (singular/plural).
- **Repartidor**: si `delivery.deliverer.name` está presente, muestra ícono `Truck` + nombre.
- **Botón "Asignar repartidor"**: visible sólo si `status === 'ready'` y `!order.delivery?.deliverer`.

### 6.4 Drag-and-drop con dnd-kit

#### Setup

```tsx
const sensors = useSensors(
    useSensor(PointerSensor, {
        activationConstraint: { distance: 8 },  // 8px antes de activar drag (evita drags accidentales)
    }),
);
```

#### Animaciones

- **`useDragSway`** custom hook: balanceo de la tarjeta arrastrada simulando física (stiffness 0.18, damping 0.62). El ángulo se calcula con la velocidad del puntero y se decae hacia 0 cuando se suelta.
- **`animate-drop-bounce`**: clase Tailwind custom que aplica un bounce a la tarjeta soltada por 650ms.
- **`DragOverlay`**: ghost de la tarjeta que sigue el cursor con tooltip "El cambio de estado en la orden será notificado al cliente".

#### Handler de drop

```tsx
const handleDragEnd = (event) => {
    const { active, over } = event;
    if (!over) return;
    const newStatus = String(over.id);
    const order = orders.find(o => o.id === active.id);
    if (!order || order.status === newStatus) return;
    if (!ESTADOS.some(e => e.key === newStatus)) return;

    setDroppedOrderId(orderId);  // dispara animate-drop-bounce
    setTimeout(() => setDroppedOrderId(null), 650);

    void updateStatus(orderId, newStatus);
};
```

#### Mobile (`<768px`)

`useIsMobile()` retorna `true` si viewport < 768px. En mobile **no hay drag**: en su lugar, `<Select>` con dropdown de columnas. El operador cambia estado tocando un `<Button>` adicional (no implementado actualmente — sólo viewing).

### 6.5 Modal de detalle (`OrderDetailModal`)

Componente `resources/js/components/orders/order-detail-modal.tsx`. Se abre al hacer click en una tarjeta.

#### Contenido

- Items completos: nombre, precio, cantidad, notas (si las hay).
- Cliente: `client_phone`, `delivery_address` o `table_number`.
- Total, `discount_amount` si aplica, `coupon_code`.
- Timestamp `ordered_at` formateado.

#### Botones según estado

| Estado actual | Botones disponibles |
|---|---|
| `pending` | (ninguno extra) |
| `in_kitchen` | (ninguno extra) |
| `ready` | "Asignar repartidor" → abre `AssignCourierModal` |
| `in_delivery` | "Reasignar repartidor" → abre `ReassignModal` |
| `completed` | (ninguno) |

**Botón "Ir al chat"**: visible cuando `order.chat_id != null` (resuelto via batch lookup en backend `OrderController::index` líneas 44-49: busca `Chat::forCompany()->whereIn('client_phone', $phones)->pluck('id', 'client_phone')`). Lleva a `/chats?open={chat_id}`.

### 6.6 Endpoint `GET /api/v1/orders` (kanban mode)

Controller: `OrderController::index`. Permission: `orders.read,read`. Multi-tenant: `Order::forCompany($companyNit)`.

#### Query

```php
$orders = Order::forCompany($companyNit)
    ->whereIn('status', ['pending', 'in_kitchen', 'ready', 'in_delivery', 'completed'])
    ->whereDate('ordered_at', now()->toDateString())  // ← filtro hard-coded a HOY
    ->with(['delivery.deliverer:id,name'])
    ->orderBy('ordered_at')
    ->get();
```

**Filtro hard-coded a hoy:** intencional. El kanban es operación del día corriente. Para histórico se usa el modo cursor (`?paginate=cursor`) o `/company/reports`.

#### Lookup batch de chats

```php
$phones = $orders->pluck('client_phone')->filter()->unique()->values()->all();
$chatByPhone = empty($phones) ? collect() : Chat::forCompany($companyNit)
    ->whereIn('client_phone', $phones)
    ->pluck('id', 'client_phone');
```

Una sola query para todos los `client_phone` distintos → `chat_id` se rellena en cada order del response. Evita N+1.

#### Response shape

```json
{
  "data": [
    {
      "id": 12345,
      "status": "in_kitchen",
      "order_type": "delivery",
      "table_number": null,
      "delivery_address": "Cra 43A #18-95",
      "client_phone": "573001112233",
      "items": [
        { "id": "spaghetti-carbonara", "name": "Spaghetti carbonara", "price": 32900, "quantity": 1, "notes": null }
      ],
      "total": 32900,
      "discount_amount": 0,
      "coupon_code": null,
      "ordered_at": "2026-05-06T19:32:00-05:00",
      "chat_id": 42,
      "delivery": null
    }
  ]
}
```

### 6.7 Endpoint `PATCH /api/v1/orders/{id}/status`

Controller: `OrderController::updateStatus`. Permission: `orders.update,update`.

#### Validación

```php
$validated = $request->validate([
    'status' => ['required', Rule::in(['pending', 'in_kitchen', 'ready', 'in_delivery', 'completed'])],
]);
```

**Sólo acepta los 5 estados operativos.** Para `successful/cancelled/abandoned` no hay endpoint público — esos los setea el bot externo o jobs internos.

#### Multi-tenant scope

```php
$order = Order::forCompany($companyNit)->findOrFail($id);
```

Si la orden pertenece a otra empresa → 404 (no 403). Esto evita filtrar info ("la orden existe pero no es tuya"). En su lugar: "la orden no existe (para ti)".

#### Sin transición de estados estricta

**No hay máquina de estados validada en el backend.** Permite saltos arbitrarios entre los 5 estados (ej. `pending → completed` directo). En la UI esto sería extraño (saltarse cocina), pero el backend no lo bloquea — depende de la disciplina del operador.

#### Audit log

`order.status_changed` con `{from, to, order_id}`.

### 6.8 Cálculo del `total` (server-side, no confiable cliente)

`OrderController::store` línea 144 calcula:

```php
$total = 0.0;
foreach ($validated['items'] as $line) {
    $catalogItem = $catalog->get($line['id']);  // ← del menú activo, no del request
    if (!$catalogItem || !($catalogItem['available'] ?? true)) {
        throw ValidationException::withMessages(['items' => 'Uno o más ítems no están disponibles en el menú activo.']);
    }
    $price = (float) ($catalogItem['price'] ?? 0);  // ← precio del menú, no del request
    $quantity = (int) $line['quantity'];
    $total += $price * $quantity;
}
```

**Frontend NO puede inyectar precios.** Aunque mande `{id: 'x', price: 999, quantity: 1}`, el backend lee el precio de `RestaurantMenu.structure.categories[].items[]` por `id` y lo aplica. Cumple regla `feedback_cart_price_from_db`.

### 6.9 RBAC y multi-tenancy completos

| Endpoint | Middleware | assertPermission interno | Scope |
|---|---|---|---|
| `GET /api/v1/orders` | `permission:orders.read,read` | sí | `Order::forCompany($nit)` |
| `POST /api/v1/orders` | `permission:orders.create,create` | sí | mismo |
| `GET /api/v1/orders/{id}` | `permission:orders.read,read` | sí | mismo |
| `PATCH /api/v1/orders/{id}/status` | `permission:orders.update,update` | sí | mismo |
| `POST /api/v1/orders/{id}/assign-courier` | `permission:deliveries.create,create` | sí (DeliveryController) | `Order::forCompany`, `User::find` |
| `POST /api/v1/deliveries/{id}/reassign` | `permission:deliveries.update,update` | sí (DeliveryStatusController) | `Delivery::forCompany`, `Delivery::isPending()` |
| `GET /api/v1/orders/{id}/available-deliverers` | `permission:deliveries.read,read` | sí | mismo |

**Doble validación**: middleware `permission:` + `assertPermission()` en cada controller. Defensa en profundidad — si alguien removiera el middleware de la ruta por error, el assert lo seguiría protegiendo.

---

## 7. Caja / POS (`/orders/cashier`)

Página: `resources/js/pages/caja/index.tsx`. Breadcrumb: `Dashboard › Órdenes › Caja`. Punto de venta para crear pedidos manuales (cuando un cliente pide en mesa o por teléfono y un operador captura la orden).

### 7.0 Gate web

```php
Route::get('orders/cashier', function (FeaturePermissionService $featurePermission) {
    // ...
    if (!$featurePermission->hasPermission($synthetic, 'orders', 'read')) {
        return redirect()->route('dashboard')->with('jwt_token', $token);
    }
    return Inertia::render('caja/index', [...]);
})->name('orders.cashier');
```

Sin permiso `orders.read` → redirect. Crear pedido requiere además `orders.create`.

### 7.1 Tipos de orden y campos por tipo

| order_type | Campo extra requerido | Campo extra opcional |
|---|---|---|
| `table` | `table_number` (string max:20) | `client_phone` |
| `delivery` | `delivery_address` (string max:500) + `client_phone` | — |
| `pickup` | `client_phone` | — |

`order_type` se selecciona con 3 botones grandes: **En sitio / Domicilio / Para llevar**.

### 7.2 Selección de items

#### Carga del menú activo

```ts
const res = await apiFetch('/api/v1/menus');
const data = await res.json();
const active = data.data.find(m =>
  m.status === 'active'
  && (!m.active_days?.length || m.active_days.includes(new Date().getDay()))
);
```

- Sólo se muestran items con `available: true`.
- Items agrupados por categoría (acordeón colapsable).
- Botón `+` por item agrega al carrito local; botón `−` resta.
- Cantidad y notas opcionales por línea.

### 7.3 Endpoint `POST /api/v1/orders` (creación de orden)

Controller: `OrderController::store`. Permission: `orders.create,create`.

#### FormRequest validation (inline en controlador)

```php
$validated = $request->validate([
    'order_type'         => ['required', Rule::in(['table', 'delivery', 'pickup'])],
    'client_phone'       => ['nullable', 'string', 'max:32'],
    'table_number'       => ['required_if:order_type,table', 'nullable', 'string', 'max:20'],
    'delivery_address'   => ['required_if:order_type,delivery', 'nullable', 'string', 'max:500'],
    'items'              => ['required', 'array', 'min:1'],
    'items.*.id'         => ['required', 'string'],
    'items.*.quantity'   => ['required', 'integer', 'min:1'],
    'items.*.notes'      => ['nullable', 'string', 'max:500'],
]);
```

#### Validaciones adicionales

```php
$menu = RestaurantMenu::forCompany($nit)->active()->first();
if (!$menu) throw ValidationException::withMessages(['menu' => 'No hay un menú activo para la empresa.']);

$today = (int) now()->format('w');  // 0=domingo, 6=sábado
$activeDays = $menu->active_days ?? [];
if (!empty($activeDays) && !in_array($today, $activeDays, true)) {
    throw ValidationException::withMessages(['menu' => 'El menú activo no aplica para hoy.']);
}
```

#### Creación de la orden

```php
$order = Order::create([
    'company_nit'      => $nit,
    'session_id'       => 'caja-' . Str::uuid(),
    'client_phone'     => $validated['client_phone'] ?? null,
    'order_type'       => $validated['order_type'],
    'table_number'     => $validated['order_type'] === 'table' ? $validated['table_number'] : null,
    'delivery_address' => $validated['order_type'] === 'delivery' ? $validated['delivery_address'] : null,
    'items'            => $items,           // ← snapshot inmutable con precio del menú
    'status'           => 'pending',
    'total'            => $total,           // ← calculado server-side
    'cost'             => 0,                // ← hard-coded; no implementado
    'discount_amount'  => 0,
    'coupon_code'      => null,
    'ordered_at'       => now(),
]);
```

`session_id` con prefijo `caja-` distingue las órdenes creadas en la POS de las del bot/carrito (que tienen `session_id` derivado de un Cart JWT).

### 7.4 Tabla de errores con códigos de aplicación

| Caso | Status | Body shape | Mensaje |
|---|---|---|---|
| Items vacío | 422 | `{message, errors:{items:[...]}}` | "El campo items es obligatorio y debe tener al menos 1." |
| Tipo `table` sin `table_number` | 422 | mismo | "El campo table_number es obligatorio cuando order_type es table." |
| Tipo `delivery` sin `delivery_address` | 422 | mismo | "El campo delivery_address es obligatorio cuando order_type es delivery." |
| Item no existe en menú activo | 422 | `{message, errors:{items:[...]}}` | "Uno o más ítems no están disponibles en el menú activo." |
| Item existe pero `available=false` | 422 | mismo | mismo mensaje (genérico para no exponer state) |
| No hay menú activo | 422 | `{message, errors:{menu:[...]}}` | "No hay un menú activo para la empresa." |
| `RestaurantMenu.active_days` no incluye hoy | 422 | mismo | "El menú activo no aplica para hoy." |
| Sin `orders.create` | 403 | `{message: "Acceso denegado"}` | RBAC |
| Sin auth | 401 | mismo | JWT inválido o expirado |

### 7.5 Aliases de back-compat

```php
Route::get('caja', fn() => redirect('/orders/cashier?' . request()->getQueryString()));
```

`/caja → /orders/cashier (302)` preservando `?token=` y `?return_to=`. Implementación en `routes/web.php`.

---

## 7. Caja / POS (`/orders/cashier`)

Punto de venta para crear pedidos manualmente. Breadcrumb: `Dashboard › Órdenes › Caja`. Permiso: `orders.read` (gate web), `orders.create` para enviar.

### 7.1 Tipos de orden

| Tipo | Campo requerido |
|------|------------------|
| **En sitio** (`table`) | Número de mesa |
| **Domicilio** (`delivery`) | Dirección |
| **Para llevar** (`pickup`) | (ninguno) |

### 7.2 Selección de items

- Lista del menú activo del día actual.
- Sólo ítems con `available=true` se pueden agregar.
- Cantidad por ítem y notas opcionales.
- Total recalculado server-side.

### 7.3 Validaciones

| Caso | Respuesta |
|------|-----------|
| Items vacío | 422 |
| Tipo `table` sin `table_number` | 422 |
| Tipo `delivery` sin `delivery_address` | 422 |
| Item no existe en menú activo | 422 |
| Item no disponible | 422 |
| No hay menú activo | 422 (`'menu' => 'No hay un menú activo para la empresa.'`) |
| Menú activo no aplica para hoy | 422 |

### 7.4 Back-compat

- URL antigua `/caja` redirige (302) a `/orders/cashier` preservando `?token=`.

### 7.5 Sesión de caja (turno) — apertura y cierre

La caja **debe estar abierta** para poder operar. Una sola sesión `open` por empresa a la vez (UNIQUE parcial en BD); cualquier usuario con permiso ve y opera la misma sesión. Polling de 10s mantiene el estado sincronizado entre cajeros.

**Apertura** (`POST /api/v1/cash-register/open`, gate `orders.create`):
- Pantalla "Caja cerrada" reemplaza la UI de caja cuando no hay sesión activa.
- Input "Efectivo inicial en caja" (puede ser 0) + notas opcionales.
- Persiste `opened_by_user_id`, `opened_at`, `opening_amount`, `opening_notes`.
- Audit log: `cash_register.opened`.

**Operación**: `OrderController` (store, appendItems, closeWithPayment, refund) y `MenuController::showPublic` lanzan error si no hay sesión activa. El menú público responde `423 cash_register_closed`, así los clientes (bot/cart) no pueden ordenar mientras la caja está cerrada.

**Cierre** (`POST /api/v1/cash-register/close`, gate `orders.update`):
- Modal con resumen del turno: `Inicial + Cobros cash + Propinas cash − Devoluciones cash = Esperado en caja`.
- Input "Efectivo contado" con diferencia proyectada en vivo (verde=cuadre, ámbar=sobrante, rojo=faltante).
- Backend calcula y persiste `expected_cash` y `cash_difference = closing − expected`. Status pasa a `closed`.
- Audit log: `cash_register.closed`.

**Inmutabilidad**: una sesión cerrada no puede reabrirse ni modificarse. Para corregir errores se abre una nueva con notas explicativas.

**Banner global**: si `should_alert=true` (caja cerrada + menú activo + horario hábil), un banner ámbar aparece en TODA la app autenticada con CTA "Abrir caja". Auto-resuelve al detectar apertura.

**Egresos de caja** (`POST /api/v1/cash-register/expenses`, gate `orders.update`): registro de pagos a domiciliarios, propinas distribuidas, proveedores e imprevistos. Categorías cerradas en `config/cash_register.php`: `domiciliario_pago`, `proveedor`, `imprevisto`, `propina_distribuida`, `otro`. Método: `cash | card | transfer` (los egresos cash reducen `expected_cash`). Tabla `cash_register_expenses` es **append-only** (sin updated_at, sin endpoints PUT/DELETE): para corregir un egreso erróneo se registra otro nuevo en sentido contrario con descripción explícita. Modal accesible desde el banner de caja activa ("+ Egreso"). El modal de cierre desglosa los egresos por categoría. Audit log: `cash.expense.recorded`. Listado por sesión vía `GET /api/v1/cash-register/sessions/{id}/expenses` (gate `reports.read`).

### 7.6 Cobro con método de pago (al cerrar mesa)

`POST /api/v1/orders/{id}/close-with-payment` recibe `{ payment_method: cash|card|transfer, amount_received?, reference?, tip_amount? }`:
- Para `cash`: valida `amount_received >= total + tip`. Calcula `change_returned`.
- Para `card`: pide número de comprobante (referencia del datáfono).
- Para `transfer`: muestra QR de la empresa al cliente. Pide número de comprobante.
- Persiste `PaymentReceipt` con `payment_method`, `amount` (signed), `reference`, `paid_at`, `cash_session_id`.
- `orders.tip_amount` se persiste **separado** del total (NO suma a base gravable, NO genera IVA, NO entra a revenue).

### 7.7 Cupones aplicados en caja

Input de cupón con validación en tiempo real. Política contable DIAN-friendly: el descuento **reduce la base gravable** (`subtotal` y `tax_amount` se redistribuyen proporcionalmente para mantener `total = subtotal + tax_amount` post-descuento). `discount_amount` y `coupon_code` se persisten en la orden; `CouponService::redeemCoupon` registra la redención atómicamente.

### 7.8 Cancelación y devolución (desde Pedidos del día)

- **Cancelar pedido**: `POST /api/v1/orders/{id}/cancel`. Solo órdenes sin pago registrado. Estado → `cancelled`.
- **Devolver**: `POST /api/v1/orders/{id}/refund` con `amount` opcional. Si se omite, devuelve el remanente completo. Para `card`/`transfer` exige número de comprobante de la devolución hecha al cliente.
  - **Refunds parciales**: múltiples por orden hasta agotar `order.total`. Cada parcial crea un `PaymentReceipt` con `amount` negativo. Status pasa a `refunded` SOLO cuando el remanente llega a 0.
  - Atomicidad con `lockForUpdate` previene race conditions con refunds concurrentes.

### 7.9 Impresión de recibos térmicos (ESC/POS) — Issue #105

Tras confirmar un cobro la app puede imprimir el recibo de venta en una térmica (58mm/80mm).

- **Endpoint**: `GET /api/v1/orders/{id}/receipt-escpos` (perm `orders.read`). Devuelve binario ESC/POS (`Content-Type: application/octet-stream`).
- **Query params**: `?width=58|80` (override del setting), `?copy=true` (re-impresión marcada como `*** COPIA ***`).
- **Pipeline backend**: `ReceiptPrintController` → `ReceiptPrintingService` → `EscposBuilder` (CP850, alineación, doble alto, corte). Solo lectura: NO crea `PaymentReceipt`s (los recibos contables son inmutables; ver CLAUDE.md). El neto por método se calcula como `SUM(amount) GROUP BY payment_method` desde los receipts existentes.
- **Frontend**: `<PrintReceiptButton orderId={...} />` (`components/printing/print-receipt-button.tsx`). Pide el binario y lo envía a una impresora térmica vía WebUSB (Chromium/Edge sobre HTTPS). Sin WebUSB → fallback descarga `.bin` para entregar a un agente LAN. Vendors soportados: Epson (0x04b8), Star (0x0519), Xprinter/STM (0x0483, 0x1a86), Bixolon clones (0x0fe6).
- **Validaciones**: 404 si la orden no pertenece a la empresa activa, 409 si no tiene `payment_receipts`.
- **Settings (company_settings)**: `printing.receipt_width` (58|80, default 58), `printing.header_lines` (json[]), `printing.footer_message` (default "¡Gracias por tu visita!"), `printing.show_qr_menu` (bool), `printing.copies` (int).

---

## 7.bis Mesas — Sesión por mesa (`/orders/tables`)

Submenú dentro de Órdenes. Grid de mesas (cantidad configurable, persiste en `localStorage[tables.grid_size]`, default 12). Cada mesa muestra:
- **Disponible** (verde): sin orden `order_type=table` activa. Click → `/orders/cashier?table=N` para abrir nueva orden.
- **Ocupada** (ámbar): muestra cantidad de ítems, total y estado. Click → modal con detalle.

Modal de detalle muestra ítems con notas, desglose tributario (Subtotal base gravable / Impuesto / Total), botones:
- **Agregar productos**: modal con menú activo. Preview tributario por línea (item.tax_rate ?? snapshot_default_tax_rate). Backend recalcula al persistir.
- **Cerrar y cobrar**: modal de pago con método (cash/card/transfer), input propina con sugerencias 10/15/20%, devuelta calculada contra total + tip.

Misma envoltura `<CashRegisterPanel>` que caja: si la caja está cerrada, no se permite operar. Polling de mesas cada 8s.

---

## 8. Domicilios (`/orders/deliveries` y `/deliveries/metrics`)

Página principal: `resources/js/pages/deliveries/index.tsx`. Métricas: `pages/deliveries/metrics.tsx`. Hook principal: `useDeliveryList`. Servicio backend: `App\Services\DeliveryService`.

### 8.0 Tabla `deliveries` y modelo

```sql
deliveries (
  id, company_nit, order_id, user_id,
  assigned_at, delivered_at, duration_minutes,
  status,                          -- 'pending' | 'completed' | 'cancelled'
  previous_delivery_id,            -- nullable, FK a la entrega anterior si fue reasignada
  reason,                          -- motivo de asignación o reasignación
  cancellation_reason,             -- nullable
  created_by,                      -- user_id del operador que asignó
  deleted_at,                      -- soft delete
  created_at, updated_at
)
```

#### Índice único parcial (constraint crítica)

```sql
CREATE UNIQUE INDEX deliveries_unique_pending_per_order
  ON deliveries (order_id, company_nit)
  WHERE status = 'pending' AND deleted_at IS NULL;
```

Migración: `2026_05_03_170000_relax_deliveries_unique_to_pending`. Permite que una orden tenga múltiples deliveries históricas (reasignaciones) pero **sólo una activa pending** a la vez. Antes la constraint era `(order_id, company_nit) UNIQUE` sin filtro, lo que bloqueaba reasignaciones.

#### Estados de delivery

| status | Significado | `assigned_at` | `delivered_at` | `duration_minutes` |
|---|---|---|---|---|
| `pending` | Asignada, repartidor en camino | requerido | `null` | `null` |
| `completed` | Entregada al cliente | requerido | requerido | calculado |
| `cancelled` | Cancelada por operador | requerido | `null` | `null` |

#### Cálculo de `duration_minutes`

```php
public function markAsDelivered(): self {
    $this->delivered_at = now();
    $this->duration_minutes = $this->assigned_at->diffInMinutes(now());
    $this->status = 'completed';
    $this->save();

    // Cascade: si la orden estaba in_delivery, pasarla a completed
    if ($this->order->status === 'in_delivery') {
        $this->order->update(['status' => 'completed']);
    }
    return $this;
}
```

### 8.1 Listado de entregas (`GET /api/v1/deliveries`)

Permission: `deliveries.read,read`. Hook: `useDeliveryList`.

#### Query params

```
status:    pending | completed | cancelled (optional, sin = todos)
user_id:   int (filtra por repartidor específico)
date_from: YYYY-MM-DD
date_to:   YYYY-MM-DD
page:      int
per_page:  int (default 20, max 100)
```

#### Response

```json
{
  "data": [
    {
      "id": 555,
      "order_id": 12345,
      "user_id": 26,
      "deliverer": { "id": 26, "name": "Cristian Marín" },
      "status": "pending",
      "assigned_at": "2026-05-06T19:42:00Z",
      "delivered_at": null,
      "duration_minutes": null,
      "reason": "Asignación automática por cercanía",
      "created_at": "2026-05-06T19:42:00Z",
      "order": {
        "id": 12345,
        "status": "in_delivery",
        "delivery_address": "Cra 43A #18-95",
        "client_phone": "573001112233",
        "total": 47000
      }
    }
  ],
  "pagination": { "current_page": 1, "last_page": 12, "per_page": 20, "total": 230 }
}
```

#### Live toggle

```ts
const { enabled, toggle } = useLivePolling({
  intervalMs: 30_000,
  autoOffMs: 5 * 60_000,
  onTick: () => fetchDeliveries(),
});
```

30s polling cuando activo, auto-off 5 min.

### 8.2 Asignar repartidor a orden (`POST /api/v1/orders/{orderId}/assign-courier`)

Permission: `deliveries.create,create`. Controller: `DeliveryController::assignCourier`.

#### FormRequest

```php
'user_id' => ['required', 'integer', 'exists:users,id'],
'reason'  => ['nullable', 'string', 'max:200'],
```

#### Validaciones de negocio

```php
// 1. Orden existe y pertenece a la empresa
$order = Order::forCompany($nit)->findOrFail($orderId);

// 2. Orden está en estado válido para asignación
if (!in_array($order->status, ['ready', 'in_delivery'])) {
    return response()->json(['message' => 'La orden no está lista para asignación.'], 422);
}

// 3. No tiene una delivery pending ya activa (por la constraint UNIQUE parcial)
if ($order->delivery()->whereIn('status', ['pending'])->exists()) {
    return response()->json(['message' => 'La orden ya tiene una entrega activa.'], 409);
}

// 4. Repartidor pertenece a la empresa y rol válido
$deliverer = User::findOrFail($request->user_id);
if (!$deliverer->hasMembershipIn($nit) || !$deliverer->roleIn($nit)?->name === 'Domiciliario') {
    return response()->json(['message' => 'Usuario no es repartidor en esta empresa.'], 403);
}

// 5. Repartidor no excede DELIVERY_MAX_ACTIVE_PER_COURIER concurrentes
$active = Delivery::where('user_id', $deliverer->id)
    ->where('status', 'pending')
    ->count();
if ($active >= config('delivery.max_active_per_courier', 3)) {
    return response()->json(['message' => 'El repartidor tiene el máximo de entregas activas.'], 422);
}
```

#### Side effects

1. Crea `Delivery` con `status='pending'`, `assigned_at=now()`.
2. Cambia `Order.status` a `in_delivery` (cascade del operador).
3. Audit log: `delivery.assigned` con `{order_id, courier_id, reason}`.
4. **Notificación opcional** (`DELIVERY_NOTIFY_ON_ASSIGNMENT=true`): `DeliveryNotificationService::notifyClient()` envía mensaje WhatsApp al cliente con info del repartidor. Si falla (sin WhatsApp conectado, error en API), **no bloquea** la respuesta — sólo logea warning.

### 8.3 Reasignar entrega (`POST /api/v1/deliveries/{id}/reassign`)

Permission: `deliveries.update,update`. Controller: `DeliveryStatusController::reassign`.

#### FormRequest

```php
'user_id' => ['required', 'integer', 'exists:users,id'],
'reason'  => ['required', 'string', 'max:300'],  // ← obligatorio, no nullable
```

`reason` puede ser libre o uno predefinido (consultados via `GET /api/v1/deliveries/reassign-reasons`):

```json
{
  "data": [
    "Vehículo con problemas",
    "Cliente solicitó cambio",
    "Repartidor no respondió",
    "Zona fuera de cobertura del repartidor",
    "Otra razón (especificar)"
  ]
}
```

#### Validaciones

```php
$delivery = Delivery::forCompany($nit)->with('order')->findOrFail($id);

if (!$delivery->isPending()) {  // status != 'pending'
    return response()->json(['message' => 'Solo se pueden reasignar entregas pendientes.'], 422);
}

if ($delivery->order?->status === 'completed') {
    return response()->json(['message' => 'Orden completada, no editable.'], 409);
}

if ($delivery->user_id === $request->user_id) {
    return response()->json(['message' => 'El repartidor seleccionado es el mismo.'], 422);
}
```

#### Algoritmo de reasignación

En transacción:

1. Cancela la delivery anterior:
   ```php
   $delivery->update([
       'status' => 'cancelled',
       'cancellation_reason' => "Reasignada: {$reason}",
   ]);
   ```
2. Crea delivery nueva referenciando la anterior:
   ```php
   Delivery::create([
       'company_nit' => $nit,
       'order_id' => $delivery->order_id,
       'user_id' => $newCourierId,
       'status' => 'pending',
       'assigned_at' => now(),
       'reason' => $reason,
       'previous_delivery_id' => $delivery->id,  // ← cadena de reasignaciones
       'created_by' => $actorId,
   ]);
   ```
3. Audit log: `delivery.reassigned` con `{order_id, from_courier, to_courier, reason}`.
4. Notif WhatsApp al cliente (opcional): "Tu pedido ahora lo lleva {nuevoRepartidor}".

### 8.4 Marcar entrega como completada (`PATCH /api/v1/deliveries/{id}/complete`)

Permission: `deliveries.update,update`. Controller: `DeliveryController::complete`.

#### FormRequest (vacío — sólo el `id` por URL)

```php
// Sin body
```

#### Algoritmo

```php
$delivery = Delivery::forCompany($nit)->findOrFail($id);

if ($delivery->status !== 'pending') {
    return response()->json(['message' => 'Solo entregas pendientes pueden completarse.'], 422);
}

$delivery->markAsDelivered();  // setea delivered_at + duration_minutes + status

// Cascade: si la orden está in_delivery, pasarla a completed
if ($delivery->order?->status === 'in_delivery') {
    $delivery->order->update(['status' => 'completed']);
}
```

Audit logs: `delivery.completed` + `order.status_changed`.

Notif WhatsApp al cliente (opcional, `DELIVERY_NOTIFY_ON_COMPLETION=true`).

### 8.5 Cancelar entrega (`DELETE /api/v1/deliveries/{id}`)

Permission: `deliveries.delete,delete`. **Soft delete** (registro conservado para auditoría).

```php
$delivery = Delivery::forCompany($nit)->findOrFail($id);
$delivery->update([
    'status' => 'cancelled',
    'cancellation_reason' => $request->input('reason', 'Cancelado por operador'),
]);
$delivery->delete();  // soft delete (deleted_at = now)

// Audit log
audit('delivery.cancelled', $actor, $delivery, ['reason']);
```

La constraint UNIQUE parcial permite asignar una nueva entrega a la misma orden después (porque `deleted_at IS NULL` excluye los soft-deleted).

### 8.6 Métricas de repartidores (`/deliveries/metrics`)

Página: `resources/js/pages/deliveries/metrics.tsx`. Hook: `useDeliveryMetrics`. Endpoint: `GET /api/v1/deliveries/metrics?period=today|week|month`.

#### Query

```sql
SELECT
  u.id, u.name,
  COUNT(d.id) AS total,
  COUNT(d.id) FILTER (WHERE d.status = 'completed') AS completed,
  COUNT(d.id) FILTER (WHERE d.status = 'cancelled') AS cancelled,
  AVG(d.duration_minutes) FILTER (WHERE d.delivered_at IS NOT NULL) AS avg_minutes,
  COUNT(d.id) FILTER (WHERE d.duration_minutes <= 45) * 1.0 / NULLIF(COUNT(d.id) FILTER (WHERE d.delivered_at IS NOT NULL), 0) AS on_time_rate
FROM deliveries d
INNER JOIN users u ON u.id = d.user_id
WHERE d.company_nit = ? AND d.assigned_at BETWEEN ? AND ?
GROUP BY u.id, u.name
ORDER BY total DESC
```

#### Response shape

```json
{
  "data": [
    {
      "user_id": 26,
      "name": "Cristian Marín",
      "total": 45,
      "completed": 42,
      "cancelled": 3,
      "avg_minutes": 38.5,
      "on_time_rate": 0.92,
      "success_rate": 0.93
    }
  ],
  "period": { "from": "2026-05-01", "to": "2026-05-06" }
}
```

`on_time_rate` = % entregadas en ≤ 45 min (configurable). `success_rate` = `completed / total`.

### 8.7 Export PDF "Historial de Repartidores"

```
POST /api/v1/exports/couriers/pdf
Body: { "filters": { "date_from", "date_to", "status?" } }
```

Permission: `deliveries.read,read`. Servicio: `PdfExportService::exportCouriers`. Plantilla blade: `resources/views/pdf/couriers.blade.php`.

#### Columnas del PDF

| # | Repartidor | Orden | Estado | Asignado | Completado |
|---|---|---|---|---|---|
| `#{delivery.id}` | `{deliverer.name ?? '—'}` | `#{order_id ?? '—'}` | Badge color | `{assigned_at \| d/m/Y H:i}` | `{delivered_at \| d/m/Y H:i \| —}` |

**Bug histórico fixeado en PR #84**: el blade leía `$delivery->completed_at` (columna inexistente). La columna real es `delivered_at`. Antes mostraba siempre `—` en la columna Completado.

#### Badges

| status | Label | Clase |
|---|---|---|
| `completed` | Completada | `badge-completed` (verde) |
| `pending` | Pendiente | `badge-pending` (amarillo) |
| `cancelled` | Cancelada | `badge-cancelled` (rojo) |

### 8.8 Back-compat

`/deliveries → /orders/deliveries` (302) preservando `?token=`. Implementado en `routes/web.php`.

### 8.9 Comportamiento de notificaciones WhatsApp

`DeliveryNotificationService` consulta el setting `whatsapp_account` antes de despachar:

```php
if (!$this->whatsappAccountService->hasActiveConnection($companyNit)) {
    Log::info('WhatsApp not connected, skipping delivery notification');
    return;
}

try {
    $this->outboundSender->sendText(
        $companyNit,
        $clientPhone,
        $message
    );
} catch (\Throwable $e) {
    Log::warning('Delivery notification failed', ['error' => $e->getMessage()]);
    // ← NO re-lanza; el flujo de delivery sigue
}
```

Configuración:
- `DELIVERY_NOTIFY_ON_ASSIGNMENT` (default `true`).
- `DELIVERY_NOTIFY_ON_COMPLETION` (default `true`).
- Si la empresa no tiene WhatsApp Cloud API conectado, las notificaciones se **omiten silenciosamente** (no bloquean delivery).

---

## 9. Menú (`/menu` y `/menu/{id}`)

Páginas: `pages/menu/index.tsx` (lista), `pages/menu/show.tsx` (editor). Controller: `App\Http\Controllers\Menu\MenuController` (27 métodos públicos). Modelo: `RestaurantMenu`.

### 9.0 Estructura JSON v3 del menú

`restaurant_menus.structure` es JSONB con shape:

```json
{
  "version": 3,
  "categories": [
    {
      "id": "entradas",
      "name": "Entradas",
      "description": "...",
      "order": 1,
      "items": [
        {
          "id": "bruschetta-tomate",
          "name": "Bruschetta al pomodoro",
          "description": "Cuatro panes tostados...",
          "price": 18900,
          "image_path": null,
          "available": true,
          "order": 1
        }
      ]
    }
  ]
}
```

**`version: 3`** es obligatorio. Versiones anteriores (1, 2) no son compatibles — requerirían migración.

#### Campos del item

| Campo | Tipo | Validación |
|---|---|---|
| `id` | string | único dentro del menú; slug `[a-z0-9\-]+` |
| `name` | string | máx 100 chars |
| `description` | string | nullable, máx 500 chars |
| `price` | int | en COP enteros (sin decimales) |
| `image_path` | string \| null | path relativo en disco `menu.image_disk` |
| `available` | bool | toggle de disponibilidad |
| `order` | int | orden en la categoría (drag-drop lo actualiza) |

#### `active_days` (programación)

Array opcional `int[]` con días de la semana donde el menú aplica:
- `null` o `[]` → aplica todos los días.
- `[1, 2, 3, 4, 5]` → sólo lunes a viernes (ej. menú ejecutivo).
- `[0, 5, 6]` → sólo domingo, viernes y sábado (ej. especial fin de semana).

Convención: 0 = domingo, 6 = sábado.

#### Estados (`status`)

| status | Significado |
|---|---|
| `draft` | Borrador, no visible para clientes |
| `scheduled` | Programado para ciertos días (`active_days`); no activo hoy si no aplica |
| `active` | Activo y visible — sólo uno por empresa a la vez |

### 9.1 Listado de menús (`GET /api/v1/menus`)

Permission: `menu.read,read`. Página `/menu`. Polling: **NO** (sólo botón "Actualizar" manual con `RefreshCw`).

#### Response

```json
{
  "data": [
    {
      "id": 1,
      "name": "Carta SuperPasas",
      "description": "...",
      "status": "active",
      "active_days": null,
      "structure": { /* v3 JSON */ },
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "permissions": {
    "canCreate": true,
    "canUpdate": true,
    "canDelete": true
  }
}
```

`permissions` viene calculado por `MenuPermissionService::summarize($actor, $companyNit)`. El frontend usa estos flags para mostrar/ocultar botones (no consulta RBAC localmente — el backend es la fuente de verdad).

### 9.2 Crear menú (`POST /api/v1/menus`)

Permission: `menu.create,create`. FormRequest: `StoreMenuRequest`.

```php
'name'          => ['required', 'string', 'max:100'],
'description'   => ['nullable', 'string', 'max:500'],
'active_days'   => ['nullable', 'array'],
'active_days.*' => ['integer', 'between:0,6'],
'structure'     => ['nullable', 'array'],  // si no se envía, se inicia con structure vacía v3
```

Crea con `status='draft'`. No hay límite de menús por empresa.

### 9.3 Activar menú (`PATCH /api/v1/menus/{id}/activate`)

Permission: `menu.update,update`. Lógica clave en transacción:

```php
DB::transaction(function () use ($menu) {
    // 1. Desactiva el menú activo actual (si existe)
    RestaurantMenu::forCompany($nit)
        ->where('status', 'active')
        ->update(['status' => 'scheduled']);  // a scheduled, no draft

    // 2. Activa este
    $menu->update(['status' => 'active']);
});
```

**Constraint**: sólo un menú con `status='active'` por empresa a la vez. Garantizado por la lógica del controller (no hay UNIQUE constraint en DB porque `active_days` permite varios scheduled coexistiendo).

### 9.4 Duplicar menú (`POST /api/v1/menus/{id}/duplicate`)

Permission: `menu.create,create`. Clona el `structure` JSON entero pero:
- Genera nuevos `id` para cada categoría e ítem (UUID o slug + `-copy`).
- Setea `status='draft'`.
- Sufijo en el nombre: `"X (copia)"`.

```php
$source = RestaurantMenu::forCompany($nit)->findOrFail($id);
$copy = $source->replicate();
$copy->name .= ' (copia)';
$copy->status = 'draft';
$copy->structure = $this->regenerateIdsInStructure($source->structure);
$copy->save();
```

### 9.5 Editor de menú (`/menu/{id}`)

Página: `pages/menu/show.tsx`. Hooks: `useMenuDrag`, `useImageUpload`. Permission: `menu.read,read`. Acciones gateadas por `canCreate/canUpdate/canDelete` que llegan en el response.

#### CRUD de categorías

| Endpoint | Permission | Body |
|---|---|---|
| `POST /api/v1/menus/{id}/categories` | `menu.create,create` | `{name, description?, order?}` |
| `PUT /api/v1/menus/{id}/categories/{catId}` | `menu.update,update` | mismo |
| `DELETE /api/v1/menus/{id}/categories/{catId}` | `menu.delete,delete` | — |

Eliminar categoría con ítems → `cascade=true` los borra todos en una operación atómica (FormRequest `DeleteCategoryRequest` con confirmación).

#### CRUD de ítems

```
POST /api/v1/menus/{id}/categories/{catId}/items
PUT  /api/v1/menus/{id}/categories/{catId}/items/{itemId}
DELETE /api/v1/menus/{id}/categories/{catId}/items/{itemId}
```

#### Validación de ítem (`StoreItemRequest`, `UpdateItemRequest`)

```php
'name'        => ['required', 'string', 'max:100'],
'description' => ['nullable', 'string', 'max:500'],
'price'       => ['required', 'integer', 'min:0', 'max:9999999'],
'available'   => ['nullable', 'boolean'],
```

#### Bloqueo de eliminación si tiene órdenes activas

`MenuController::destroyItem` verifica:

```php
$active = Order::forCompany($nit)
    ->whereIn('status', ['pending', 'in_kitchen', 'ready', 'in_delivery'])
    ->where('items', '@>', "[{\"id\": \"{$itemId}\"}]")  // PostgreSQL JSONB containment
    ->exists();

if ($active) {
    return response()->json([
        'message' => 'No se puede eliminar el ítem porque tiene órdenes activas.'
    ], 409);
}
```

### 9.6 Drag-drop con dnd-kit y debounce 300ms

Hook `useMenuDrag` retorna `{updateCategoryOrder, updateItemOrder}`:

```ts
const debouncedSave = useDebouncedCallback((newOrder) => {
  apiFetch(`/api/v1/menus/${menuId}/categories/${catId}`, {
    method: 'PUT',
    body: JSON.stringify({ order: newOrder })
  });
}, 300);

const onDragEnd = ({ active, over }) => {
  if (!over) return;
  const newOrder = recomputeOrder(items, active.id, over.id);
  setItems(newOrder);  // optimistic
  debouncedSave(newOrder);
};
```

**Debounce 300ms**: si el usuario arrastra varios items en sucesión, sólo el último gana — evita N requests cuando reordena rápidamente.

### 9.7 Upload de imagen de plato (`POST /api/v1/menus/{id}/items/{itemId}/image`)

Permission: `menu.update,update`. FormRequest: `UploadDishImageRequest`.

```php
'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],  // 2 MB
```

#### Pipeline

1. Frontend (`useImageUpload`) valida client-side: `file.size <= 2 * 1024 * 1024` y MIME en `['image/jpeg', 'image/png']`. Si falla, no envía.
2. POST multipart con `image` field.
3. Backend valida con FormRequest (defensa en profundidad — el client-side es UX, el server-side es seguridad).
4. `Storage::disk(config('menu.image_disk', 'local'))->putFile("menus/items", $file)` retorna path único.
5. Update del JSONB: setea `structure.categories[].items[itemId].image_path = $newPath`.
6. Borra archivo viejo si lo había (`Storage::delete($oldPath)`).
7. Audit log: `menu.item_image_uploaded` con `{file_size, mime_type}`.

#### URLs firmadas (validez 60 min)

`Storage::temporaryUrl($path, now()->addMinutes(60))` para imágenes en S3 o disco protegido. En disco `local` retorna URL pública directa (no firmada — el disco es público).

### 9.8 Toggle de disponibilidad (`PATCH .../items/{itemId}/availability`)

Permission: `menu.update,update`. Body: `{available: bool}`.

#### Optimistic update

```ts
// pages/menu/show.tsx
const toggleAvailability = async (itemId, current) => {
  setItems(prev => prev.map(i => i.id === itemId ? { ...i, available: !current } : i));
  const res = await apiFetch(`/api/v1/menus/${menuId}/items/${itemId}/availability`, {
    method: 'PATCH',
    body: JSON.stringify({ available: !current }),
  });
  if (!res.ok) {
    setItems(prev => prev.map(i => i.id === itemId ? { ...i, available: current } : i));  // revierte
    showToast('error', 'No se pudo cambiar disponibilidad');
  }
};
```

Audit log: `menu.item_availability_changed` con `{from, to, item_id}`.

### 9.9 Programar días activos (`PATCH /api/v1/menus/{id}/schedule`)

Permission: `menu.update,update`. Body: `{active_days: int[]}`.

```php
'active_days'   => ['required', 'array'],
'active_days.*' => ['integer', 'between:0,6'],
```

Si el menú estaba `active` y la programación nueva no incluye hoy, `MenuSchedulerService::syncOne($menu)` lo demueve a `scheduled`.

### 9.10 Sync manual (`POST /api/v1/menus/sync-schedule`)

Permission: `menu.update,update`. Botón "Sincronizar programación". Llama `MenuSchedulerService::syncCompany($nit)`:

```php
public function syncCompany(string $nit): void {
    $today = (int) now()->format('w');  // 0=dom, 6=sáb

    $menus = RestaurantMenu::forCompany($nit)->whereIn('status', ['active', 'scheduled'])->get();

    foreach ($menus as $menu) {
        $shouldBeActive = empty($menu->active_days) || in_array($today, $menu->active_days, true);

        if ($shouldBeActive && $menu->status !== 'active') {
            // Promover este menú
            RestaurantMenu::forCompany($nit)->where('status', 'active')->update(['status' => 'scheduled']);
            $menu->update(['status' => 'active']);
        } elseif (!$shouldBeActive && $menu->status === 'active') {
            $menu->update(['status' => 'scheduled']);
        }
    }
}
```

**Si hay conflictos** (varios menús aplican hoy) — el primero (por `id` ascendente) gana. La UI debería evitar este escenario; el comando es defensivo.

### 9.11 Comando programado `menus:sync-schedule`

Cron: `0 * * * *` (cada hora en el minuto 0). Definido en `routes/console.php`:

```php
Schedule::command('menus:sync-schedule')->hourly();
```

Itera todas las companies y llama `MenuSchedulerService::syncCompany($nit)`. Útil para que cuando llegan las 00:00 de un día nuevo, el menú correspondiente se active automáticamente sin acción del operador.

### 9.12 Menú público (sin autenticación)

```
GET /api/v1/public/menu/{companyNit}
Middleware: jwt (sin company.access)
```

**Excepción al multi-tenancy**: el `companyNit` viene por URL, no por JWT. Cualquier JWT válido (incluso el del bot o de un cliente con cart JWT) puede consultar el menú activo de cualquier empresa.

#### Response

```json
{
  "data": {
    "company": {
      "nit": "1",
      "commercial_name": "SuperPasas",
      "logo_path": null,
      "menu_primary_color": "#0052FF"
    },
    "menu": {
      "name": "Carta SuperPasas",
      "categories": [
        {
          "id": "entradas",
          "name": "Entradas",
          "items": [
            { "id": "bruschetta-tomate", "name": "Bruschetta al pomodoro", "price": 18900, "available": true, "image_url": null }
          ]
        }
      ]
    }
  }
}
```

**Filtros aplicados** server-side:
- Sólo `RestaurantMenu` con `status='active'`.
- Sólo categorías con al menos un item disponible.
- Sólo items con `available=true`.

Diseñado para que `pedidos.flexyflow.co` cargue el menú antes de obtener cart JWT (precarga UX).

#### 9.12.1 Pedido público sin mesa desde el QR de sede

Desde `/menus?branch={menu_qr_token}` (QR de menú de sede) el cliente arma un
carrito local y envía un pedido **para llevar o domicilio** sin mesa:

```
POST /api/v1/public/branch/{menu_qr_token}/orders
Throttle: branch-order-public (5/min por IP+token). Sin auth.
Payload: type (pickup|delivery), customer_name, customer_phone,
         address + neighborhood (solo delivery), items[{id, quantity, notes?}],
         payment_preference?, cash_pays_with?, tip_amount?, customer_notes?
```

- **Checkout enriquecido (F2)**: el cliente elige medio de pago (solo los
  habilitados en `/company/preferences` → `company_settings.payment_methods`,
  mapeados vía `config('payments.company_aliases')`; el payload público expone
  `restaurant.payment_methods[{slug,label,account}]` con el número de cuenta
  para transferencia/Nequi/Daviplata), dice con cuánto paga si es efectivo
  (`orders.cash_pays_with`, validado ≥ total+propina contra BD), deja propina
  voluntaria sugerida 10% (→ `orders.tip_amount`, fuera del total;
  `closeWithPayment` la conserva si caja no la reenvía) y notas de entrega
  (`orders.customer_notes`, sanitizadas, 500 bytes). Todo INFORMATIVO: el pago
  real lo registra caja.

- Mismas puertas que el menú público: empresa activa, horario abierto, caja abierta.
- La orden nace `status=pending_approval` (items `pending_approval` + `submitted_at`)
  y cae al banner de aprobaciones de caja/tablero. El staff valida los datos
  (dirección/barrio a mano, no hay validación automática) y aprueba con
  `POST /api/v1/orders/{id}/approve` (→ `pending`, items → `approved`) o cancela.
- Precios SIEMPRE del menú activo de la sede. El envío entra como línea
  `order_items` sintética (`menu_item_id='delivery_fee'` — constante
  `Order::DELIVERY_FEE_ITEM_ID`, "Domicilio", tax 0), con el precio configurado
  por sede (`branch_settings.delivery_fee`, editable en `/company/branches` →
  branding del menú). El mismo fee aplica a órdenes delivery creadas desde caja
  (`OrderController::store` lo inyecta antes del aggregate; la fila nace
  `served` para no aparecer en KDS). El bootstrap expone
  `activeBranch.delivery_fee` para que caja muestre el total real.
- Domicilios solo dentro de la ciudad de la sede (`branches.city`) — aviso
  informativo en el checkout público; la verificación es manual en la aprobación.
- Cliente vinculado al CRM por phone (upsert de `Contact` con nombre).
- Auditoría: `order.created_by_customer` (sin actor) y `order.approved`.

#### Sesión de carta: tracking, multi-orden y append del cliente (F3)

- **Tracking**: `cart_sessions.viewed_at` se marca en el primer open del link
  (`cart-resolve`); `last_activity_at` se refresca con pings del cliente
  (`POST /api/v1/public/cart/{token}/activity`, throttle `cart-public` 30/min,
  siempre 204). El chat los muestra por el polling de 30s existente.
- **Multi-orden**: `orders.cart_session_id` liga cada orden a su sesión de
  carta; `linkCartSession` acepta sesiones `active|converted` (el mismo link
  produce la 2ª+ orden tras aprobarse la primera).
- **Estado público (CA6)**: `GET /api/v1/public/cart/{token}/orders` devuelve
  todas las órdenes con label derivado (`pending_approval` + preferencia
  transferencia → "Esperando comprobante"; si no → "En revisión"). El
  componente `public-order-status.tsx` lo pinta sobre la carta (poll 12s).
- **Append del cliente**: `POST /api/v1/public/cart/{token}/orders/{order}/items`
  agrega items mientras la orden siga `pending_approval` (re-chequeo bajo
  lock; si caja aprobó en paralelo → 409 `ORDER_ALREADY_APPROVED` y el
  frontend cae a checkout de orden nueva). Precios del menú activo en BD;
  audit `order.items_appended_by_customer`; ChatMessage bot al hilo del chat.

#### Chat: panel de próxima acción y recibo térmico virtual (F4)

- **`cart_flow` en `GET /chats/{id}`**: última sesión de carta del chat +
  órdenes (`status`, `payment_preference`, `cash_pays_with`, `customer_notes`,
  `receipt_sent_at`, `receipt_stale`). Viaja por el polling de 30s existente.
- **Panel `chat-cart-actions.tsx`** bajo el header del chat: "Carta enviada —
  sin abrir" / "Abrió la carta {hora}" / badge pulsante "Está armando el
  pedido…" (`last_activity_at` < 3 min) / "Link vencido" + reenviar carta. Por
  orden en curso: chips (tipo, medio de pago, paga-con/devueltas, notas CA7/
  CA8) y botones — **nada se envía automático**.
- **Recibo térmico (CA2)**: `POST /chats/{id}/orders/{orderId}/receipt`
  (`chats.update`) arma texto plano 32 cols con `WhatsappReceiptBuilder`
  (envuelto en ``` para monoespaciado WhatsApp; total desde `orders.total`,
  propina desglosada, PAGA CON/CAMBIO si efectivo, strip ESC/GS y backticks)
  y lo envía como ChatMessage operator. Guards bajo lock: 409
  `RECEIPT_ALREADY_SENT` si vigente (`receipt_sent_total == total+tip`), 409
  `ORDER_CHANGED` si `expected_total` difiere (carrera con el append del
  cliente). Outbound fallido queda `failed` y se reintenta con `retryMessage`
  sin resetear el guard. Audit `chat.receipt.sent` (sin el cuerpo).
- **Aprobación (CA3/CA4)**: reutiliza `POST /orders/{id}/approve` + param
  opcional `expected_total` (mismo guard 409). Con preferencia transferencia
  el botón sugiere verificar el comprobante (llega como mensaje con imagen).
- **Rechazar comprobante (CA3)**: `POST /chats/{id}/orders/{orderId}/reject-proof`
  envía aviso estándar; la orden sigue `pending_approval`. Audit
  `chat.payment_proof.rejected`.

#### Cajero edita la orden del chat (F5)

- **Append staff extendido**: `POST /orders/{id}/items` acepta
  `table|delivery|pickup` (antes solo mesa). Rechaza delivery en `in_transit`
  (ya despachado). "Agregar productos" en `orders/show.tsx` habilitado para
  delivery/pickup no despachados (incluye `pending_approval` del chat).
- **Cambio de tipo en caliente**: `PATCH /orders/{id}/order-type`
  (`orders.update`) alterna pickup↔delivery mientras no sea terminal ni
  `in_transit`. A delivery exige dirección e inserta la línea Domicilio (fee
  de la sede, sin doble fee bajo lock); a pickup la cancela
  (`cancellation_reason='system'`). Recalcula con `recalculateAndSave`; el
  recibo queda stale → el panel sugiere reenviar. Acción en el panel del chat
  (botón "Pasar a domicilio/para llevar" con dirección inline). Audit
  `order.type_changed`.

#### Ledger de efectivo del domiciliario (F6)

- **Abono al despachar (CA4)**: `POST /orders/{id}/courier-advance`
  (`orders.update`) — el repartidor entrega el total del pedido en efectivo a
  caja. Es un `PaymentReceipt` cash normal con
  `payment_data.{courier_advance, courier_user_id}` → `computeExpectedCash` y
  el arqueo cuadran sin lógica nueva. Propina fuera del abono (es del
  repartidor). Guards: delivery `in_transit` asignada, sin receipt positivo
  previo, caja abierta. `closeWithPayment` → 409 `ORDER_ALREADY_PAID` si ya
  está cubierta. Botón "Registrar abono del domiciliario" en
  `OrderDetailModal` (vista deliveries).
- **Entrega fallida / no-show (CA5)**: con abono → `refund` existente (asiento
  negativo, el domiciliario recupera su plata, orden `refunded`) y luego
  `PUT /deliveries/{id}/no-show`; sin abono → `POST /orders/{id}/cancel` con
  `category='no_show'` (orden → `failed`, delivery cerrado con razón
  `no_show`). Botón "Entrega fallida / No show" en `OrderDetailModal`. El
  aviso al cliente es un botón MANUAL en el panel del chat ("Enviar aviso de
  cancelación") — nunca automático.
- **Cruce al cierre**: `liveSummary.couriers[]` (modal de cierre) y el PDF de
  cierre (`pdf/cash-drawer.blade.php`) muestran por domiciliario: abonos,
  reversiones, entregas completadas, tarifas adeudadas (SUM líneas Domicilio)
  y pagos. Las tarifas se pagan con el egreso `domiciliario_pago` vinculado
  al repartidor (`cash_register_expenses.courier_user_id`, selector en el
  modal de egresos).

### 9.13 Resumen de los 18 endpoints de menú

| Método | URL | Permission | Notas |
|---|---|---|---|
| GET | `menus` | `menu.read,read` | Lista con permissions |
| POST | `menus` | `menu.create,create` | Nuevo menú |
| GET | `menus/{id}` | `menu.read,read` | Detalle con structure |
| PUT | `menus/{id}` | `menu.update,update` | Update name/description/active_days |
| DELETE | `menus/{id}` | `menu.delete,delete` | Borra menú entero |
| POST | `menus/{id}/duplicate` | `menu.create,create` | Clona como draft |
| PATCH | `menus/{id}/activate` | `menu.update,update` | Activar (desactiva otros) |
| PATCH | `menus/{id}/schedule` | `menu.update,update` | Set active_days |
| POST | `menus/sync-schedule` | `menu.update,update` | Sync manual |
| POST | `menus/{id}/categories` | `menu.create,create` | Nueva categoría |
| PUT | `menus/{id}/categories/{catId}` | `menu.update,update` | Edit/reorder |
| DELETE | `menus/{id}/categories/{catId}` | `menu.delete,delete` | Borra cat + items |
| POST | `menus/{id}/categories/{catId}/items` | `menu.create,create` | Nuevo item |
| PUT | `menus/{id}/categories/{catId}/items/{itemId}` | `menu.update,update` | Edit item |
| DELETE | `menus/{id}/categories/{catId}/items/{itemId}` | `menu.delete,delete` | Borra item (bloqueado si tiene órdenes activas) |
| POST | `menus/{id}/items/{itemId}/image` | `menu.update,update` | Upload imagen 2MB |
| PATCH | `menus/{id}/categories/{catId}/items/{itemId}/availability` | `menu.update,update` | Toggle disponibilidad |
| GET | `public/menu/{companyNit}` | `jwt` (sin company.access) | Público |

---

## 10. Cupones (`/coupons` y `/coupons/{id}`)

Páginas: `pages/coupons/index.tsx` (lista), `pages/coupons/show.tsx` (detalle). Hook: `useCoupons`. Servicio: `App\Services\CouponService`.

### 10.0 Modelo `Coupon` y schema

```sql
coupons (
  id, company_nit, code,
  type,                            -- 'percentage' | 'fixed_amount'
  value,                           -- numeric(10,2)
  valid_from, valid_until,         -- timestamps
  valid_days,                      -- jsonb nullable. Ej. [1,2,3,4,5] (0=Dom..6=Sáb). NULL/[] = todos los días. (#125)
  valid_hours_from, valid_hours_to,-- time nullable, ambos o ninguno (CHECK). America/Bogota. Cross-midnight si from > to. (#125)
  auto_apply,                      -- bool default false. Si true, el OrderController lo aplica al cerrar la orden sin código. (#125)
  max_uses, uses_count,            -- int (max_uses null = ilimitado)
  min_order_amount,                -- numeric(10,2) nullable
  first_order_only,                -- bool (default false)
  is_active,                       -- bool (toggle manual)
  status,                          -- 'active' | 'inactive' | 'exhausted'
  created_by,                      -- email del actor (texto, no FK)
  deleted_at,                      -- soft delete
  created_at, updated_at
)
-- Índice parcial #125: coupons_auto_apply_active_idx ON coupons (company_nit)
--   WHERE auto_apply=true AND status='active' AND deleted_at IS NULL
```

### 10.1 Reglas de validación de campos (`StoreCouponRequest`)

```php
'code'             => ['required', 'string', 'min:4', 'max:'.config('coupons.code_max_length', 20),
                       'regex:/^[A-Z0-9\-_]+$/',
                       Rule::unique('coupons', 'code')->where('company_nit', $nit)->whereNull('deleted_at')],
'type'             => ['required', Rule::in(['percentage', 'fixed_amount'])],
'value'            => ['required', 'numeric', 'min:0',
                       Rule::when(fn() => $this->type === 'percentage',
                           ['max:'.config('coupons.max_value_percentage', 80)],
                           ['max:'.config('coupons.max_value_fixed', 100000)])],
'valid_from'       => ['required', 'date'],
'valid_until'      => ['required', 'date', 'after_or_equal:valid_from'],
'max_uses'         => ['nullable', 'integer', 'min:1'],
'min_order_amount' => ['nullable', 'numeric', 'min:0'],
'first_order_only' => ['nullable', 'boolean'],
'is_active'        => ['nullable', 'boolean'],
```

#### Reglas de negocio

| Regla | Implementación |
|---|---|
| Código único por empresa (`code, company_nit`) | `Rule::unique` con scope |
| Código `[A-Z0-9\-_]{4,20}` | regex |
| Tipo `percentage` máx 80% | `COUPON_MAX_VALUE_PERCENTAGE` (default 80) |
| Tipo `fixed_amount` máx 100 000 COP | `COUPON_MAX_VALUE_FIXED` (default 100000) |
| Cupón con `uses_count > 0` no editable | `UpdateCouponRequest::authorize` retorna `false` si `uses_count > 0` |
| Validez: `valid_until >= valid_from` | `after_or_equal` |
| `max_uses` null = ilimitado | nullable |

### 10.2 Auto-agotado y status

`syncStatus()` se llama después de cada redención y al ejecutar el seeder/test:

```php
public function syncStatus(): self {
    if ($this->valid_until !== null && $this->valid_until->isPast()) {
        $status = 'inactive';
    } elseif ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
        $status = 'exhausted';
    } else {
        $status = $this->is_active ? 'active' : 'inactive';
    }
    $this->update(['status' => $status]);
    return $this;
}
```

| Caso | status final |
|---|---|
| `is_active=false` | `inactive` |
| `valid_until < now()` | `inactive` |
| `uses_count >= max_uses` | `exhausted` |
| Activo y vigente | `active` |

### 10.3 Cálculo del descuento (`Coupon::calculateDiscount($subtotal)`)

```php
public function calculateDiscount(float $subtotal): float {
    if ($this->min_order_amount && $subtotal < $this->min_order_amount) {
        return 0.0;  // no aplica
    }
    return match ($this->type) {
        'percentage'   => round($subtotal * ($this->value / 100), 2),
        'fixed_amount' => min($this->value, $subtotal),  // no descuenta más del subtotal
    };
}
```

Ejemplos:
- Cupón `BIENVENIDO 15%` sobre subtotal $40 000 → descuento $6 000 → total $34 000.
- Cupón `VIERNES10` ($10 000 fijo) sobre subtotal $80 000 → descuento $10 000 → total $70 000.
- Cupón `VIERNES10` sobre subtotal $5 000 → descuento $5 000 (no más que el subtotal) → total $0.

### 10.3.bis Programación horaria / Happy Hour (#125)

Cupones pueden restringirse a días+horas específicos y aplicarse automáticamente sin que el cliente teclee el código.

**Schema**: `valid_days jsonb` (array de ints 0-6 o NULL), `valid_hours_from/to time` (ambos o ninguno, CHECK), `auto_apply boolean default false`.

**Lógica** (`Coupon::isScheduledNow()`):
- Sin programación → siempre aplica.
- `valid_days` con valores → debe coincidir el día actual (`now('America/Bogota')->format('w')`).
- `valid_hours_from <= valid_hours_to` → ventana directa.
- `valid_hours_from > valid_hours_to` → ventana cruza medianoche (ej. 22:00→02:00, válida si `now >= from || now <= to`).
- TZ siempre `America/Bogota`.

**Auto-apply** (`CouponService::bestAutoApplyForCart`):
- Si `OrderController::store` recibe `coupon_code` vacío, busca cupones `auto_apply=true && active && in-window`, valida cada uno y elige el de mayor `discount_amount`. Desempate `created_at DESC`.
- Excluye `locked_to_phone IS NOT NULL` y `source='loyalty_redeem'` (cupones de canje deben invocarse explícitamente).
- Audita en `audit_logs` con acción `coupon.auto_applied` (incluye `coupon_id`, `discount_amount`, `order_total_before/after`).

**Endpoints públicos**:
- `POST /api/v1/cart/active-auto-apply` → anuncia el mejor candidato para el carrito actual con `{active, coupon_code, label, ends_at, discount_amount, final_total}`. Útil para badge en POS y bot público.

**Reglas contables**: idéntico flujo que cupón manual; `discount_amount` reduce base gravable preservando `total = subtotal + tax_amount`. Sin nuevos `payment_method`.

### 10.4 Validación pública del código (`GET /api/v1/coupons/{code}/validate`)

Middleware: `jwt` (sin `company.access`). Usado por el bot/cart antes de aplicarlo.

#### Query

`GET /api/v1/coupons/BIENVENIDO/validate?company_nit=1&subtotal=45000&client_phone=573001112233`

#### Lógica

```php
$coupon = Coupon::where('company_nit', $nit)
    ->where('code', $code)
    ->whereNull('deleted_at')
    ->first();

if (!$coupon) {
    return response()->json(['valid' => false, 'reason' => 'not_found'], 404);
}

if (!$coupon->is_active || $coupon->status === 'inactive') {
    return response()->json(['valid' => false, 'reason' => 'inactive']);
}

if ($coupon->status === 'exhausted') {
    return response()->json(['valid' => false, 'reason' => 'exhausted']);
}

if ($coupon->valid_until && $coupon->valid_until->isPast()) {
    return response()->json(['valid' => false, 'reason' => 'expired']);
}

if ($coupon->min_order_amount && $subtotal < $coupon->min_order_amount) {
    return response()->json([
        'valid' => false,
        'reason' => 'min_order',
        'min_order_amount' => $coupon->min_order_amount,
    ]);
}

if ($coupon->first_order_only) {
    $hasOrders = Order::where('client_phone', $clientPhone)->exists();
    if ($hasOrders) {
        return response()->json(['valid' => false, 'reason' => 'not_first_order']);
    }
}

$discount = $coupon->calculateDiscount($subtotal);

return response()->json([
    'valid' => true,
    'discount_amount' => $discount,
    'total_after' => max($subtotal - $discount, 0),
    'coupon' => [
        'code' => $coupon->code,
        'type' => $coupon->type,
        'value' => $coupon->value,
    ],
]);
```

### 10.5 Aplicar cupón al carrito (`POST /api/v1/cart/apply-coupon`)

Para cliente final (sin auth de empresa). Usa CartJwt como credencial.

#### Body

```json
{ "code": "BIENVENIDO", "cart_jwt": "..." }
```

#### Lógica

1. Decodifica `cart_jwt` con `CartJwtService` → obtiene `company_nit`, `cart_session_jti`, `client_phone`.
2. Valida cupón (mismo algoritmo que `validate`).
3. Si válido: actualiza `cart_sessions.coupon_code = $code`. NO incrementa `uses_count` aún (eso pasa al confirmar la orden).

### 10.6 Redención efectiva (`CouponRedemption`)

Cuando una orden se crea con cupón aplicado, se inserta una fila en `coupon_redemptions`:

```sql
coupon_redemptions (
  id, company_nit, coupon_id, order_id,
  client_phone,
  discount_amount,                 -- monto del descuento
  order_total_before,              -- subtotal antes del descuento
  order_total_after,               -- total final
  created_at
)
```

`Coupon::redemptions()` es `hasMany(CouponRedemption)`. La columna `coupons.uses_count` se sincroniza con `count()` de redenciones via `Coupon::syncUsage()`.

### 10.7 Detalle del cupón (`/coupons/{id}`)

Página: `pages/coupons/show.tsx`. Permission: `coupons.read,read`.

#### Endpoint

```
GET /api/v1/coupons/{id}
```

Response: el cupón completo + summary de redenciones.

#### Historial paginado (`GET /api/v1/coupons/{id}/redemptions`)

Permission: `coupons.read,read`. Default `per_page=50`.

Response:
```json
{
  "data": [
    {
      "id": 234,
      "client_phone": "57300***1234",   // últimos 4 dígitos visibles
      "order_id": 12345,
      "discount_amount": 6000,
      "order_total_before": 40000,
      "order_total_after": 34000,
      "created_at": "2026-04-15T19:32:00Z"
    }
  ],
  "pagination": {...}
}
```

**Teléfono enmascarado**: `mb_substr($phone, 0, 5) . str_repeat('*', strlen($phone) - 9) . mb_substr($phone, -4)` para privacy en la UI. El admin con `users.read` ve el completo (no implementado actualmente).

### 10.8 Edición de cupón con `uses_count > 0`

`UpdateCouponRequest::authorize`:
```php
public function authorize(): bool {
    $coupon = Coupon::find($this->route('id'));
    return $coupon && $coupon->uses_count === 0;
}
```

Si `uses_count > 0`, el endpoint responde **403 Forbidden** con mensaje "Cupón ya redimido, no editable. Crea uno nuevo." Esto preserva la integridad histórica de `coupon_redemptions` (no cambias las reglas con las que un cliente redimió).

**Excepción**: el toggle `is_active` (PATCH `/status`) sí funciona aunque `uses_count > 0` — para poder pausar campañas en curso.

### 10.9 Soft delete

```sql
coupons.deleted_at TIMESTAMP NULL
```

`DELETE /api/v1/coupons/{id}` aplica `Coupon::delete()` (Eloquent SoftDeletes trait). Las redenciones históricas siguen apuntando al cupón vía `coupon_id` con `withTrashed()`, así que los reportes financieros del pasado no se rompen.

### 10.10 Generador de código (`generateCouponCode()`)

Frontend en `lib/generate-coupon-code.ts`:

```ts
export function generateCouponCode(length = 8): string {
  const charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';  // sin O/0/I/1 para legibilidad
  return Array.from({ length }, () =>
    charset[Math.floor(Math.random() * charset.length)]
  ).join('');
}
```

Botón "Generar código" en `CouponForm` llama esto y rellena el input. El usuario puede sobrescribirlo manualmente.

### 10.11 Endpoints de cupones (resumen)

| Método | URL | Permission | Notas |
|---|---|---|---|
| GET | `coupons` | `coupons.read,read` | Lista paginada |
| POST | `coupons` | `coupons.create,create` | Nuevo |
| GET | `coupons/{id}` | `coupons.read,read` | Detalle |
| PUT | `coupons/{id}` | `coupons.update,update` | Editar (sólo `uses_count=0`) |
| PATCH | `coupons/{id}/status` | `coupons.update,update` | Toggle is_active (siempre permitido) |
| DELETE | `coupons/{id}` | `coupons.delete,delete` | Soft delete |
| GET | `coupons/{id}/redemptions` | `coupons.read,read` | Historial paginado |
| GET | `coupons/{code}/validate` | `jwt` (sin company.access) | Validación pública |
| POST | `cart/apply-coupon` | `jwt` (sin company.access) | Aplicar al carrito |

---

## 11. Horarios operativos (`/hours`)

Página: `pages/hours/index.tsx`. Hook: `useBusinessHours`. Servicio: `App\Services\BusinessHoursService`. Permission: `hours.read,read` (ver), `hours.update,update` (editar).

### 11.0 Modelos

#### `business_hours`

```sql
business_hours (
  id, company_nit,
  day_of_week,       -- 0=domingo, 6=sábado
  open_time,         -- TIME
  close_time,        -- TIME
  is_enabled,        -- bool (true = abre ese día)
  UNIQUE(company_nit, day_of_week)
)
```

7 filas por empresa (una por día). El seeder crea los 7 al hacer enrollment.

#### `business_hour_exceptions`

```sql
business_hour_exceptions (
  id, company_nit,
  exception_date,    -- DATE
  is_open,           -- bool (false = cerrado todo el día)
  open_time,         -- TIME nullable (si is_open=true y override)
  close_time,        -- TIME nullable
  reason,            -- string nullable
  UNIQUE(company_nit, exception_date)
)
```

Una excepción por fecha por empresa. Las excepciones tienen **precedencia absoluta** sobre el horario semanal de ese día.

### 11.1 Horario semanal

Página muestra editor con 7 filas (Dom→Sáb), cada una con:
- Toggle `is_enabled` (abierto / cerrado).
- Time input `open_time` (deshabilitado si cerrado).
- Time input `close_time`.
- Validación: `close_time > open_time`.

#### Endpoint `PUT /api/v1/hours`

FormRequest: `UpdateBusinessHoursRequest`. Body:

```json
{
  "schedule": [
    { "day_of_week": 0, "open_time": "16:00:00", "close_time": "22:00:00", "is_enabled": true },
    { "day_of_week": 1, "open_time": "00:00:00", "close_time": "00:00:00", "is_enabled": false },
    ...7 entradas
  ]
}
```

Validación:
```php
'schedule'             => ['required', 'array', 'size:7'],
'schedule.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
'schedule.*.open_time'   => ['required', 'date_format:H:i:s'],
'schedule.*.close_time'  => ['required', 'date_format:H:i:s'],
'schedule.*.is_enabled'  => ['required', 'boolean'],
```

Implementación: bulk upsert por `(company_nit, day_of_week)`.

### 11.2 Estado en tiempo real (`GET /api/v1/hours/status`)

Permission: `hours.read,read`. Servicio: `BusinessHoursService::getStatus($companyNit, ?Carbon $now)`.

#### Response

```json
{
  "data": {
    "is_open": true,
    "reason": "Horario regular",
    "next_change": "2026-05-06T22:30:00-05:00",
    "current_schedule": {
      "day": "Mar",
      "open_time": "17:00",
      "close_time": "22:30"
    }
  }
}
```

#### Algoritmo

```php
public function getStatus(string $nit, ?Carbon $now = null): array {
    $now ??= now();
    $today = $now->format('Y-m-d');
    $dow = (int) $now->format('w');

    // 1. Excepción del día (precedencia)
    $exception = BusinessHourException::where('company_nit', $nit)
        ->where('exception_date', $today)
        ->first();

    if ($exception) {
        if (!$exception->is_open) {
            return ['is_open' => false, 'reason' => $exception->reason ?? 'Cerrado por excepción', 'next_change' => $this->resolveNextChange($nit, $now)];
        }
        $open = Carbon::parse($today.' '.$exception->open_time);
        $close = Carbon::parse($today.' '.$exception->close_time);
        $isOpen = $now->between($open, $close);
        return [
            'is_open' => $isOpen,
            'reason' => $exception->reason ?? 'Horario especial',
            'next_change' => $isOpen ? $close->toIso8601String() : $open->toIso8601String(),
        ];
    }

    // 2. Horario base del día
    $hour = BusinessHour::where('company_nit', $nit)
        ->where('day_of_week', $dow)
        ->first();

    if (!$hour || !$hour->is_enabled) {
        return ['is_open' => false, 'reason' => 'Día cerrado', 'next_change' => $this->resolveNextOpening($nit, $now)];
    }

    $open = Carbon::parse($today.' '.$hour->open_time);
    $close = Carbon::parse($today.' '.$hour->close_time);
    $isOpen = $now->between($open, $close);
    return [
        'is_open' => $isOpen,
        'reason' => 'Horario regular',
        'next_change' => $isOpen ? $close->toIso8601String() : $open->toIso8601String(),
    ];
}
```

#### Limitación: cierre después de medianoche

`close_time` es TIME (no permite cruzar a día siguiente). Para un restaurante que cierra a las 02:00 AM, hoy se modela como `close_time='23:59:59'` y se confía en el cron `chats:purge-old` y la operación humana para órdenes posteriores. Mejora futura: agregar `close_time_next_day BOOLEAN` o un nuevo campo `close_at_offset_minutes`.

### 11.3 Excepciones (CRUD)

| Endpoint | Permission |
|---|---|
| GET `/api/v1/hours/exceptions` | `hours.read,read` |
| POST `/api/v1/hours/exceptions` | `hours.update,update` |
| PUT `/api/v1/hours/exceptions/{id}` | `hours.update,update` |
| DELETE `/api/v1/hours/exceptions/{id}` | `hours.update,update` |

#### Body crear/editar

```json
{
  "exception_date": "2026-12-25",
  "is_open": false,
  "open_time": null,
  "close_time": null,
  "reason": "Cerrado por Navidad"
}
```

Si `is_open=true`: `open_time` y `close_time` requeridos.

#### Validaciones

```php
'exception_date' => ['required', 'date_format:Y-m-d',
                     Rule::unique('business_hour_exceptions')
                         ->where('company_nit', $nit)],
'is_open'        => ['required', 'boolean'],
'open_time'      => ['required_if:is_open,true', 'nullable', 'date_format:H:i:s'],
'close_time'     => ['required_if:is_open,true', 'nullable', 'date_format:H:i:s', 'after:open_time'],
'reason'         => ['nullable', 'string', 'max:200'],
```

### 11.4 Endpoint para bot externo (`GET /api/external/hours/status`)

Middleware: `bot.jwt` (separado de user JWT). Sin `company.access`.

```php
Route::middleware('bot.jwt')->get('external/hours/status', function (Request $request) {
    $companyNit = $request->attributes->get('bot_company_nit');
    return response()->json($businessHoursService->getStatus($companyNit));
});
```

#### Configuración

- `BOT_JWT_SECRET` requerido. Si no está → middleware retorna 401 (no 500).
- El bot externo emite su propio JWT firmado con esa clave, conteniendo `{company_nit, exp, ...}`.
- TTL: `BOT_JWT_TTL` (default 3600s).

**No es un endpoint público anónimo** — requiere conocer `BOT_JWT_SECRET` para emitir tokens. La clave se rota por empresa en producción.

---

## 11. Horarios operativos (`/hours`)

Permiso: `hours.read`.

### 11.1 Horario semanal

- Configurar apertura y cierre por día de la semana.
- Marcar días como cerrado (toggle `is_enabled`).
- `day_of_week`: 0=domingo, 6=sábado (convención Carbon/JS).

### 11.2 Estado en tiempo real

- `GET /api/v1/hours/status` — retorna `{is_open: boolean, reason: string, next_change: ISO 8601}`.
- Considera: horario base + excepciones del día.
- Mostrado como badge en la página de horarios.

### 11.3 Excepciones

- Agregar excepción por fecha (festivos, mantenimiento, etc.) con:
  - Override de horario (ej. abrir 10am–4pm en lugar de 9am–6pm)
  - O día completo cerrado
  - Razón opcional
- Editar y eliminar excepciones.
- **Las excepciones tienen precedencia sobre el horario base.**

### 11.4 Endpoint para bot externo

- `GET /api/external/hours/status` — protegido con `bot.jwt` (clave separada `BOT_JWT_SECRET`).
- **No es público sin autenticación.** Si `BOT_JWT_SECRET` no está configurado, devuelve 401 (no 500).

---

## 12. Chat con clientes (`/chats`)

Página: `pages/chats.tsx`. Hook: `useChats`. Controller: `App\Http\Controllers\Api\ChatController`. Permission: `chats.read,read` (ver lista y mensajes), `chats.update,update` (enviar mensaje).

### 12.0 Modelos

#### `chats`

```sql
chats (
  id, company_nit, client_phone,
  client_name,                     -- nullable, viene de WhatsApp profile o se setea manual
  contact_id,                      -- FK a contacts (nullable)
  status,                          -- 'open' | 'closed'
  source,                          -- 'whatsapp' | 'instagram' | 'facebook' | 'otro'
  bot_paused,                      -- bool (true mientras n8n no esté disponible)
  handoff_requested_at,            -- timestamp nullable
  handoff_reason,                  -- string nullable
  last_message_at,                 -- timestamp del último mensaje (any sender)
  meta_synced_at,                  -- timestamp del último sync con Meta
  meta_sync,                       -- JSON con metadata de sincronización
  created_at, updated_at,
  UNIQUE(company_nit, client_phone)
)
```

Un chat por `(company_nit, client_phone)` — relación 1:1 con cliente. Si el mismo teléfono escribe a dos restaurantes, son dos chats separados.

#### `chat_messages`

```sql
chat_messages (
  id, chat_id,
  sender,                          -- 'client' | 'bot' | 'operator'
  body,                            -- texto, nullable si es solo media
  status,                          -- 'sent' | 'delivered' | 'read' | 'failed' (operator-side)
  meta_message_id,                 -- wamid de Meta (cliente) o response id (operator)
  media,                           -- JSON: [{type:'audio'|'image'|'document', mime, url, duration}]
  sent_at,                         -- timestamp
  created_at
)
```

`sender`:
- `client` — entrante desde WhatsApp/etc; tiene `meta_message_id` (wamid).
- `bot` — automatizado por el bot externo (cuando esté operativo).
- `operator` — respuesta humana desde el panel.

### 12.1 Lista de conversaciones (`GET /api/v1/chats`)

Permission: `chats.read,read`.

#### Query params

```
q:        string (búsqueda; max 100 chars)
page:     int
per_page: int (default 20, max 50)
```

#### Algoritmo de búsqueda (`?q=`)

Implementado en `ChatController::index` con escape de wildcards:

```php
if ($q) {
    $needle = mb_strtolower(trim($q));
    $like = '%' . str_replace(['%','_','!'], ['!%','!_','!!'], $needle) . '%';

    $query->where(function ($q2) use ($like, $needle) {
        $q2->whereRaw("LOWER(client_name) LIKE ? ESCAPE '!'", [$like])
           ->orWhereRaw("LOWER(client_phone) LIKE ? ESCAPE '!'", [$like])
           ->orWhereExists(function ($sub) use ($like) {
               $sub->select(DB::raw(1))
                   ->from('chat_messages')
                   ->whereColumn('chat_messages.chat_id', 'chats.id')
                   ->whereRaw("LOWER(body) LIKE ? ESCAPE '!'", [$like]);
           });

        // Si el término contiene dígitos, también busca por id de orden
        if (preg_match('/\d/', $needle)) {
            $orderId = (int) preg_replace('/\D/', '', $needle);
            if ($orderId > 0) {
                $clientPhones = Order::where('company_nit', $companyNit)
                    ->where('id', $orderId)
                    ->pluck('client_phone');
                $q2->orWhereIn('client_phone', $clientPhones);
            }
        }
    });
}
```

**Anti SQL-injection**:
- PDO bindea todos los params (no concat directo).
- Wildcards `%`, `_`, `!` se escapan con `!` y se usa `ESCAPE '!'`.
- `q` validado max:100.

#### Response

```json
{
  "data": [
    {
      "id": 42,
      "client_phone": "573001112233",
      "client_name": "Laura Restrepo",
      "status": "open",
      "source": "whatsapp",
      "bot_paused": true,
      "last_message_at": "2026-05-06T19:32:00Z",
      "latest_message": { "sender": "client", "body": "¿A qué hora abren?", "sent_at": "..." },
      "latest_order": { "id": 12345, "status": "completed" },
      "unread_count": 3
    }
  ],
  "pagination": {...}
}
```

`latest_order` se resuelve con un batch lookup:
```php
$phones = $chats->pluck('client_phone')->unique();
$latest = Order::whereIn('client_phone', $phones)
    ->where('company_nit', $nit)
    ->latest('ordered_at')
    ->get(['id', 'status', 'client_phone'])
    ->groupBy('client_phone')
    ->map(fn ($g) => $g->first());
```
**Sin filtro de pago** — la última orden por `ordered_at desc` aunque sea cancelled o abandoned.

### 12.2 Detalle del chat (`GET /api/v1/chats/{id}`)

Permission: `chats.read,read`. Retorna chat + todos los mensajes ordenados por `sent_at asc`.

```json
{
  "data": {
    "id": 42,
    "client_phone": "573001112233",
    "client_name": "Laura Restrepo",
    "status": "open",
    "source": "whatsapp",
    "bot_paused": true,
    "messages": [
      {
        "id": 1,
        "sender": "client",
        "body": "Hola, quiero pedir",
        "status": null,
        "meta_message_id": "wamid.xxx",
        "media": [],
        "sent_at": "2026-05-06T19:32:00Z"
      }
    ]
  }
}
```

### 12.3 Detalle del cliente (`GET /api/v1/chats/{id}/client`)

Permission: `chats.read,read`. Retorna contacto + historial de órdenes (hasta 50, descendentes).

```json
{
  "data": {
    "contact": {
      "phone": "573001112233",
      "name": "Laura Restrepo",
      "notes": null,
      "first_seen": "...",
      "last_seen": "..."
    },
    "orders": [
      {
        "id": 12345,
        "status": "completed",
        "total": 53800,
        "items": [...],
        "ordered_at": "..."
      }
    ]
  }
}
```

UI: click en el nombre del header → abre `ClientDetailModal` con esta info. Cada orden del historial es clickeable y abre `OrderDetailModal`.

### 12.4 Enviar mensaje (`POST /api/v1/chats/{id}/messages`)

Permission: `chats.update,update`. FormRequest: `StoreChatMessageRequest`.

#### Body

```json
{ "body": "Hola Laura, tu pedido está listo." }
```

#### Validación

```php
'body' => ['required', 'string', 'min:1', 'max:4096'],  // límite WhatsApp
```

#### Lógica

```php
$message = ChatMessage::create([
    'chat_id' => $chat->id,
    'sender' => 'operator',
    'status' => 'sent',
    'body' => $validated['body'],
    'meta_message_id' => null,  // se actualiza cuando Meta confirma con response id
    'sent_at' => now(),
]);

$chat->update(['last_message_at' => now()]);

// Si la empresa tiene WhatsApp Cloud API conectado, envía
if ($whatsappAccount->isActive()) {
    try {
        $response = $this->outboundSender->sendText($nit, $chat->client_phone, $validated['body']);
        $message->update(['meta_message_id' => $response['messages'][0]['id'] ?? null]);
    } catch (\Throwable $e) {
        $message->update(['status' => 'failed']);
        Log::warning('WhatsApp send failed', ['chat_id' => $chat->id]);
    }
}
```

Si WhatsApp no está conectado, el mensaje se guarda igual (queda en BD para histórico) pero el cliente no lo recibe — la UI lo muestra con badge "no entregado".

### 12.5 Marcado como leído — doble chulito azul (`POST /api/v1/chats/{id}/mark-read`)

Permission: `chats.read,read`. Endpoint para sincronizar el "leído" con Meta cuando el operador efectivamente ve la conversación (no automáticamente al recibir el mensaje).

#### Condiciones de invocación (frontend)

```ts
useEffect(() => {
  if (!selectedChat || document.visibilityState !== 'visible') return;

  const lastClientMessage = messages.findLast(m =>
    m.sender === 'client' && m.meta_message_id
  );
  if (!lastClientMessage) return;

  apiFetch(`/api/v1/chats/${selectedChat.id}/mark-read`, { method: 'POST' });
}, [selectedChat, messages, document.visibilityState]);

useEffect(() => {
  const onVisibility = () => { /* re-trigger */ };
  document.addEventListener('visibilitychange', onVisibility);
  return () => document.removeEventListener('visibilitychange', onVisibility);
}, []);
```

3 condiciones simultáneas:
1. `selectedChat != null` (panel abierto).
2. `document.visibilityState === 'visible'` (pestaña activa).
3. Hay un `chat_messages` con `sender='client'` y `meta_message_id != null`.

#### Backend

```php
public function markRead(Request $request, int $id): JsonResponse {
    $chat = Chat::forCompany($nit)->findOrFail($id);

    // Validar setting
    if (!$companySettings->get('whatsapp_read_receipts', false)) {
        return response()->json(['skipped' => 'read_receipts_disabled']);
    }

    // Tomar el último mensaje cliente con meta_message_id
    $lastMessage = ChatMessage::where('chat_id', $id)
        ->where('sender', 'client')
        ->whereNotNull('meta_message_id')
        ->latest('sent_at')
        ->first();

    if (!$lastMessage) {
        return response()->json(['skipped' => 'no_client_messages']);
    }

    // Throttle: si ya marcamos este wamid hace <5 min, skip
    $cacheKey = "chat:{$id}:last_read_message_id";
    $lastMarked = Cache::get($cacheKey);
    if ($lastMarked === $lastMessage->meta_message_id) {
        return response()->json(['skipped' => 'throttled']);
    }
    Cache::put($cacheKey, $lastMessage->meta_message_id, 300);  // 5 min

    // Despacha el job
    MarkWhatsappMessageReadJob::dispatch($nit, $lastMessage->meta_message_id);

    return response()->json(['marked' => true, 'wamid' => $lastMessage->meta_message_id]);
}
```

**Acumulativo de Meta**: marcar el último wamid hace que Meta marque también todos los anteriores en la misma conversación. Por eso solo despachamos el job para el último.

**Throttle 5 min**: evita spam si el operador alterna pestañas rápido. Cache key: `chat:{id}:last_read_message_id`.

#### Setting `whatsapp_read_receipts`

- Almacenado en `company_settings` (key-value).
- **Default `false`** (privacy-by-default).
- Se controla desde `/company/whatsapp` → bloque "Preferencias" → toggle "Privacidad".

### 12.6 Reproductor de audio (notas de voz)

Componente `chat-message-media.tsx` con `<AudioPlayer>` interno. Estilo WhatsApp:

```ts
const animate = () => {
  if (!audio.paused) {
    setProgress((audio.currentTime / audio.duration) * 100);
    requestAnimationFrame(animate);  // ← más suave que onTimeUpdate
  }
};

audio.addEventListener('play', () => requestAnimationFrame(animate));
```

Features:
- **Barra de progreso animada** vía `requestAnimationFrame` (~60fps) en vez de `timeupdate` (~4fps).
- **Thumb circular** se desliza con el progreso.
- **Click en barra** para hacer seek.
- **Botón altavoz** (`Volume2` ↔ `VolumeX`) mute/unmute del clip actual.
- **Contador**: tiempo actual / total (`MM:SS / MM:SS`).

### 12.7 Webhook entrante de WhatsApp (lleno chats automáticamente)

`POST /api/v1/webhooks/whatsapp` — sin auth pero firmado HMAC. Maneja Meta Cloud API. Implementado en `WhatsappWebhookController::receive` y `WhatsappInboundMessageHandler`.

Ver sección 14 para detalle. Cada mensaje entrante crea/actualiza un `chat` con `bot_paused=true` (mientras n8n no esté operativo) y persiste el body en `chat_messages`.

### 12.8 Bot pausado y handoff

Mientras `n8n` no esté disponible, todos los chats nuevos llegan con `bot_paused=true`. El operador puede tomar la conversación manualmente (no se automatiza nada).

Endpoints para gestionar:

```
PATCH /api/v1/chats/{id}/bot
Body: { "bot_paused": false }
```

Permission: `chats.update,update`. Reanuda el bot (cuando esté operativo) o lo pausa para que el operador tome control.

```
PATCH /api/v1/chats/{id}/contact
Body: { "name": "Laura R.", "notes": "Cliente VIP" }
```

Permission: `chats.update,update`. Edita el contacto asociado al chat.

### 12.9 `ChatSourceBadge` (canal de origen)

Componente `chat-source-badge.tsx`. Diseño minimalista — sin icono, fondo `bg-muted/40` con borde, sólo el nombre del canal:

| source | Texto del badge |
|---|---|
| `whatsapp` | WhatsApp |
| `instagram` | Instagram |
| `facebook` | Facebook |
| `otro` | Otro |

Aparece en la lista (al lado del nombre del cliente) y en el header del chat activo.

### 12.10 Purga automática (`chats:purge-old`)

Cron: `dailyAt('03:00')` (3 AM diaria). Comando: `App\Console\Commands\PurgeOldChats`.

```php
public function handle(): int {
    $cutoff = now()->subDays(60);

    $deleted = Chat::where('last_message_at', '<', $cutoff)
        ->orWhereNull('last_message_at')
        ->delete();  // borra chats + cascade chat_messages

    // contacts y orders se preservan (FK no cascade)
    $this->info("Purged {$deleted} stale chats");
    return 0;
}
```

Borra `chats` con `last_message_at < now() - 60 días`. Las relaciones a `contacts` y `orders` se preservan (sólo el chat e historial de mensajes se limpian).

### 12.11 Polling (5s, `useChats`)

```ts
useEffect(() => {
  const tick = () => apiFetch('/api/v1/chats', { signal });
  tick();
  const interval = setInterval(tick, 5_000);
  return () => clearInterval(interval);
}, []);
```

5s polling siempre activo (operación crítica — los clientes esperan respuesta rápida). Evaluado bajar a 3s para más reactividad o mantener en 5s para reducir carga.

### 12.12 Endpoints de chats (resumen)

| Método | URL | Permission | Notas |
|---|---|---|---|
| GET | `chats` | `chats.read,read` | Lista con `?q=` |
| GET | `chats/{id}` | `chats.read,read` | Detalle con messages |
| POST | `chats/{id}/messages` | `chats.update,update` | Enviar respuesta |
| POST | `chats/{id}/mark-read` | `chats.read,read` | Doble chulito azul |
| GET | `chats/{id}/client` | `chats.read,read` | Contacto + historial órdenes |
| PATCH | `chats/{id}/bot` | `chats.update,update` | Pausar/reanudar bot |
| PATCH | `chats/{id}/contact` | `chats.update,update` | Editar contact |
| POST | `chats/{id}/menu-link` | `chats.update,update` (throttle 20/min) | Link corto de carta con sesión (`/menus?cart={uuid}`); al confirmar el pedido, el resumen se precarga en el chat |

---

## 12.bis CRM básico de clientes (`/clients`) — issue #123

Página: `pages/clients/index.tsx` (listado) + `pages/clients/show.tsx` (perfil).
Hooks: `useClients`, `useClient`. Controller: `App\Http\Controllers\Api\ClientController`.
Servicio: `App\Services\CrmService`.

**Diferencia con chats**: chats es transaccional (mensajes), CRM es analítico (KPIs por cliente). Las queries del CRM corren sobre `orders` agregadas (con `Cache::flexible`) y combinan datos de `contacts`, `client_notes` y `client_tags`.

### 12.bis.0 Modelo de datos

Identidad del cliente: `(company_nit, client_phone)`. No hay tabla canónica `clients`; el teléfono normalizado (`57XXXXXXXXXX`) es la llave informal que ya usan `orders.client_phone` y `contacts.phone`.

Tablas nuevas:
- `client_notes` — notas privadas (alergias, preferencias, quejas). Soft-delete (`deleted_at`) para auditoría legal. Sin `branch_id` por diseño: el CRM es cross-sede.
- `client_tags` — etiquetas configurables (`vip`, `domicilio`, etc.). UNIQUE en `(company_nit, client_phone, tag)`. Hard-delete; el audit_log preserva trazabilidad.

### 12.bis.1 Cross-sede por diseño

A diferencia de orders/chats/inventory, el CRM **no** requiere `branch.access`. Un teléfono es un cliente único para toda la empresa, sin importar en qué sede haya pedido. Esto permite, a futuro, cupones por cliente que apliquen en cualquier sede. Las queries usan `Order::withoutBranchScope()` y `Contact::withoutBranchScope()` para consolidar.

### 12.bis.2 Normalización de teléfono

`CrmService::normalizePhone()`:
- Quita `+`, espacios y caracteres no numéricos.
- Si tiene 10 dígitos y empieza con `3` (móvil CO), antepone `57`.
- Si ya tiene prefijo país, lo deja.
- Idempotente: `573001234567 → 573001234567`, `3001234567 → 573001234567`, `+57 300 123 4567 → 573001234567`.

### 12.bis.3 Segmentación automática

`CrmService::classifySegment()` clasifica en uno de 6 segmentos por reglas (no es ML, es heurística determinística):

| Segmento | Regla |
|---|---|
| `vip` | Top 10 por gasto en últimos 90 días (con gasto > 0) |
| `at_risk` | Total ≥ 4 órdenes y `cancellation_rate > 25%` |
| `inactive` | Última orden hace > 60 días |
| `new` | Primera orden < 30 días y ≤ 2 órdenes totales |
| `recurrent` | ≥ 3 órdenes en últimos 60 días |
| `regular` | Fallback |

TZ canónica: `America/Bogota` (consistente con `config('orders.timezone')`).

### 12.bis.4 Listado (`GET /api/v1/clients`)

Parámetros: `search` (nombre o phone, ILIKE), `segment`, `tag`, `page`, `per_page` (max 100).

Respuesta:
```json
{
  "data": [
    {
      "phone": "573001000113",
      "name": "Laura Restrepo",
      "total_orders": 23,
      "completed_orders": 21,
      "cancelled_orders": 2,
      "total_spent": 558900,
      "average_ticket": 26614,
      "last_order_at": "2026-05-08T19:42:00-05:00",
      "first_order_at": "2026-03-10T12:01:00-05:00",
      "orders_last_60d": 18,
      "spent_last_90d": 558900,
      "cancellation_rate": 0.087,
      "tags": ["vip", "domicilio"],
      "segment": "vip"
    }
  ],
  "meta": {
    "current_page": 1, "last_page": 5, "per_page": 25, "total": 125,
    "segments": ["vip","recurrent","new","inactive","at_risk","regular"],
    "available_tags": ["vip","domicilio","preferente"]
  }
}
```

Cache: `crm:list:base:{nit}` con `Cache::flexible([300, 1800])` — 5min fresh, 30min stale. Filtros se aplican sobre la lista cacheada en PHP (sin re-pegarle a la BD). Se invalida explícitamente al crear/eliminar nota o tag.

### 12.bis.5 Perfil consolidado (`GET /api/v1/clients/{contact}`)

Refactor #235: el param `{contact}` es `contacts.id`. Devuelve KPIs (mismos del listado) + historial de 50 últimas órdenes (matchea por `contact_id` y por `client_phone` legacy) + 20 últimos chats (cross-sede, por phone del Contact) + todas las notas + todas las tags. Si el contact no existe en la empresa activa, devuelve 404.

### 12.bis.6 Notas privadas

| Método | URL | Permission |
|---|---|---|
| POST | `/api/v1/clients/{contact}/notes` | `clients.update,update` |
| DELETE | `/api/v1/clients/{contact}/notes/{id}` | `clients.delete,delete` |

Validación: `note` requerido, 1–2000 chars. Soft-delete con auditoría completa (`client.note_created` / `client.note_deleted`) que incluye un excerpt de hasta 200 chars del contenido para reconstrucción ante disputa.

### 12.bis.7 Etiquetas

| Método | URL | Permission |
|---|---|---|
| POST | `/api/v1/clients/{contact}/tags` | `clients.update,update` |
| DELETE | `/api/v1/clients/{contact}/tags/{id}` | `clients.delete,delete` |

Validación: `tag` slug-style (`/^[a-z0-9_\-]+$/`), 1–50 chars. Se lowercases en `prepareForValidation`. Idempotente: si el tag ya existe (UNIQUE constraint), devuelve el row existente con 200 en vez de 201.

### 12.bis.8 Privacidad y validación cross-tenant

Refactor #235: el route model binding `Contact` ya garantiza que el contact exista; `ClientController::loadContactOrFail` valida adicionalmente que `company_nit` del contact coincida con `active_company_nit` del JWT. Aborta 404 si pertenece a otra empresa (no leak cross-tenant). `company_nit` siempre viene del JWT, jamás del body o path.

### 12.bis.9 Auditoría

Cada mutación queda en `audit_logs`:
- `client.created` — incluye `contact_id`, `doc_type`, `doc_number`, `client_phone`, `client_name`, `branch_id`.
- `client.updated` — mismos campos que created (sin `branch_id`).
- `client.merged` — `contact_id` del principal, `merged_contacts[]` (snapshot id/name/doc/phone/email de los absorbidos), `moved` (conteos reasignados por tabla).
- `client.note_created` — incluye `contact_id`, `client_phone`, `note_id`.
- `client.note_deleted` — incluye `contact_id`, `client_phone`, `note_id`, `note_excerpt`.
- `client.tag_added` — incluye `contact_id`, `client_phone`, `tag`.
- `client.tag_removed` — incluye `contact_id`, `client_phone`, `tag`.

### 12.bis.10 UI

- **Listado** (`/clients`): tabla con búsqueda con debounce 300ms (matchea nombre / documento / teléfono), filtros por segmento y tag, paginación. Refactor #235: la fila del cliente muestra nombre + documento + teléfono. Identidad canónica = `contacts.id`. Con `clients.delete`: checkboxes de selección + barra "Unificar (N)" → `MergeClientsDialog` para fusionar duplicados (radio elige el principal; pedidos/chats/notas/tags pasan al principal, los absorbidos se eliminan).
- **Detalle** (`/clients/{contact}`): header con nombre + documento + phone + segmento + KPIs, editor de tags, tabs (historial órdenes / chats / notas). Acción "Ver chat" abre `/chats?chat={id}` si el cliente tiene al menos una conversación.

### 12.bis.11 Endpoints (resumen)

| Método | URL | Permission |
|---|---|---|
| GET | `clients` | `clients.read,read` |
| POST | `clients` | `clients.create,create` (refactor #235: doc obligatorio, phone opcional) |
| GET | `clients/{contact}` | `clients.read,read` |
| PATCH | `clients/{contact}` | `clients.update,update` |
| POST | `clients/{contact}/merge` | `clients.delete,delete` (fusionar = eliminar duplicados) |
| POST | `clients/{contact}/notes` | `clients.update,update` |
| DELETE | `clients/{contact}/notes/{id}` | `clients.delete,delete` |
| POST | `clients/{contact}/tags` | `clients.update,update` |
| DELETE | `clients/{contact}/tags/{id}` | `clients.delete,delete` |

### 12.bis.12 Pendientes fuera de alcance

- Loyalty integration (issue #18): el `CrmService` aún no consume `loyalty_accounts`.
- Cupones específicos por cliente (asociados a un phone o a una tag).
- Exportación CSV del CRM.
- Métricas avanzadas: cohort retention, LTV, churn por mes.
- Acción "enviar mensaje ad-hoc desde CRM" (delega al panel `/chats`).

---

## 12.ter Fidelización con puntos (`/loyalty/reports`, integrado en `/clients/{contact}` y `/cart/{jwt}`) — issue #122

Programa de retención cross-sede. Una cuenta de puntos vive por `(company_nit, client_phone)` sin importar la sede donde el cliente pidió. El programa puede habilitarse/configurarse por empresa via `company_settings` (key prefix `loyalty.*`); defaults globales en `config/loyalty.php` y `.env` (`LOYALTY_*`).

### 12.ter.1 Modelos
- `loyalty_accounts(id, company_nit, client_phone, balance, lifetime_earned, tier, last_activity_at, timestamps)` — UNIQUE `(company_nit, client_phone)`.
- `loyalty_movements(id, loyalty_account_id, company_nit, type, points, reference_type, reference_id, actor_id, meta jsonb, created_at)` — **append-only** (`UPDATED_AT=null`). UNIQUE PARCIAL Postgres `(reference_type='order', reference_id, type='earn')` garantiza idempotencia del award.
- `loyalty_redemptions(id, loyalty_account_id, loyalty_movement_id, coupon_id, reward_key, points, status, expires_at, applied_at, applied_order_id, timestamps)` — vincula un canje con su Coupon temporal single-use.
- `coupons` extendida con `is_single_use`, `locked_to_phone`, `source` (cupones de canje tienen `source='loyalty_redeem'` y se ocultan de listados públicos de cupones disponibles).

### 12.ter.2 Reglas contables (CLAUDE.md compliance)
- Puntos **NO son moneda**. Nunca tocan `payment_receipts`.
- Refunds **totales** disparan `LoyaltyService::refundReverse()` que registra un movement `type=refund_reverse` con `points = -earn.points`. Refunds parciales NO reversan puntos (decisión pragmática; el incentivo del cliente se mantiene).
- Canje **consume puntos al crearse**. Si el cupón expira sin usarse, los puntos NO se devuelven (el evento financiero ya ocurrió). Si staff cancela un canje antes de aplicarse, sí se devuelven via `type=adjust` positivo equivalente.
- Todas las mutaciones bajo `DB::transaction` + `LoyaltyAccount::lockForUpdate()`.

### 12.ter.3 Earn automático
Tras `OrderController::closeWithPayment` (fuera de la transacción de cobro, para no reversar un pago válido si el programa falla), se invoca `LoyaltyService::award($order)`:
- Sólo si `loyalty.enabled` para la empresa y la orden tiene `client_phone`.
- `points = floor(orderTotal * points_per_cop * tier_multiplier)`. Multiplicador por tier (bronze 1.0x, silver 1.2x, gold 1.4x por default).
- Tier se recalcula tras cada earn: `tierFor(lifetime_earned)` recorre `config('loyalty.tiers')` ascendentemente.

### 12.ter.4 Canje
Catálogo en `config('loyalty.rewards')`. Cada reward tiene `key`, `points`, `discount_type` (`fixed_amount` en v1), `discount_value`, `min_order_amount`, `label`. Al canjear:
1. `LoyaltyService::redeem` descuenta puntos del balance.
2. Crea un `Coupon` con `scope='company'`, `max_uses=1`, `valid_until=now + redemption_expires_minutes`, `is_single_use=true`, `locked_to_phone=<phone>`, `source='loyalty_redeem'`, código autogenerado `LYL-XXXXXXXX`.
3. Persiste `LoyaltyRedemption` con `status='issued'`.
4. Cuando el cupón se aplica a una orden (vía `CouponService::redeemCoupon`), la redemption pasa a `applied` con `applied_order_id` fijo.

`Coupon::isValidFor()` rechaza el cupón si:
- `locked_to_phone` ≠ phone normalizado del checkout → "Este cupón solo puede ser usado por el cliente que lo canjeó."
- `is_single_use` y `uses_count >= 1` → "Este cupón ya fue usado."

### 12.ter.5 Endpoints
| Capa | Endpoint | Permiso |
|---|---|---|
| Staff | `GET /api/v1/loyalty/accounts` | `loyalty.read` |
| Staff | `GET /api/v1/loyalty/accounts/{phone}` | `loyalty.read` |
| Staff | `POST /api/v1/loyalty/accounts/{phone}/adjust` | `loyalty.update` |
| Staff | `POST /api/v1/loyalty/accounts/{phone}/redeem` | `loyalty.update` |
| Staff | `GET /api/v1/loyalty/reports/summary` | `loyalty.read` |
| Público | `POST /api/v1/public/loyalty/{nit}/{lookup,redeem}` | `throttle:loyalty-public` 10/min |
| Bot | `POST /api/external/loyalty/{lookup,redeem}` | `bot.jwt` |

`POST` para todos los lookups (incluso lectura) para mantener phones fuera de `access.log`.

### 12.ter.6 Ajustes manuales
`POST /api/v1/loyalty/accounts/{phone}/adjust` requiere `loyalty.update` + motivo (mín 3 chars) + tope `LOYALTY_MAX_MANUAL_ADJUST` (default 10.000 pts). Los ajustes positivos **no** suman a `lifetime_earned` (evita inflar tiers). Ajustes negativos no pueden dejar balance < 0. Quedan en `audit_logs` como `loyalty.adjusted` con `points`, `reason`, `balance_after`, `actor_id`.

### 12.ter.7 Expiración (`loyalty:expire-stale`)
Schedule diario 04:15 hora local. Por cada empresa con `loyalty.enabled`:
1. Marca como `expired` redenciones `issued` con `expires_at < now`.
2. Si `loyalty.expire_after_months > 0`: cuentas con balance > 0 y `last_activity_at` (o NULL) anterior a `now - N meses` se expiran completamente. Crea movement `type=expire` con `points = -balance` y reset balance a 0.

`--dry-run` salta la expiración pero igual marca redenciones vencidas. `--company={nit}` limita el alcance.

### 12.ter.8 UI staff (`/clients/{contact}` panel)
`LoyaltyPanel` se renderiza encima de las tabs si `loyalty.read`. Muestra saldo, tier badge, progreso a siguiente tier, catálogo de rewards (canjeables/bloqueados), historial de 50 movements, modales para ajustar puntos y para canjear en nombre del cliente. Tras canjear staff-side, se muestra el `coupon_code` para que el operador se lo dicte al cliente.

### 12.ter.9 UI cliente (`/cart/{jwt}`)
`LoyaltyCard` aparece sólo si el carrito tiene `client_phone`. 404 silencioso si el programa está deshabilitado (no revela existencia del cliente). Muestra saldo + progreso a siguiente tier + recompensas (deshabilita las que requieren mínimo mayor al subtotal actual). Al canjear, el `coupon_code` emitido se inyecta como `initialCode` al `CouponInput` para que el cliente sólo presione "Aplicar".

### 12.ter.10 Reportes (`/loyalty/reports`)
Sección con filtros `from`/`to` (default 30 días). KPIs del período (puntos otorgados/canjeados/expirados, clientes activos), tasa de canje (`applied/total` de redemptions), distribución de cuentas por tier, tabla ARPU por tier (revenue + ARPU calculado en SQL contra `orders.status=completed`), top 20 clientes por lifetime, panel de expiraciones. Todas las agregaciones en SQL.

### 12.ter.11 Configuración por empresa
Overrides en `company_settings`:
- `loyalty.enabled` (boolean)
- `loyalty.points_per_cop` (float)
- `loyalty.tiers` (JSON; mismo shape que `config/loyalty.php`)
- `loyalty.refund_reverses_points` (boolean)
- `loyalty.expire_after_months` (integer)

### 12.ter.12 Fuera de alcance v1 (follow-ups)
- Rewards de ítem gratis (sólo descuentos fijos v1).
- Reversa proporcional en refunds parciales.
- Comandos del bot (`/puntos`, `/canjear`): los endpoints existen en `/api/external/loyalty/*`, pero el parsing de intents en el flujo n8n queda fuera del repo.
- Notificaciones automáticas al cambiar de tier.
- Multimoneda (todo COP).

---

## 12.quater Alertas accionables de margen y costos (`/dashboard` + config en `/company/preferences`) — issue #124

Convierte el food cost y los datos de costos/stock en señales accionables: el dashboard muestra un feed de alertas con severidad, descripción y acciones cuando hay riesgo material en márgenes, alza de costos, platos sin ventas o stock por debajo del mínimo. Sin automatizaciones — el usuario decide y actúa manualmente desde la página correspondiente (`/menu`, `/inventory`, etc.).

### 12.quater.1 Modelos
- `alert_rules(id, company_nit, type, threshold decimal(12,4), period_days, enabled, notify_dashboard, notify_whatsapp, timestamps)` — UNIQUE `(company_nit, type)`. En v1 hay una sola fila por tipo por empresa. `notify_whatsapp` es placeholder para v2 (el feed siempre se renderiza si `notify_dashboard=true`).
- `alert_events(id, alert_rule_id, company_nit, type, severity, target_type, target_id, payload jsonb, triggered_at, dismissed_at, actioned_at, actioned_note, actioned_by, timestamps)`. UNIQUE PARCIAL `(alert_rule_id, target_type, COALESCE(target_id,''), DATE(triggered_at))` garantiza un solo evento por target por día — corridas adicionales del evaluador actualizan el evento existente en vez de duplicar.

### 12.quater.2 Tipos de regla
| Type | Threshold semántica | Severity |
|------|---------------------|----------|
| `margin_below` | Fracción de margen mínimo (0.30 = 30%) | critical si <70% del umbral, warning si no |
| `cost_increase` | Fracción de incremento (0.10 = +10%) ventana móvil | critical si >2× threshold, warning si no |
| `item_low_volume` | (no usa threshold) | info |
| `low_stock` | (no usa threshold; usa `min_stock` por insumo) | critical si stock=0, warning si stock≤min |

### 12.quater.3 Backend
- `app/Services/Alerts/AlertEngine.php` — orquestador.
- `app/Services/Alerts/Evaluators/{MarginBelow,CostIncrease,ItemLowVolume,LowStock}Evaluator.php` — strategy pattern. Cada evaluator devuelve `AlertEventDraft[]` con datos del snapshot.
- `app/Services/Alerts/AlertSeedService.php` — `ensureDefaults($nit)` crea las 4 reglas si no existen (margen 30%, incremento 10% / 7d, low volume 14d, low stock 1d). Idempotente.
- `app/Console/Commands/EvaluateAlertRulesCommand.php` — `alerts:evaluate [--company={nit}]`. Schedule diario 05:00 (después de food cost snapshot).
- Dedup: el engine usa `lockForUpdate` sobre el evento del día (si existe) y actualiza payload/severity. Cero duplicados aunque el cron corra N veces.

### 12.quater.4 Endpoints API
| Method | Path | Permiso | Notas |
|--------|------|---------|-------|
| GET | `/api/v1/alerts` | `reports.read` | Lista paginada. Filtros: `status` (`active`/`dismissed`/`actioned`/`all`), `severity`, `type`. Orden: severity (critical→info), luego `triggered_at desc`. |
| GET | `/api/v1/alerts/summary` | `reports.read` | Counts por severity para badge. |
| POST | `/api/v1/alerts/{id}/dismiss` | `reports.read` | Descarta. Idempotente. Audita `alert.dismissed`. |
| POST | `/api/v1/alerts/{id}/action` | `reports.read` | Marca como revisado con `note` opcional. Audita `alert.actioned`. |
| GET | `/api/v1/alert-rules` | `reports.read` | Devuelve las 4 reglas (autoseed). |
| PUT | `/api/v1/alert-rules/{type}` | `company.update` | Upsert. Valida threshold/period_days. Audita `alert_rule.updated`. |

### 12.quater.5 Permisos
- **Feed y endpoints de eventos**: `reports.read` (mismo gate que food cost / márgenes). Un cajero sin acceso a info financiera no ve el feed ni puede descartar alertas.
- **Configuración de reglas**: `company.update` (mismo gate que `/company/preferences`).
- `AlertEventPolicy` valida pertenencia del actor a la empresa del evento (defensa en profundidad).

### 12.quater.6 Frontend
- `resources/js/hooks/use-alerts.ts` — `useAlerts(status)` y `useAlertRules()`. Polling 5min en el feed.
- `resources/js/components/alerts/alerts-feed.tsx` — bloque embebido en `/dashboard` (sólo si `reports.read`). Por cada alerta: icono+color por severity, descripción human-readable, deep-link a `/menu` o `/inventory`, botones "Marcar revisado" y "Descartar".
- `resources/js/components/alerts/alert-rules-config.tsx` — Card embebida en `/company/preferences` (sólo si `company.update`). Una sección por tipo con enable/threshold/period_days. Threshold se muestra en % en la UI y se almacena como fracción.

### 12.quater.7 Auditoría
Eventos en `audit_logs`:
- `alert.dismissed` con `{type, severity, target}`.
- `alert.actioned` con `{type, severity, target, note}`.
- `alert_rule.updated` con `{type, before, after}`.

### 12.quater.8 Fuera de scope (v2)
- Canales WhatsApp y email (sólo dashboard en v1).
- Reglas custom totalmente libres (más de 4 tipos).
- Acciones automatizadas (retirar plato, subir precio, renegociar). Decisión explícita por riesgo de error.

---

## 12.quinquies Multi-bodega (`/company/warehouses`, `/inventory` con selector) — issue #120

Cada sede (`branches`, #117) puede tener N **bodegas** (`warehouses`): cocina, barra, congelador, almacén general. Los insumos no viven en la sede sino en una bodega específica. Stock, movimientos y valorización se calculan por bodega.

### 12.quinquies.1 Modelo de datos

| Tabla | Columnas clave | Notas |
|---|---|---|
| `warehouses` | `id uuid`, `company_nit`, `branch_id`, `name`, `slug`, `type` (cocina/barra/congelador/almacen), `is_default bool`, `archived_at` | UNIQUE `(branch_id, slug)`. Soft-archive. |
| `ingredient_stocks` | `ingredient_id`, `warehouse_id`, `on_hand_qty decimal(12,4)` | UNIQUE `(ingredient_id, warehouse_id)`. Migrado en `2026_05_11_000000_create_warehouses_block`. |
| `ingredient_movements` | `warehouse_id NOT NULL`, `type`, `quantity` (signed), `unit_cost`, `total_cost`, `reference_*` | FK con `restrictOnDelete`. Inmutable. |
| `warehouse_stock_snapshots` | `warehouse_id`, `snapshot_date`, `total_value decimal(14,2)`, `currency` | Generado diario por `inventory:snapshot-daily` (cron 02:30 Bogotá). |

### 12.quinquies.2 Permiso

`warehouses.manage` (asignado a owner+admin por default). Sin granularidad CRUD: el mismo permiso cubre todas las operaciones. Listar requiere ser miembro de la empresa con `branch.access`.

### 12.quinquies.3 Aislamiento

- **Mutaciones**: reciben `warehouse_id` explícito desde el payload. El backend valida que la bodega pertenezca a `active_branch_id` (vía `BranchScope` global + check explícito en `InventoryService::recordMovement` y `PurchaseService::receive`).
- **Listados**: por defecto filtran a las bodegas accesibles para `active_branch_id`. Vista consolidada por sede agrupa por `warehouse.type`.
- **Reportes** de food cost (#113) y menu engineering (#114) son por sede (suma todas las bodegas) — no se exponen costos por bodega individual en v1.

### 12.quinquies.4 Tipos de movimiento

Lista cerrada en `App\Models\IngredientMovement::TYPES` (`config/menu.php`):

- `entry` — entrada por compra (manual o desde `purchases.receive`). Quantity positiva, ajusta `current_avg_cost` ponderado.
- `waste` — merma. Quantity positiva en payload, signo negativo en la fila (decrece stock). Motivo obligatorio.
- `adjustment` — ajuste manual por conteo físico. Captura `new_on_hand` y backend calcula delta. Exige nota.
- `recipe_consumption` — consumo automático al cerrar orden con `closeWithPayment` (sólo si `MENU_AUTO_CONSUME_RECIPES=true` y la orden tiene receta vinculada). Quantity negativa.
- `transfer_in` / `transfer_out` — pareja generada por `InventoryTransferController::store`. Atomic en una transacción.

### 12.quinquies.5 Endpoints

| Método | Path | Permiso | Notas |
|---|---|---|---|
| GET | `/api/v1/company/warehouses?include_archived=1` | `warehouses.manage` read | Filtra a `active_branch_id`. |
| POST | `/api/v1/company/warehouses` | `warehouses.manage` create | Body: `name, slug?, type, is_default?`. Si `is_default=true`, downgrade del anterior default. |
| PATCH | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage` update | Update parcial. |
| DELETE | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage` delete | Soft archive. **Bloqueado si hay `on_hand_qty > 0`** en cualquier ingrediente — devuelve 422 con `WAREHOUSE_HAS_STOCK`. |
| POST | `/api/v1/inventory/transfers` | `inventory.update` | Body: `ingredient_id, from_warehouse_id, to_warehouse_id, quantity, notes?`. Atomic + `lockForUpdate` sobre `ingredient_stocks` de ambas bodegas. |

### 12.quinquies.6 Política operativa

- **Onboarding**: `CompanyEnrollmentController::store` crea sede principal + bodega `'Principal'` con `is_default=true` automáticamente. Sin esta bodega, las primeras compras no tendrían destino.
- **Transferencias**: si la bodega origen no tiene stock suficiente, 422 con `INSUFFICIENT_STOCK` (`Bodega origen no tiene suficiente {ingrediente}`). No se permiten transferencias **entre sedes** distintas (issue futura).
- **Costo promedio**: al recibir un movement `entry` (precio nuevo), `current_avg_cost` se recalcula como `(stock_anterior * costo_anterior + cantidad_entrada * costo_unitario_entrada) / (stock_anterior + cantidad_entrada)`. Esto preserva margen histórico al cambiar precios.
- **Snapshots**: si una bodega se archiva, sus `warehouse_stock_snapshots` previos permanecen (auditoría). Los snapshots nuevos dejan de generarse.

### 12.quinquies.7 UI

- **`/company/warehouses`** — listado por sede con badges (principal, archivada), modal crear/editar, toggle "Ver archivadas". Hook: `useInventory` (compartido).
- **`/inventory`** — selector de bodega en el header de la tabla de stock. Movements drawer muestra `warehouse_id` por fila. Transfer modal valida que sean bodegas de la misma sede.
- **`/purchases`** — al ejecutar `receive`, selector para enrutar el ingreso a una bodega específica (default = bodega default de la sede). Audita la elección.

### 12.quinquies.8 Auditoría

Eventos relevantes:

- `warehouse.created/updated/archived` — cambios de catálogo.
- `inventory.transfer` con metadata `{from_warehouse_id, to_warehouse_id, ingredient_id, quantity, unit_cost}`.
- `inventory.movement` con `warehouse_id` siempre presente.

### 12.quinquies.9 Fuera de alcance (v2)

- Transferencias entre sedes (requiere reglas contables adicionales: traslado de costo + posible IVA en factura interna).
- Reservas / picking (apartar stock para una orden futura).
- Min/max por bodega — hoy `min_stock_qty` vive a nivel ingrediente, no de bodega.
- Costo promedio por bodega — hoy el costo promedio es a nivel ingrediente (global). Si se quiere por bodega habría que cambiar `current_avg_cost` a tabla `ingredient_costs_per_warehouse`.

---

## 13. Carrito público (`/cart/{jwt}`)

Página: `pages/cart.tsx`. Hook: `useCart`. Controller: `App\Http\Controllers\Api\CartController`. Servicio: `App\Services\CartJwtService`. **Sin autenticación de empresa** — el CartJwt es la única credencial.

### 13.0 Modelos

#### `cart_sessions`

```sql
cart_sessions (
  id, company_nit,
  jwt_jti,                         -- UUID único del JWT (extraído del payload)
  client_phone,
  status,                          -- 'active' | 'converted' | 'abandoned' | 'expired'
  expired_at,                      -- timestamp
  coupon_code,                     -- nullable
  metadata,                        -- JSON
  created_at, updated_at,
  UNIQUE(jwt_jti)
)
```

#### `cart_items`

```sql
cart_items (
  id, cart_session_id,
  menu_item_id,                    -- string (id del item del menú)
  name, price, quantity, category,
  notes,
  created_at, updated_at
)
```

`name`, `price`, `category` son **snapshot** al momento de agregar — no se actualizan si el menú cambia. Esto preserva la integridad histórica del carrito.

### 13.1 Anatomía del CartJwt

Emitido por `CartJwtService::issue($companyNit, $clientPhone)`:

```json
{
  "jti": "uuid-...",
  "company_nit": "1",
  "client_phone": "573001112233",
  "iat": 1714999999,
  "exp": 1715004199                    // +70 min (CART_JWT_TTL)
}
```

- **Secret separado** (`CART_JWT_SECRET`) del de usuarios — no hay forma de cross-impersonate.
- TTL 70 min (`CART_JWT_TTL=4200`).
- `jti` es UUID que se persiste en `cart_sessions.jwt_jti` para idempotencia.

### 13.2 Endpoint de migración (`POST /api/v1/cart/migrate-jwt/{jwt}`)

**Sin auth, sin `company.access`.** El `{jwt}` en URL es la credencial.

#### Lógica

```php
public function migrateJwt(string $jwt): JsonResponse {
    try {
        $payload = $this->cartJwtService->verify($jwt);
    } catch (\Throwable) {
        return response()->json(['message' => 'Invalid cart token'], 401);
    }

    $session = CartSession::updateOrCreate(
        ['jwt_jti' => $payload['jti']],
        [
            'company_nit' => $payload['company_nit'],
            'client_phone' => $payload['client_phone'],
            'status' => 'active',
            'expired_at' => Carbon::createFromTimestamp($payload['exp']),
        ]
    );

    $items = $session->items()->get();

    return response()->json([
        'data' => [
            'session' => ['id' => $session->id, 'company_nit' => $session->company_nit],
            'items' => $items,
        ],
    ]);
}
```

### 13.3 Endpoint de refresh (`GET /api/v1/cart/{jwt}`)

Mismo flujo: verifica JWT, busca session por `jti`, retorna items. Devuelve 404 si la session no existe.

### 13.4 Aplicar cupón (`POST /api/v1/cart/apply-coupon`)

Body:
```json
{ "code": "BIENVENIDO", "cart_jwt": "..." }
```

Lógica:
1. Verifica `cart_jwt` → obtiene `company_nit`, `jti`.
2. Busca `CartSession::where('jwt_jti', $jti)`.
3. Valida cupón (mismo algoritmo que `validate` público — sección 10.4).
4. Si válido: `$session->update(['coupon_code' => $code])`.
5. Response incluye `discount_amount` calculado sobre el subtotal actual.

**Sólo un cupón a la vez por sesión** — sobrescribe el anterior si había.

### 13.5 Limitaciones del frontend

`pages/cart.tsx`:
- **No permite agregar/quitar items** — el carrito lo gestiona el bot/backend (cuando esté operativo).
- Sólo lectura: muestra items, total, descuento.
- Botón "Confirmar pedido" → POST a un endpoint del bot externo (no implementado en este repo) que crea la `Order` final.

### 13.6 Configuración

```env
CART_JWT_SECRET=...                # required
CART_JWT_TTL=4200                  # 70 min default
CART_BASE_URL=https://pedidos.flexyflow.co
```

**Resolución perezosa**: `CartController` resuelve `CartJwtService` vía `Container::make()` cuando lo necesita. Si `CART_JWT_SECRET` no está configurado:
- `$cartJwtService->verify()` lanza `RuntimeException`.
- El controller catchea y responde **401** con mensaje genérico (no 500).
- Mismo patrón que `ValidateBotJwt` para el bot externo.

### 13.7 Endpoints del carrito (resumen)

| Método | URL | Auth | Notas |
|---|---|---|---|
| POST | `cart/migrate-jwt/{jwt}` | CartJwt | Crea/actualiza session, retorna items |
| GET | `cart/{jwt}` | CartJwt | Refresh estado |
| POST | `cart/apply-coupon` | CartJwt | Aplicar cupón a session |

---

## 14. WhatsApp Cloud API (`/company/whatsapp`)

Onboarding y gestión del número de WhatsApp del restaurante (issue #77). Página: `pages/company/whatsapp.tsx`. Controllers: `App\Http\Controllers\Api\WhatsappAccountController`, `WhatsappVerificationController`, `WhatsappWebhookController`. Servicios: `App\Services\Whatsapp\{WhatsappAccountService, WhatsappInboundMessageHandler, WhatsappOutboundMessageSender, WhatsappSignatureValidator, WhatsappVerificationCodeService, MetaGraphApiClient}`. Policy: `App\Policies\WhatsappAccountPolicy`.

Mientras n8n no esté disponible, los mensajes entrantes caen al panel de chats con `bot_paused=true`. El flujo inbound (recibir mensajes y registrarlos en `chats`) **sí está operativo**; el outbound automatizado (bot que responde) no.

### 14.0 Modelos completos

#### `meta_platform_credentials`

Credenciales de la app de flexyflow en Meta (BSP — Business Solution Provider). Una fila por ambiente.

```sql
meta_platform_credentials (
  id, environment,                 -- 'qa' | 'production'
  app_id,                          -- Meta App ID (público)
  app_secret,                      -- ENCRYPTED
  business_id,                     -- Business Manager ID (público)
  system_user_id,                  -- ID del System User
  system_user_token,               -- ENCRYPTED, token never-expire
  config_id,                       -- ID de Embedded Signup config
  webhook_verify_token,            -- ENCRYPTED, token de handshake
  graph_api_version,               -- 'v25.0' por defecto
  is_active,                       -- bool
  created_at, updated_at
)
```

Casts encrypted aplicados: `app_secret`, `system_user_token`, `webhook_verify_token`.

Bootstrap: `MetaPlatformCredentialsSeeder` lee del `.env` y crea la fila activa para el ambiente actual. La fuente de verdad operativa es la BD, no el `.env`.

#### `company_whatsapp_accounts`

Una fila por empresa que tiene WhatsApp conectado.

```sql
company_whatsapp_accounts (
  id, company_nit,                 -- 1:1 con companies
  provisioning_mode,               -- 'embedded_signup' | 'naas'
  status,                          -- 'pending' | 'active' | 'disconnected' | 'suspended'
  waba_id,                         -- WhatsApp Business Account ID
  phone_number_id,                 -- ID del número (UNIQUE global, no por empresa)
  phone_e164,                      -- '+15556372625'
  display_name,                    -- 'SuperPasas'
  access_token,                    -- ENCRYPTED, token de la cuenta del cliente
  webhook_subscribed,              -- bool
  metadata,                        -- JSON
  deleted_at,                      -- soft delete
  created_at, updated_at,
  UNIQUE(phone_number_id) WHERE deleted_at IS NULL
)
```

`phone_number_id` UNIQUE global parcial → un número de WhatsApp sólo puede pertenecer a una empresa a la vez. Si un cliente cambia de plataforma, el viejo se soft-deletea y el nuevo se inserta.

#### `company_whatsapp_account_events`

Auditoría inmutable del ciclo de vida de la cuenta.

```sql
company_whatsapp_account_events (
  id, account_id,
  event_type,                      -- 'signup_completed' | 'swap_phone' | 'disconnected'
                                   -- 'verification_sent' | 'verification_used' | etc
  payload,                         -- JSON con datos del evento
  occurred_at, created_at
)
```

Sin `updated_at` ni `deleted_at`. Append-only. Útil para diagnosticar problemas con clientes y para responder a tickets de Meta.

#### `whatsapp_verification_codes`

Códigos OTP para acciones sensibles.

```sql
whatsapp_verification_codes (
  id, company_nit,
  action,                          -- 'connect' | 'swap_phone' | 'disconnect'
  code_hash,                       -- bcrypt del código de 6 dígitos
  reject_token,                    -- UUID para botón "No fui yo"
  attempts,                        -- int (max 3 antes de inválido)
  expires_at,                      -- now()+10min
  consumed_at,                     -- nullable
  rejected_at,                     -- nullable
  created_at, updated_at
)
```

### 14.1 Estado y gate de la página

```php
Route::get('company/whatsapp', function (FeaturePermissionService $featurePermission) {
    // ... extrae token + payload
    if (!$featurePermission->hasPermission($synthetic, 'whatsapp', 'read')) {
        return redirect()->route('dashboard')->with('jwt_token', $token);
    }
    return Inertia::render('company/whatsapp', [
        'token' => $token,
        'activeCompany' => resolveActiveCompany($payload),
    ]);
})->name('company.whatsapp');
```

Sin `whatsapp.read` → redirect a `/dashboard`. Antes la página renderizaba para todos y `GET /api/v1/whatsapp` fallaba con 403.

### 14.2 Estados visuales de la página

#### Sin conexión

```
┌─────────────────────────┐  ┌─────────────────────────┐
│ Conectar mi número      │  │ Solicitar número        │
│                         │  │                         │
│ [Conectar con Facebook] │  │ [Solicitar]             │
│                         │  │                         │
│ Trae tu número de WA    │  │ Te asignamos uno        │
└─────────────────────────┘  └─────────────────────────┘
```

#### Conectado

```
┌──────────────────────────────────────────────────────┐
│ 🟢 Conectado                                          │
│ Número: +57 300 111 2233                             │
│ Empresa: SuperPasas                                   │
│ [Cambiar número] [Desconectar]                       │
└──────────────────────────────────────────────────────┘

[Bloque Preferencias: Privacidad + Bot]
```

### 14.3 Embedded Signup (Opción A)

Frontend carga el SDK de Facebook:

```ts
// pages/company/whatsapp.tsx
useEffect(() => {
  const script = document.createElement('script');
  script.src = 'https://connect.facebook.net/en_US/sdk.js';
  script.async = true;
  document.body.appendChild(script);

  (window as any).fbAsyncInit = () => {
    FB.init({ appId: APP_ID, autoLogAppEvents: true, xfbml: true, version: 'v25.0' });
  };
}, []);

const onConnect = () => {
  FB.login((response) => {
    if (response.authResponse) {
      const { code } = response.authResponse;
      // Pedir OTP primero
      requestOtp(() => sendCallbackToBackend(code));
    }
  }, {
    config_id: META_CONFIG_ID,  // 941660645323511 (QA) o 2605276259869097 (PDN)
    response_type: 'code',
    override_default_response_type: true,
  });
};
```

Cuando Facebook completa el flow, el SDK retorna un `code` (no es OAuth directo — es el flow específico de Embedded Signup).

#### Endpoint `POST /api/v1/whatsapp/embedded-signup-callback`

Permission: `whatsapp.connect,create` + verificación OTP.

Body:
```json
{ "code": "AQB...", "phone_number_id": "1061107973753281", "waba_id": "1258801695847080" }
```

Header obligatorio: `X-Whatsapp-Verification-Code: 123456`.

#### Flow del callback

```php
public function embeddedSignupCallback(Request $request): JsonResponse {
    // 1. Valida OTP
    $this->verificationService->consume(
        $companyNit,
        'connect',
        $request->header('X-Whatsapp-Verification-Code')
    );

    // 2. Permission check (doble: middleware + assertPermission)
    $this->permissionService->assertPermission($request, 'whatsapp', 'connect');

    // 3. Token exchange con Meta
    $tokenResponse = $this->metaClient->exchangeCodeForToken($request->code);
    // → { "access_token": "...", "token_type": "bearer" }

    // 4. Subscribe el webhook al WABA
    $this->metaClient->subscribeWebhook($request->waba_id, $tokenResponse['access_token']);

    // 5. Persiste la cuenta
    $account = CompanyWhatsappAccount::updateOrCreate(
        ['company_nit' => $companyNit],
        [
            'provisioning_mode' => 'embedded_signup',
            'status' => 'active',
            'waba_id' => $request->waba_id,
            'phone_number_id' => $request->phone_number_id,
            'access_token' => $tokenResponse['access_token'],
            'webhook_subscribed' => true,
        ]
    );

    // 6. Audit event
    CompanyWhatsappAccountEvent::create([
        'account_id' => $account->id,
        'event_type' => 'signup_completed',
        'payload' => ['waba_id' => $request->waba_id, 'phone_number_id' => $request->phone_number_id],
    ]);

    return response()->json(['data' => $account->fresh()]);
}
```

### 14.4 Number as a Service (Opción B)

`POST /api/v1/whatsapp/naas-request` — el restaurante solicita que flexyflow le provisione un número.

Permission: `whatsapp.connect,create` + OTP.

Body:
```json
{
  "preferred_country_code": "57",
  "business_description": "Restaurante italiano de cena",
  "callback_email": "owner@restaurant.com"
}
```

Crea `company_whatsapp_accounts` con `status='pending'`, `provisioning_mode='naas'`. El equipo interno de flexyflow gestiona el provisioning manualmente y luego marca `status='active'`.

### 14.5 OTP (verificación por correo)

Servicio: `App\Services\Whatsapp\WhatsappVerificationCodeService`. Notificación: `App\Notifications\WhatsappActionVerificationCodeNotification`. Plantilla: `resources/views/emails/whatsapp/verification-code.blade.php`.

#### Solicitar código (`POST /api/v1/whatsapp/verification/request`)

Permission: `whatsapp.read,read`. Body: `{action: 'connect'|'swap_phone'|'disconnect'}`.

```php
public function request(Request $request): JsonResponse {
    $action = $request->input('action');
    $companyNit = $request->attributes->get('active_company_nit');

    // Rate limit: 3 códigos / 30 min por (company_nit, action)
    $key = "whatsapp_verification_rate:{$companyNit}:{$action}";
    if (RateLimiter::tooManyAttempts($key, 3)) {
        $seconds = RateLimiter::availableIn($key);
        return response()->json([
            'message' => "Demasiadas solicitudes. Intenta de nuevo en {$seconds}s.",
        ], 429);
    }
    RateLimiter::hit($key, 1800);  // 30 min decay

    // Genera código
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $rejectToken = Str::uuid()->toString();

    WhatsappVerificationCode::create([
        'company_nit' => $companyNit,
        'action' => $action,
        'code_hash' => Hash::make($code),
        'reject_token' => $rejectToken,
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
    ]);

    // Notifica al owner
    $owner = $companyService->getOwner($companyNit);
    $owner->notify(new WhatsappActionVerificationCodeNotification(
        $code, $action, $rejectToken
    ));

    return response()->json(['expires_in' => 600]);
}
```

#### Verificar código (`POST /api/v1/whatsapp/verification/verify`)

```php
public function verify(Request $request): JsonResponse {
    $action = $request->input('action');
    $code = $request->input('code');

    $entry = WhatsappVerificationCode::where('company_nit', $companyNit)
        ->where('action', $action)
        ->whereNull('consumed_at')
        ->whereNull('rejected_at')
        ->where('expires_at', '>', now())
        ->latest()
        ->first();

    if (!$entry) {
        return response()->json(['valid' => false, 'reason' => 'expired_or_not_found'], 422);
    }

    if ($entry->attempts >= 3) {
        return response()->json(['valid' => false, 'reason' => 'too_many_attempts'], 422);
    }

    $entry->increment('attempts');

    if (!Hash::check($code, $entry->code_hash)) {
        return response()->json(['valid' => false, 'reason' => 'wrong_code', 'remaining' => 3 - $entry->attempts], 422);
    }

    // Lo marca como "consumido" pendiente de uso (deferred consume)
    return response()->json(['valid' => true, 'token' => $entry->id]);
}
```

El consume real ocurre cuando el endpoint sensible (connect/swap/disconnect) procesa el header `X-Whatsapp-Verification-Code`.

#### Botón "No fui yo" (`GET /api/v1/whatsapp/verification/reject?token=...`)

**Endpoint público** — sin auth. El email contiene un link directo:

```
https://bistro.flexyflow.co/api/v1/whatsapp/verification/reject?token={uuid}
```

```php
public function reject(string $token): JsonResponse {
    $entry = WhatsappVerificationCode::where('reject_token', $token)
        ->whereNull('consumed_at')
        ->whereNull('rejected_at')
        ->first();

    if (!$entry) {
        return response()->json(['rejected' => false, 'reason' => 'token_not_found'], 404);
    }

    $entry->update(['rejected_at' => now()]);

    // Audit + email al owner notificando el reject
    return response()->json(['rejected' => true]);
}
```

Cuando el owner clickea, el código queda inválido permanentemente. No bloquea futuros códigos del mismo `action` — sólo invalida ese específico.

### 14.6 Acciones owner-only

#### Cambiar número (`DELETE /api/v1/whatsapp/phone`)

Permission: `whatsapp.swap_phone,delete` + Policy `swapPhone` + OTP.

```php
public function deletePhone(Request $request): JsonResponse {
    Gate::authorize('swapPhone', $account);  // Policy
    $this->verificationService->consume($companyNit, 'swap_phone', $request->header('X-Whatsapp-Verification-Code'));

    // Llama Meta para deregistrar el número
    $this->metaClient->deletePhoneNumber($account->phone_number_id, $account->access_token);

    // Setea status='disconnected' y nulifica phone_number_id
    $account->update([
        'status' => 'disconnected',
        'phone_number_id' => null,
        'phone_e164' => null,
    ]);

    // Evento auditable
    CompanyWhatsappAccountEvent::create([
        'account_id' => $account->id,
        'event_type' => 'swap_phone',
        'payload' => ['old_phone_number_id' => $oldPhoneNumberId],
    ]);

    return response()->json(['data' => $account]);
}
```

Después de esto, el restaurante debe hacer Embedded Signup nuevo con el número nuevo.

#### Desconectar cuenta (`DELETE /api/v1/whatsapp`)

Permission: `whatsapp.disconnect,delete` + Policy `disconnect` + OTP.

Soft-deletea `company_whatsapp_accounts.deleted_at = now()`. Mantiene `chats` y `chat_messages` históricos por compliance.

#### Policy `WhatsappAccountPolicy`

```php
public function swapPhone(User $user, CompanyWhatsappAccount $account): bool {
    $role = $user->roleIn($account->company_nit);
    return $role && $role->name === config('roles.role_names.owner');
}

public function disconnect(User $user, CompanyWhatsappAccount $account): bool {
    return $this->swapPhone($user, $account);  // mismo criterio
}
```

**Defensa en profundidad**: el RBAC ya marca `whatsapp.swap_phone` como `is_owner_only`, pero la Policy lo verifica también por nombre de rol. Aunque alguien manipulara la matriz de permisos para dar `swap_phone` a un admin, la Policy lo rechazaría.

### 14.7 Webhook entrante (`POST /api/v1/webhooks/whatsapp`)

**Sin auth, sin company.access**. Validado por HMAC.

#### Handshake (`GET`)

```
GET /api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=XXX&hub.challenge=YYY
```

```php
public function verify(Request $request) {
    if ($request->query('hub.mode') !== 'subscribe') return response('', 400);

    $expectedToken = $this->credentialsService->getActive()->webhook_verify_token;
    if ($request->query('hub.verify_token') !== $expectedToken) {
        return response('', 403);
    }

    return response($request->query('hub.challenge'));  // echo
}
```

#### Recepción (`POST`)

Validación HMAC SHA-256 con `META_APP_SECRET`:

```php
public function receive(Request $request): JsonResponse {
    $signature = $request->header('X-Hub-Signature-256');
    $body = $request->getContent();

    if (!$this->signatureValidator->isValid($signature, $body)) {
        return response()->json(['message' => 'Invalid signature'], 401);
    }

    $payload = $request->json()->all();

    // Persiste evento en webhook_events para idempotencia y replay
    WebhookEvent::firstOrCreate(
        ['source' => 'whatsapp', 'event_id' => $payload['entry'][0]['id'] ?? null],
        ['payload' => $payload]
    );

    // Despacha procesamiento
    $this->inboundHandler->handle($payload);

    return response()->json(['ok' => true]);
}
```

#### Resolución de empresa

```php
$phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');
$account = CompanyWhatsappAccount::where('phone_number_id', $phoneNumberId)
    ->where('status', 'active')
    ->first();
if (!$account) {
    Log::warning('Webhook received for unknown phone_number_id', ['id' => $phoneNumberId]);
    return;  // 200 OK pero ignora
}
$companyNit = $account->company_nit;
```

#### Procesamiento de mensaje

```php
foreach ($payload['entry'][0]['changes'][0]['value']['messages'] ?? [] as $message) {
    $wamid = $message['id'];

    // Idempotencia: si ya existe ese wamid, skip
    if (ChatMessage::where('meta_message_id', $wamid)->exists()) {
        continue;
    }

    // Crea o actualiza el chat
    $chat = Chat::firstOrCreate(
        ['company_nit' => $companyNit, 'client_phone' => $message['from']],
        [
            'client_name' => $contactName,
            'source' => 'whatsapp',
            'bot_paused' => true,  // mientras n8n no esté operativo
            'last_message_at' => now(),
        ]
    );

    // Persiste el mensaje
    ChatMessage::create([
        'chat_id' => $chat->id,
        'sender' => 'client',
        'body' => $message['text']['body'] ?? null,
        'meta_message_id' => $wamid,
        'sent_at' => Carbon::createFromTimestamp($message['timestamp']),
    ]);

    // Si tiene media, despacha job para descargar
    if (!empty($message['image']) || !empty($message['audio']) || !empty($message['document'])) {
        DownloadWhatsappMediaJob::dispatch($chat->id, $message);
    }
}
```

**Idempotencia por `meta_message_id`**: si Meta reenvía el mismo wamid (puede pasar en reintentos), no se duplica el mensaje en BD.

### 14.8 Bloque "Preferencias"

Bajo el header de estado en `/company/whatsapp`. Dos cards:

#### Privacidad

Toggle `whatsapp_read_receipts` (boolean, default `false`):
- **Activo**: cuando el operador efectivamente ve la conversación (panel abierto + tab visible), el cliente recibe doble chulito azul. Ver sección 12.5 para detalles del flow.
- **Inactivo (default)**: el cliente nunca ve doble chulito azul. El backend ni llama a Meta cuando recibe `mark-read`.

#### Bot de WhatsApp

Dos textos editables (texto plano, hasta 500 chars cada uno):

| Setting | Propósito | Placeholder soportado |
|---|---|---|
| `bot_welcome_message` | Mensaje de bienvenida cuando el cliente escribe por primera vez | `{company_name}` |
| `bot_away_message` | Mensaje cuando el restaurante está cerrado | `{company_name}` |

Vista previa: burbuja estilo WhatsApp en tiempo real mientras el operador escribe.

#### Persistencia (`PATCH /api/v1/companies/settings`)

Cada card guarda independientemente con PATCH parcial:

```json
PATCH /api/v1/companies/settings
{
  "whatsapp_read_receipts": true
}
```

Permission: `company.update,update`. Frontend muestra `can_update` para gating del botón "Guardar".

### 14.9 Configuración

```env
META_APP_ID=1265007232388204
META_APP_SECRET=...
META_BUSINESS_ID=929046296489964
META_SYSTEM_USER_ID=...
META_SYSTEM_USER_TOKEN=...               # never-expire
META_CONFIG_ID_QA=941660645323511        # Embedded Signup config QA
META_CONFIG_ID_PDN=2605276259869097      # Embedded Signup config prod
META_GRAPH_API_VERSION=v25.0
META_WEBHOOK_VERIFY_TOKEN_QA=...
META_WEBHOOK_VERIFY_TOKEN_PDN=...
```

La fuente de verdad operativa es `meta_platform_credentials` (cifrada en BD). El `.env` sólo se usa para bootstrap del seeder.

### 14.10 Endpoints de WhatsApp (resumen)

| Método | URL | Permission | Notas |
|---|---|---|---|
| GET | `whatsapp` | `whatsapp.read,read` | Estado actual de la cuenta |
| POST | `whatsapp/embedded-signup-callback` | `whatsapp.connect,create` + OTP | Conectar via FB SDK |
| POST | `whatsapp/naas-request` | `whatsapp.connect,create` + OTP | Solicitar número de flexyflow |
| DELETE | `whatsapp/phone` | `whatsapp.swap_phone,delete` + Policy + OTP | Cambiar número (owner only) |
| DELETE | `whatsapp` | `whatsapp.disconnect,delete` + Policy + OTP | Desconectar (owner only) |
| POST | `whatsapp/verification/request` | `whatsapp.read,read` | Solicitar OTP |
| POST | `whatsapp/verification/verify` | `whatsapp.read,read` | Verificar OTP |
| GET | `whatsapp/verification/reject?token=...` | público | Botón "No fui yo" |
| GET | `webhooks/whatsapp` | público | Handshake Meta |
| POST | `webhooks/whatsapp` | público (HMAC validado) | Eventos Meta |

---

## 15. Configuración de empresa

Páginas: `pages/company/settings.tsx` (Información + Facturación), `pages/company/preferences.tsx` (Preferencias). Controllers: `App\Http\Controllers\Company\CompanyController`, `App\Http\Controllers\Api\CompanySettingsController`, `App\Http\Controllers\Web\CompanyPreferencesController`. Servicio: `App\Services\CompanySettingsService`.

### 15.1 Información (`/company/settings`)

Permission gate web: `company.update,update`. Tabs:

#### Tab "Información" (icono `Building2`)

Endpoint: `GET /api/v1/company` y `PUT /api/v1/company`. FormRequest: `App\Http\Requests\Company\UpdateCompanyRequest`.

##### Campos editables

| Campo | Tipo | Validación | Notas |
|---|---|---|---|
| `commercial_name` | string | `required, max:100` | Reemite JWT al cambiar (sidebar) |
| `legal_name` | string | `required, max:150` | Razón social SAS |
| `bank_id` | int | `required, exists:banks,id` | Selector dinámico |
| `account_number` | string | `required, max:30` | Numérico, no validado strict |
| `account_type` | string | `required, in:corriente,ahorros` | — |
| `breb_key` | string | `nullable, max:50` | BREB key (Banco de la República) |
| `logo` | file | `nullable, mimes:png,jpg,jpeg,webp,svg, max:5120` | 5 MB |
| `qr_code` | file | `nullable, mimes:png,jpg,jpeg, max:5120` | 5 MB |

##### Campo NO editable

`nit` es la primary key y **nunca se actualiza**. No está en las reglas del FormRequest; cualquier intento de cambiar NIT es ignorado silenciosamente.

##### Reissue del JWT al cambiar `commercial_name`

```php
// CompanyController::update
if ($request->has('commercial_name') && $company->commercial_name !== $request->commercial_name) {
    $jwtService->reissueWithUpdatedCompanies($payload);
    Cookie::queue($jwtService->buildCookie($newToken));
}
```

Razón: el sidebar muestra el `commercial_name` (componente `RestaurantIdentity`) y se hidrata desde el JWT. Sin reissue, el sidebar mostraría el nombre viejo hasta el siguiente refresh natural.

##### Authorization en FormRequest

```php
public function authorize(): bool {
    return app(FeaturePermissionService::class)
        ->hasPermission($this, 'company', 'update');
}
```

Cualquier rol (incluso personalizado) con `company.update` puede editar. **No se compara por nombre de rol** — la validación es 100% RBAC.

#### Tab "Facturación" (icono `Receipt`)

Lista facturas + plan actual + banner de mora. Requiere permiso adicional `billing.read,read`. Sin ese permiso, el tab se oculta.

Sub-componentes:
- `SubscriptionCard`: plan actual (`/api/v1/billing/subscription`).
- `OverdueBanner`: visible si hay facturas overdue.
- Tabla paginada de `Invoice` (`/api/v1/billing/invoices`).

#### 15.1.bis Sección Impuestos (config tributario por empresa)

Sección colapsable en `pages/company/settings.tsx`. Permite parametrizar el régimen tributario sin tocar código:

| Campo | Tipo | Persistencia |
|---|---|---|
| `tax_regime` | enum: simple / inc_8 / iva_19 / iva_5 / iva_exento / custom | `companies.tax_regime` |
| `default_tax_rate` | decimal(5,2) — % aplicado a ítems sin override | `companies.default_tax_rate` |
| `default_tax_label` | varchar(60) — etiqueta legible (ej. "INC 8%") | `companies.default_tax_label` |
| `tax_included_in_price` | boolean — si los precios del menú ya incluyen impuesto | `companies.tax_included_in_price` |

Selector de régimen autocompleta `rate` y `label` según preset. Modo `custom` permite ingreso libre. Los presets vienen de `config/taxes.php` y se exponen vía `tax_presets` en la respuesta del backend.

**Override por ítem en menú**: el formulario de plato (`dish-form-modal.tsx`) tiene sección "Impuesto del ítem (opcional)" con inputs `tax_rate` y `tax_label`. Vacío = hereda del default de empresa. Útil para menús mixtos (bebida alcohólica IVA 19% mientras la comida lleva INC 8%).

**Snapshot inmutable a nivel de orden**: al crear una orden, el sistema persiste `tax_regime`, `tax_included_in_price`, `tax_rate` (effective ponderado) y `snapshot_default_tax_rate`. Cambios futuros en config de empresa no afectan órdenes históricas. `appendItems` usa el `snapshot_default_tax_rate` (no el effective) para coherencia con ítems mixtos.

### 15.2 Preferencias (`/company/preferences`)

Endpoint: `GET /api/v1/companies/settings` y `PATCH /api/v1/companies/settings`. Servicio: `CompanySettingsService`. FormRequest: `App\Http\Requests\Settings\UpdateCompanySettingsRequest`.

#### Allowlist de keys (`CompanySettingsService::ALLOWED_KEYS`)

Sólo estas keys se aceptan en `PATCH`. Cualquier otra → **422 con `key.invalid`**.

```php
public const ALLOWED_KEYS = [
    // Regional
    'timezone',
    'currency',
    'language',

    // Pedidos
    'delivery_area_km',
    'min_order_amount',
    'payment_methods',
    'payment_method_accounts',
    'order_auto_confirm',

    // Notificaciones
    'order_notify_customer_email',

    // WhatsApp (gestionados desde /company/whatsapp pero comparten endpoint)
    'whatsapp_read_receipts',
    'bot_welcome_message',
    'bot_away_message',

    // Branding del menú público
    'menu_primary_color',
];
```

#### Sección "Regional"

| Setting | Tipo | Validación | Default |
|---|---|---|---|
| `timezone` | string | `Rule::in(['America/Bogota'])` | `America/Bogota` |
| `currency` | string | `Rule::in(['COP'])` | `COP` |
| `language` | string | `Rule::in(['es'])` | `es` |

Hoy todos hardcoded a `es-CO`/`America/Bogota`/`COP`. Cuando se internacionalice, las allowlists se expanden.

#### Sección "Pedidos"

| Setting | Tipo | Validación | Default |
|---|---|---|---|
| `delivery_area_km` | int | `min:1, max:100` | 5 |
| `min_order_amount` | int | `min:0` | 0 |
| `payment_methods` | array | cada item `Rule::in(['efectivo','transferencia','tarjeta','nequi','daviplata'])` | `['efectivo','transferencia']` |
| `payment_method_accounts` | array | mapa `{method: accountInfo}` | `{}` |
| `order_auto_confirm` | bool | — | `false` |

#### Sección "Notificaciones"

| Setting | Tipo | Default |
|---|---|---|
| `order_notify_customer_email` | bool | `false` |

#### Branding del menú público

| Setting | Tipo | Validación | Default |
|---|---|---|---|
| `menu_primary_color` | string | regex `^#[0-9A-Fa-f]{6}$` | `#0052FF` |

Usado en `GET /api/v1/public/menu/{nit}` para que el frontend del menú público pinte botones/headers con el color de la marca.

#### `payment_method_accounts` (estructura)

Permite asociar a cada método de pago una info adicional:

```json
{
  "transferencia": { "bank": "Bancolombia", "account": "20012345678", "account_type": "corriente" },
  "nequi": { "phone": "3001112233" },
  "daviplata": { "phone": "3001112233" },
  "tarjeta": null,
  "efectivo": null
}
```

Validación lazy: cada método del array `payment_methods` debería tener entry en `payment_method_accounts` (warning, no error). El bot lee este JSON para mostrar al cliente cómo pagar.

### 15.3 Endpoint detalle de un setting (`GET /api/v1/companies/settings/{key}`)

Permission: `company.update,read`. Retorna sólo esa key (útil para componentes que sólo necesitan una):

```json
{ "data": { "key": "min_order_amount", "value": 25000 } }
```

### 15.4 Patch parcial (transacción)

```php
// PATCH /api/v1/companies/settings
public function update(UpdateCompanySettingsRequest $request): JsonResponse {
    $companyNit = $request->attributes->get('active_company_nit');
    $changes = $request->validated();

    DB::transaction(function () use ($companyNit, $changes) {
        foreach ($changes as $key => $value) {
            CompanySetting::updateOrCreate(
                ['company_nit' => $companyNit, 'key' => $key],
                ['value' => $value]
            );
        }
        Cache::forget("company_settings:{$companyNit}");  // invalida caché
    });

    audit('company.settings_updated', $actor, $company, ['changes' => array_keys($changes)]);

    return response()->json(['data' => $this->settingsService->all($companyNit)]);
}
```

Cache key: `company_settings:{nit}`. TTL: `COMPANY_SETTINGS_CACHE_TTL` (default 3600s).

### 15.5 Endpoints de empresa (resumen)

| Método | URL | Permission |
|---|---|---|
| GET | `/api/v1/company` | `company.update,read` |
| PUT | `/api/v1/company` | `company.update,update` |
| GET | `/api/v1/companies/settings` | `company.update,read` |
| PATCH | `/api/v1/companies/settings` | `company.update,update` |
| GET | `/api/v1/companies/settings/{key}` | `company.update,read` |
| GET | `/api/v1/companies/active` | jwt + company.access (sin permission, sólo membresía) |

---

## 16. Identidades — Usuarios y Roles

Páginas: `pages/users/Users.tsx`, `pages/roles/Roles.tsx`, `pages/roles/RoleEditor.tsx` (modal). Controllers: `UserRoleController`, `RoleController`, `InvitationController`. Servicios: `FeaturePermissionService`. Policy: `CompanyRolePolicy`.

### 16.1 Roles (`/identities/roles`)

Permission gate web: `roles.read,read`. Sin permiso → redirect a `/dashboard`.

#### Endpoint principal `GET /api/v1/roles`

```json
{
  "data": [
    {
      "id": 5,
      "name": "Propietario",
      "description": "...",
      "color": "#C0FD79",
      "is_system": true,
      "users_count": 1,
      "permissions": [
        { "feature_id": 1, "feature_slug": "orders",
          "can_create": true, "can_read": true, "can_update": true, "can_delete": true }
      ]
    }
  ],
  "can_manage": true,            // tiene roles.create/update/delete
  "permissions": {                // permisos del actor para los botones
    "canCreate": true,
    "canUpdate": true,
    "canDelete": true
  }
}
```

#### Crear rol (`POST /api/v1/roles`)

Permission: `roles.create,create`. FormRequest implícito en el controller:

```php
'name'        => ['required', 'string', 'max:50',
                   Rule::unique('company_roles')->where('company_nit', $nit)],
'description' => ['nullable', 'string', 'max:200'],
'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
'permissions' => ['required', 'array'],
'permissions.*.feature_id' => ['required', 'integer', 'exists:features,id'],
'permissions.*.can_create' => ['boolean'],
'permissions.*.can_read'   => ['boolean'],
'permissions.*.can_update' => ['boolean'],
'permissions.*.can_delete' => ['boolean'],
```

#### Validación crítica al crear/editar permisos

`UserRoleController::validatePermissionsAgainstActor` aplica al rol también: el actor no puede crear un rol con permisos que él mismo no tiene.

```php
foreach ($input['permissions'] as $perm) {
    foreach (['create', 'read', 'update', 'delete'] as $action) {
        if ($perm["can_{$action}"] ?? false) {
            if (!$this->permissionService->hasPermission($request, $featureSlug, $action)) {
                throw new HttpResponseException(response()->json([
                    'message' => "No puedes otorgar permisos que no posees: {$featureSlug}.{$action}"
                ], 403));
            }
        }
    }
}
```

#### Editar rol (`PUT /api/v1/roles/{id}`)

Permission: `roles.update,update`. Verifica policy `CompanyRolePolicy::update`:

```php
public function update(User $user, CompanyRole $role): bool {
    if ($role->is_system) return false;  // ← bloqueado
    return $user->hasPermission($role->company_nit, 'roles.update', 'update');
}
```

**Roles `is_system=true` no pueden editarse en absoluto.** Ni el owner. La UI los muestra como "Sistema" sin botón Edit.

#### Eliminar rol (`DELETE /api/v1/roles/{id}`)

Permission: `roles.delete,delete`. Bloqueado si:

```php
if ($role->is_system) {
    return response()->json(['message' => 'Roles del sistema no pueden eliminarse.'], 403);
}
if ($role->users()->count() > 0) {
    return response()->json([
        'message' => 'Tiene usuarios asignados. Cambia sus roles primero.'
    ], 409);
}
```

Audit: `role.deleted` con snapshot completo en `data.before`.

#### Color picker

Componente `RoleEditor.tsx` con:
- Swatches predefinidos: 8 colores (azul, verde, lima, ámbar, naranja, rojo, violeta, cian).
- Input `<input type="color">` nativo del navegador para personalizado.
- Validación frontend: regex `/^#[0-9A-Fa-f]{6}$/`. Backend re-valida.
- En modo creación, `color` se inicializa con un swatch aleatorio de la paleta para no obligar al operador a elegir.

#### Validación de nombre único (pre-submit)

`RoleEditor.tsx` recibe `existingRoles` desde `Roles.tsx` y valida en cliente que no exista otro rol con el mismo nombre (case-insensitive, trim). Bloquea el botón Guardar y muestra inline error antes de pegarle a la API. La unique key de BD sigue siendo el respaldo final.

#### Plantilla "Clonar permisos de…"

Selector arriba de la matriz que lista todos los roles existentes del companyNit (incluyendo `is_system`). Al elegir uno, sobreescribe la matriz con sus permisos como punto de partida — luego se ajusta a mano. Botón "Reiniciar" vuelve al estado original (vacío en creación, persistido en edición).

#### Bulk-toggle por columna CRUD

El header de `permissions-matrix` expone una caja tri-state (`none/some/all`) por cada acción (Crear, Leer, Actualizar, Eliminar). Marcarla activa la columna entera para todas las features; desmarcarla la apaga. Útil para roles "solo lectura" o "control total".

#### Tooltip por feature

Cada nombre de feature en la matriz dispara un tooltip con `feature.description` (si existe). Permite explicar qué desbloquea cada permiso sin saturar la UI.

#### Contador de usuarios por rol

`GET /api/v1/roles` incluye `users_count` por rol (vía `withCount('users')` — sin N+1). `Roles.tsx` muestra una columna "Usuarios" con badge verde si hay alguno asignado. La confirmación de delete advierte explícitamente cuántos usuarios tiene el rol antes de invocar el `DELETE` (que el backend rechaza con 422 si `users_count > 0`).

### 16.2 Usuarios (`/identities/users`)

Permission gate web: `users.read,read`. Página: `pages/users/Users.tsx`.

#### Endpoint principal `GET /api/v1/users`

```json
{
  "data": [
    {
      "id": 22,
      "email": "cristianmarint@gmail.com",
      "name": "Cristian Marín",
      "membership": {
        "company_role_id": 5,
        "role": {
          "id": 5,
          "name": "Propietario",
          "color": "#C0FD79",
          "is_system": true
        },
        "status": "active",            // 'active' | 'inactive'
        "custom_permissions": null      // null o JSONB con overrides
      },
      "joined_at": "..."
    }
  ],
  "available_roles": [...],             // para selector de invitar/cambiar
  "permissions": {
    "canInvite": true,
    "canUpdate": true,
    "canDelete": true
  },
  "actor_id": 22,                       // para gating "no me toco a mi mismo"
  "actor_permissions": [...]             // para validar scope al editar overrides
}
```

#### Invitar usuario (`POST /api/v1/invitations`)

Permission: `users.update,create`. Flujo:

```php
'email'           => ['required', 'email', 'max:150'],
'company_role_id' => ['required', 'integer',
                       Rule::exists('company_roles', 'id')->where('company_nit', $nit)],
```

```php
// Validaciones de negocio
if (User::where('email', $email)->whereHas('memberships', fn($q) =>
    $q->where('company_nit', $nit))->exists()) {
    return response()->json(['message' => 'El usuario ya es miembro.'], 409);
}

if (CompanyInvitation::where('company_nit', $nit)
    ->where('email', $email)
    ->where('status', 'pending')
    ->exists()) {
    return response()->json(['message' => 'Ya tiene una invitación pendiente.'], 409);
}

// Crea invitación
$invitation = CompanyInvitation::create([
    'company_nit' => $nit,
    'email' => $email,
    'company_role_id' => $companyRoleId,
    'token' => Str::random(64),
    'status' => 'pending',
    'expires_at' => now()->addDays(7),
]);

// Envía email con link
Mail::to($email)->send(new CompanyInvitationMail($invitation));

audit('invitation.created', $actor, $invitation, ['email', 'role']);
```

#### Cambiar rol (`PUT /api/v1/users/{id}/role`)

Permission: `users.update,update`. Body: `{company_role_id: int}`.

Validaciones:
- El actor no puede modificarse a sí mismo (`$actor->id !== $userId`).
- Bloqueo de degradación del último owner: si `userId` es el único miembro con rol `Propietario` (`is_system=true && name=Propietario`), no se puede degradar. Devuelve 409.

#### Editar permisos individuales (`PUT /api/v1/users/{id}/permissions`)

Permission: `users.update,update`. Body:

```json
{
  "custom_permissions": [
    {
      "feature_id": 12,
      "feature_slug": "whatsapp.swap_phone",
      "can_create": false, "can_read": true, "can_update": false, "can_delete": false
    }
  ]
}
```

Validación crítica (sección 0.6):

```php
// Para cada permiso que el actor INTENTA otorgar (true), valida que él lo tenga
foreach ($custom as $perm) {
    foreach (['create', 'read', 'update', 'delete'] as $action) {
        if ($perm["can_{$action}"] === true) {
            if (!$actorPermissionService->has($actorId, $nit, $perm['feature_slug'], $action)) {
                return response()->json([
                    'message' => "No puedes otorgar {$perm['feature_slug']}.{$action} que tú no posees."
                ], 403);
            }
        }
    }
}
```

**Sólo aplica a no-owners.** Si el target es owner del rol del sistema, el endpoint retorna 403 con `"No puedes editar permisos del Propietario."`.

#### Activar/desactivar (`PATCH /api/v1/users/{id}/status`)

Permission: `users.update,update`. Body: `{status: 'active'|'inactive'}`.

```php
$user = User::findOrFail($userId);
$membership = CompanyUser::where('company_nit', $nit)->where('user_id', $userId)->firstOrFail();

if ($actor->id === $userId) return response()->json(['message' => 'No puedes desactivarte a ti mismo.'], 422);

$membership->update(['status' => $request->status]);

if ($request->status === 'inactive' && config('auth.jwt.blacklist_enabled', true)) {
    $jwtService->invalidateUserActiveSession($user);
    // → busca el último UserActiveToken del user y lo blacklistea
    // → próximo request del usuario: 401 con "Sesión revocada por administrador"
}

audit('user.status_changed', $actor, $user, ['from', 'to']);
```

#### Eliminar miembro (`DELETE /api/v1/users/{id}`)

Permission: `users.update,delete`.

```php
$membership = CompanyUser::where('company_nit', $nit)->where('user_id', $userId)->firstOrFail();
$role = $membership->role;

// Bloqueo del último system role member
if ($role->is_system) {
    $count = CompanyUser::where('company_nit', $nit)
        ->where('company_role_id', $role->id)
        ->where('status', 'active')
        ->count();
    if ($count <= 1) {
        return response()->json([
            'message' => "No puedes eliminar al último miembro con rol {$role->name}."
        ], 409);
    }
}

if ($actor->id === $userId) return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], 422);

$membership->delete();
$jwtService->invalidateUserActiveSession($user);
audit('user.deleted', $actor, $user, ['membership_role' => $role->name]);
```

**Garantía**: siempre hay al menos un Propietario, un Administrador, y un Empleado activos. La UI muestra mensaje de bloqueo claro cuando se intenta romper esta regla.

#### Bulk actions (`POST /api/v1/users/bulk/role`)

Cambiar rol a múltiples usuarios en una operación:

```json
{ "user_ids": [22, 23, 24], "company_role_id": 5 }
```

Aplica las mismas validaciones que el cambio individual a cada uno. Si alguno falla, los demás sí se aplican (no es transaccional). Response:

```json
{
  "data": {
    "updated": 2,
    "failed": [{ "user_id": 24, "reason": "Cannot demote last owner" }]
  }
}
```

#### Polling

**No hay polling automático.** Sólo botón "Actualizar" (icono `RefreshCw`). Razón: las membresías cambian poco; reducir carga del backend.

#### Reactivación al cambiar de empresa

```ts
// pages/users/Users.tsx
useEffect(() => {
  fetchUsers();
}, [activeCompany?.nit]);  // ← watch
```

Cuando el operador cambia de empresa via `/auth/company-selector`, los users se refrescan automáticamente.

---

## 17. Facturación (`/billing`)

Página: `pages/billing/index.tsx`. Render web: `App\Http\Controllers\Billing\BillingController` (`routes/web.php` con name `billing`). API: `App\Http\Controllers\Api\BillingController`. Servicio: `App\Services\BillingService`. PDF: `App\Services\InvoicePdfService`. Notifications: `App\Notifications\{InvoiceGeneratedNotification, InvoiceOverdueNotification}`.

Permission gate web: `billing.read,read`. Sin permiso → redirect a `/dashboard`.

### 17.0 Modelos completos

#### `billing_plans` (catálogo de planes)

```sql
billing_plans (
  id, code,                        -- 'free' | 'pro' | 'enterprise' (slug único)
  slug, name,                      -- 'Free' | 'Pro' | 'Enterprise'
  description,
  price,                           -- numeric(10,2) en COP
  currency,                        -- 'COP'
  interval,                        -- 'monthly' | 'yearly' (sólo monthly hoy)
  features,                        -- JSONB: lista de strings descriptivos
  sort_order,                      -- int para ordenamiento UI
  is_active,                       -- bool
  created_at, updated_at,
  UNIQUE(slug)
)
```

Seedeados por `BillingPlanSeeder`:

| slug | name | price (COP) | features destacadas |
|---|---|---|---|
| `free` | Free | 0 | Hasta 50 órdenes/mes, 1 menú, sin WhatsApp |
| `pro` | Pro | 89 900 | Órdenes ilimitadas, 5 menús, WhatsApp Cloud, métricas |
| `enterprise` | Enterprise | 249 900 | Todo Pro + multi-sede, integraciones, soporte 24/7 |

#### `subscriptions`

```sql
subscriptions (
  id, company_nit,
  billing_plan_id,                 -- FK a billing_plans
  status,                          -- 'active' | 'paused' | 'cancelled' | 'expired'
  starts_at, ends_at,              -- ends_at null = abierto
  cancelled_at,
  created_at, updated_at
)
```

Una empresa tiene N suscripciones históricas pero **sólo una `active`** a la vez (constraint lógica del código, no UNIQUE en DB).

#### `subscription_discounts`

```sql
subscription_discounts (
  id, subscription_id,
  type,                            -- 'percentage' | 'fixed_amount'
  value,                           -- numeric(10,2)
  reason,                          -- string
  starts_at, ends_at,              -- vigencia
  created_at, updated_at
)
```

Aplicables al momento de generar factura. El cron `billing:expire-discounts` marca como `cancelled` los que tienen `ends_at < today`.

#### `invoices`

```sql
invoices (
  id, company_nit, subscription_id,
  type,                            -- 'monthly' | 'proration' | 'credit-note' | 'one_time'
  period_from, period_to,          -- DATE (rango cubierto)
  days_billed,                     -- int (días efectivos del período)
  base_amount,                     -- numeric(10,2) precio del plan
  discount_percent,                -- numeric(5,2) nullable
  discount_amount,                 -- numeric(10,2) nullable
  amount,                          -- numeric(10,2) total final (base - discount)
  currency,                        -- 'COP'
  due_date,                        -- DATE
  status,                          -- 'draft' | 'pending' | 'paid' | 'overdue' | 'voided'
  generated_at,                    -- timestamp
  voided_by_invoice_id,            -- FK a invoices (para credit-notes)
  pdf_path,                        -- nullable string
  created_at, updated_at
)
```

#### `invoice_lines` (desglose)

```sql
invoice_lines (
  id, invoice_id,
  description,                     -- 'Suscripción Plan Pro — Mayo 2026'
  quantity,                        -- 1 (default)
  unit_price,
  subtotal,                        -- quantity * unit_price
  created_at, updated_at
)
```

#### `invoice_payments`

```sql
invoice_payments (
  id, invoice_id, company_nit,
  registered_by,                   -- FK users (admin de plataforma que registró)
  amount,
  currency,
  payment_date,                    -- DATE
  payment_reference,               -- 'FLEXY-PAY-MAY26-1234' (referencia bancaria)
  payment_method,                  -- 'transferencia' | 'efectivo' | etc
  notes,
  created_at, updated_at
)
```

Una factura paga típicamente tiene 1 fila en `invoice_payments`. Si fue pago parcial, puede tener varias.

### 17.1 Suscripción activa (`GET /api/v1/billing/subscription`)

Permission: `billing.read,read`.

```json
{
  "data": {
    "id": 12,
    "plan": {
      "code": "pro",
      "name": "Pro",
      "price": 89900,
      "interval": "monthly",
      "features": ["Órdenes ilimitadas", "5 menús", "WhatsApp Cloud API", "Métricas avanzadas"]
    },
    "status": "active",
    "starts_at": "2026-02-01",
    "current_period": { "from": "2026-05-01", "to": "2026-05-31" },
    "next_invoice_date": "2026-06-20",
    "active_discount": null
  }
}
```

`current_period` se calcula desde `starts_at` + offset al mes corriente. Si `starts_at` fue el 15, los períodos son siempre del 15 al 14 del mes siguiente; si fue el 1, son del 1 al fin de mes.

**Uso DIAN del período en curso (`dian_usage`, #facturación-dian):** la respuesta real (`BillingController::subscription`) agrega un campo `dian_usage` — `null` salvo que el plan activo incluya `'dian'` en `features` (Plan Plus). Cuando aplica, trae `period_from`/`period_to` (mes calendario en curso), `unit_price` (`BILLING_DIAN_UNIT_PRICE`, default $10 COP IVA incluido), `total_documents`, `usage_amount`, `plan_amount`, `estimated_total` y `resolutions` (array con conteo por `dian_resolution_id`/`prefix`/`resolution_number`/`document_type`). Se muestra en `company/settings` → tab Facturación vía `DianUsageCard`, debajo de `SubscriptionCard`. `estimated_total` es informativo — no aplica descuento de promo, que solo se ve reflejado en el invoice real generado el día 1 (`BillingService::generateMonthlyInvoices` agrega una `InvoiceLine` por resolución con documentos > 0, sumando el cargo bruto sin descuento).

### 17.2 Banner de mora

Visible cuando `Company.status IN ('mora', 'delinquent')`. Calculado por el comando `billing:mark-overdue-invoices`.

| `Company.status` | Trigger | Banner DS | Bloquea acceso |
|---|---|---|---|
| `active` | Default. Empresa al día. | (sin banner) | no |
| `past_due` | ≥1 factura vencida y atraso ≤ `BILLING_PAST_DUE_GRACE_MONTHS` (3 meses default). | `PastDueBanner` global (`Alert variant="warning"`) con countdown desde día 1 hasta `expected_block_at`. | **no** — operación normal, sólo aviso. |
| `suspended` | Atraso > gracia. Aplicado por `BillingService::recalculateCompanyStatus()`. | `SuspendedBanner` global (`Alert variant="critical"`) con días vencido + monto adeudado + CTA. | **sí** — middleware `EnsureCompanyNotBlocked` (#175 + #193) gatea API y web. Sidebar se reduce a Dashboard + Mi empresa. |

#### Bloqueo a nivel HTTP (#193)

- **API**: `EnsureCompanyNotBlocked` (montado en el grupo de rutas autenticadas de `routes/api.php`) devuelve `403 + JSON` con `{ code: 'company_payment_blocked', status }`. Allow-list: `api.billing.*`, `api.companies.active`, `api.auth.logout`, `api.auth.switch-company`. El cliente API redirige a `/billing` al detectar el code (`resources/js/lib/api.ts`).
- **Web**: el mismo middleware se monta en el grupo `web` (`bootstrap/app.php` después de `HandleInertiaRequests`) y emite `302 → /dashboard` con flash `payment_blocked` cuando la ruta no está en la allow-list web. Allow-list: `dashboard`, `company.settings`, `company.preferences`, `company.under-review`, `billing`, `auth.*`, `password.*`, `verification.*`, `logout`, `login`, `register`, `home`, `me*`, `profile.*`, `appearance`, `pwa.*`, `health.*`, `storage-proxy`, `public.*`. El frontend lee `flash.payment_blocked` en `app-layout.tsx` y muestra un toast `error`.
- **Auditoría**: cada bloqueo registra `company.access_blocked_by_suspension` con metadatos `{route, user_id, company_nit, context}`. Throttle 1/min por user+ruta vía `Cache::add` para evitar flood de `audit_logs` (requiere cache store compartido en PDN — `CACHE_STORE` en redis/dynamodb).

#### Reactivación automática (#193)

- `companies:recalculate-statuses` corre cada 4h en `routes/console.php` con `onOneServer()+withoutOverlapping(30)`.
- Itera empresas en `past_due`/`suspended` por chunks de 200 y delega a `BillingService::recalculateCompanyStatus($company, now())`.
- Cubre tres transiciones:
  - `past_due → active`: cuando se liquidaron todas las facturas vencidas (tras aprobar comprobante de pago).
  - `past_due → suspended`: cuando `expected_block_at <= today` (gracia expirada).
  - `suspended → active`: cuando se liquida la deuda tras el bloqueo.
- Idempotente: el servicio interno hace `lockForUpdate + transaction` por empresa y no genera audit ni notificaciones si no hay cambio.
- Cuando el cliente sube comprobante y un admin lo aprueba, `BillingService::settleCompanyArrears()` recalcula de forma síncrona — el cron de 4h es el fallback.

#### Lógica del estado (`BillingService::recalculateCompanyStatus`)

```php
$target = match (true) {
    $trialActive => 'active',
    !$hasOverdue => 'active',
    $fresh->status === 'active' => 'past_due',
    $fresh->status === 'past_due' => $this->pastDueGraceExpired($fresh, $today, $graceMonths)
        ? 'suspended'
        : 'past_due',
    $fresh->status === 'suspended' => 'suspended',
    default => $fresh->status,
};
```

Los estados retirados `mora` y `delinquent` (modelo previo a #175) ya no existen — fueron colapsados en `past_due` con countdown.

### 17.3 Historial de facturas (`GET /api/v1/billing/invoices`)

Permission: `billing.read,read`. FormRequest: `GetInvoicesRequest`.

#### Query params

```
status:    pending | paid | overdue (si ausente: todos los activos, excluye voided)
page:      int
per_page:  int default 15, max 50
```

#### Response

```json
{
  "data": [
    {
      "id": 234,
      "type": "monthly",
      "period": { "from": "2026-05-01", "to": "2026-05-31" },
      "base_amount": 89900,
      "discount_percent": null,
      "discount_amount": null,
      "amount": 89900,
      "due_date": "2026-06-15",
      "status": "pending",
      "generated_at": "2026-05-20T03:00:00Z",
      "is_overdue": false,
      "days_overdue": 0
    }
  ],
  "pagination": { "current_page": 1, "last_page": 8, "per_page": 15, "total": 120 }
}
```

**Filtro automático**: `whereNotIn('status', ['voided'])` — las anuladas (notas de crédito aplicadas) se ocultan por defecto. Para verlas, pasar `?include_voided=true`.

#### Columnas de la tabla UI

| Tipo | Período | Monto | Vencimiento | Estado | Acciones |
|---|---|---|---|---|---|
| `InvoiceTypeChip` (Mensual/Prorrateo/Nota) | `formatInvoicePeriod(from, to)` | `formatCOP(amount)` con icono % si descuento | `formatDate(due_date)` | `InvoiceStatusBadge` | Detalle / Descargar PDF |

### 17.4 Detalle de factura (`GET /api/v1/billing/invoices/{id}`)

Permission: `billing.read,read`. Ownership: `Invoice::forCompany($nit)->findOrFail($id)`.

```json
{
  "data": {
    "id": 234,
    "type": "monthly",
    "period_from": "2026-05-01",
    "period_to": "2026-05-31",
    "days_billed": 31,
    "base_amount": 89900,
    "discount_percent": null,
    "discount_amount": null,
    "amount": 89900,
    "currency": "COP",
    "due_date": "2026-06-15",
    "status": "paid",
    "generated_at": "2026-05-20T03:00:00Z",
    "lines": [
      { "description": "Suscripción Plan Pro — Mayo 2026", "quantity": 1, "unit_price": 89900, "subtotal": 89900 }
    ],
    "payments": [
      {
        "id": 88,
        "amount": 89900,
        "payment_date": "2026-06-10",
        "payment_method": "transferencia",
        "payment_reference": "FLEXY-PAY-MAY26-4521",
        "registered_by": "admin@flexyflow.co"
      }
    ]
  }
}
```

### 17.5 Descarga de PDF (`GET /api/v1/billing/invoices/{id}/download`)

Permission: `billing.read,read`. Genera URL firmada y devuelve:

```json
{
  "data": {
    "url": "https://bistro.flexyflow.co/api/v1/billing/invoices/234/pdf?expires=...&signature=...",
    "expires_at": "2026-05-06T22:30:00Z"
  }
}
```

#### `GET /api/v1/billing/invoices/{id}/pdf` (servir PDF)

Sin permission middleware — la **URL firmada es la credencial**. Validación con `URL::hasValidSignature($request)`.

```php
public function servePdf(Request $request, int $id): StreamedResponse {
    if (!URL::hasValidSignature($request)) abort(403);

    $invoice = Invoice::findOrFail($id);  // No hay forCompany; URL firmada ya autorizó
    if (!$invoice->pdf_path || !Storage::disk(config('billing.storage_disk', 'private'))->exists($invoice->pdf_path)) {
        // Genera on-demand si no existe
        $invoice->pdf_path = $this->invoicePdfService->generate($invoice);
        $invoice->save();
    }

    return Storage::disk(config('billing.storage_disk'))->download($invoice->pdf_path, "factura-{$invoice->id}.pdf");
}
```

TTL de la firma: `BILLING_DOWNLOAD_TTL` (default 3600s = 1 hora). Después la URL deja de funcionar y hay que pedir una nueva.

### 17.6 Exportación CSV del historial (`GET /api/v1/billing/invoices/export.csv`)

Permission: `billing.read,read`. Servicio: `CsvExportService::exportInvoices`.

#### Columnas del CSV

```
ID, Tipo, Período Desde, Período Hasta, Días Facturados,
Plan, Monto Base, Descuento %, Descuento $, Monto Final,
Fecha Vencimiento, Estado, Fecha Generación, Fecha Pago, Referencia Pago, Método
```

Streaming chunked con BOM UTF-8 (`EF BB BF`) prefijado, sin cap de filas. Excluye `voided` por defecto (mismo filtro que la tabla).

### 17.7 Planes disponibles (`GET /api/v1/billing/plans`)

Permission: `billing.read,read` (sólo lectura).

```json
{
  "data": [
    {
      "code": "free",
      "name": "Free",
      "price": 0,
      "currency": "COP",
      "interval": "monthly",
      "features": ["Hasta 50 órdenes/mes", "1 menú activo", "Soporte por email"],
      "sort_order": 1,
      "is_current": false
    },
    {
      "code": "pro",
      "name": "Pro",
      "price": 89900,
      ...
      "is_current": true
    }
  ]
}
```

`is_current` se calcula contra la `Subscription` activa de la empresa.

**Cambio de plan**: NO implementado en este endpoint. La gestión de upgrade/downgrade la hace el panel de operador de flexyflow externo. El restaurante sólo ve.

### 17.8 Generación mensual de facturas (`billing:generate-monthly-invoices`)

#### Schedule

```php
// routes/console.php
Schedule::command('billing:generate-monthly-invoices')
    ->cron(sprintf('0 %d %d * *',
        config('billing.generate_hour', 3),    // BILLING_GENERATE_HOUR=3
        config('billing.generate_day', 20)     // BILLING_GENERATE_DAY=20
    ))
    ->timezone('UTC');
```

Por defecto: **día 20 del mes a las 3 AM UTC** (10 PM COL).

#### Algoritmo (`GenerateMonthlyInvoicesCommand::handle`)

```php
public function handle(BillingService $service): int {
    $now = now();

    Subscription::where('status', 'active')->chunk(100, function ($subs) use ($service, $now) {
        foreach ($subs as $sub) {
            try {
                $service->generateMonthlyInvoiceFor($sub, $now);
            } catch (\Throwable $e) {
                Log::error('Invoice generation failed', [
                    'subscription_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
                // Continúa con la siguiente — un fallo no aborta todo el batch
            }
        }
    });

    return 0;
}
```

#### `BillingService::generateMonthlyInvoiceFor($subscription, $now)`

```php
public function generateMonthlyInvoiceFor(Subscription $sub, Carbon $now): ?Invoice {
    $plan = $sub->plan;
    $month = $now->copy()->subMonth();  // factura del MES PASADO (post-paid)
    $periodFrom = $month->copy()->startOfMonth();
    $periodTo = $month->copy()->endOfMonth();
    $dueDate = $month->copy()->day(config('billing.due_day', 15))->addMonth();

    // 1. Idempotencia: no duplicar
    $existing = Invoice::where('subscription_id', $sub->id)
        ->where('period_from', $periodFrom->toDateString())
        ->where('period_to', $periodTo->toDateString())
        ->where('status', '!=', 'voided')
        ->first();
    if ($existing) return null;

    // 2. Días efectivos del período (prorrateo si la subscription empezó tarde)
    $effectiveStart = $sub->starts_at->gt($periodFrom) ? $sub->starts_at : $periodFrom;
    $effectiveEnd = $sub->ends_at && $sub->ends_at->lt($periodTo) ? $sub->ends_at : $periodTo;
    $daysBilled = $effectiveStart->diffInDays($effectiveEnd) + 1;
    $totalDays = $periodFrom->daysInMonth;

    // 3. Cálculo del monto base
    $baseAmount = $daysBilled === $totalDays
        ? $plan->price                                       // mes completo
        : round($plan->price * ($daysBilled / $totalDays));  // proration

    // 4. Aplicar descuentos vigentes
    $discount = $sub->discounts()
        ->where('starts_at', '<=', $periodTo)
        ->where(function ($q) use ($periodFrom) {
            $q->whereNull('ends_at')->orWhere('ends_at', '>=', $periodFrom);
        })
        ->orderByDesc('value')  // mejor descuento gana si hay varios
        ->first();

    $discountAmount = 0;
    $discountPercent = null;
    if ($discount) {
        if ($discount->type === 'percentage') {
            $discountPercent = $discount->value;
            $discountAmount = round($baseAmount * ($discount->value / 100));
        } else {
            $discountAmount = min($discount->value, $baseAmount);
        }
    }

    $amount = max($baseAmount - $discountAmount, 0);

    // 5. Crear invoice + lines
    $invoice = Invoice::create([
        'company_nit' => $sub->company_nit,
        'subscription_id' => $sub->id,
        'type' => $daysBilled === $totalDays ? 'monthly' : 'proration',
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'days_billed' => $daysBilled,
        'base_amount' => $baseAmount,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'amount' => $amount,
        'currency' => $plan->currency,
        'due_date' => $dueDate,
        'status' => $amount === 0 ? 'paid' : 'pending',  // si totalmente descontada, queda paga
        'generated_at' => $now,
    ]);

    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'description' => "Suscripción Plan {$plan->name} — {$month->translatedFormat('F Y')}",
        'quantity' => 1,
        'unit_price' => $baseAmount,
        'subtotal' => $baseAmount,
    ]);

    if ($discountAmount > 0) {
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => "Descuento: {$discount->reason}",
            'quantity' => 1,
            'unit_price' => -$discountAmount,
            'subtotal' => -$discountAmount,
        ]);
    }

    // 6. Generar PDF y notificar
    $invoice->pdf_path = $this->invoicePdfService->generate($invoice);
    $invoice->save();

    if (config('billing.notify_on_generate', true)) {
        $sub->company->owner->notify(new InvoiceGeneratedNotification($invoice));
    }

    audit('invoice.generated', null, $invoice, [
        'company_nit' => $sub->company_nit,
        'amount' => $amount,
        'days_billed' => $daysBilled,
    ]);

    return $invoice;
}
```

### 17.9 Marcar facturas vencidas (`billing:mark-overdue-invoices`)

#### Schedule

```php
Schedule::command('billing:mark-overdue-invoices')
    ->cron(sprintf('0 %d %d * *',
        config('billing.overdue_hour', 3),     // BILLING_OVERDUE_HOUR=3
        config('billing.overdue_day', 16)      // BILLING_OVERDUE_DAY=16
    ))
    ->timezone('UTC');
```

Día 16 del mes a las 3 AM UTC (después del día 15 que es default `due_day`).

#### Algoritmo

```php
public function handle(BillingService $service): int {
    $today = now()->startOfDay();

    $overdue = Invoice::where('status', 'pending')
        ->where('due_date', '<', $today)
        ->get();

    foreach ($overdue as $invoice) {
        $invoice->update(['status' => 'overdue']);

        if (config('billing.notify_on_overdue', true)) {
            $invoice->company->owner->notify(new InvoiceOverdueNotification($invoice));
        }

        audit('invoice.overdue', null, $invoice, [
            'days_overdue' => $today->diffInDays($invoice->due_date),
        ]);
    }

    // Update Company.status
    foreach ($overdue->pluck('company_nit')->unique() as $nit) {
        $service->updateCompanyStatusFromInvoices($nit);
    }

    return 0;
}
```

### 17.10 Expirar descuentos (`billing:expire-discounts`)

#### Schedule

```php
Schedule::command('billing:expire-discounts')
    ->cron('0 4 1 * *')  // día 1 de cada mes a 4 AM UTC
    ->timezone('UTC');
```

#### Algoritmo

```php
SubscriptionDiscount::where('ends_at', '<', now()->startOfDay())
    ->whereNull('expired_at')
    ->update(['expired_at' => now()]);

audit('discount.expired', null, null, ['count' => $affected]);
```

Los descuentos `expired_at IS NOT NULL` no se aplican en `generateMonthlyInvoiceFor`.

### 17.11 Notificaciones por correo

| Notification | Trigger | Canal | Recipientes |
|---|---|---|---|
| `InvoiceGeneratedNotification` | Después de generar invoice (si `BILLING_NOTIFY_ON_GENERATE=true`) | mail (queue) | Owner de la empresa |
| `InvoiceOverdueNotification` | Después de marcar overdue (si `BILLING_NOTIFY_ON_OVERDUE=true`) | mail (queue) | Owner de la empresa |

Las notifs van a `users.email` del owner. Si la empresa tiene varios owners (raro), va a todos.

### 17.12 Reglas de inmutabilidad

| Estado | Modificable? |
|---|---|
| `draft` | sí (placeholder, raramente usado) |
| `pending` | sólo `status → paid` o `voided` (nunca el monto) |
| `paid` | NO (excepto crear `credit-note` que la voida) |
| `overdue` | sólo `status → paid` |
| `voided` | NO |

**Notas de crédito** (`type='credit-note'`):
- Se crean cuando hay disputa/reembolso.
- Tienen `voided_by_invoice_id` apuntando a la factura original.
- La factura original cambia a `status='voided'`.
- Aparecen como ítem aparte en el historial.

### 17.13 Configuración

```env
BILLING_CURRENCY=COP                    # único soportado
BILLING_GRACE_MONTHS=2                  # meses de gracia antes de delinquent automático
BILLING_DUE_DAY=10                      # día default de vencimiento
BILLING_DIAN_UNIT_PRICE=10              # COP (IVA incl.) por documento DIAN emitido en el período — Plan Plus
BILLING_GENERATE_DAY=20                 # día de generación mensual
BILLING_GENERATE_HOUR=3                 # hora UTC
BILLING_OVERDUE_DAY=16                  # día de marcado overdue
BILLING_OVERDUE_HOUR=3                  # hora UTC
BILLING_NOTIFY_ON_GENERATE=true         # email al owner al generar
BILLING_NOTIFY_ON_OVERDUE=true          # email al marcar overdue
BILLING_DOWNLOAD_TTL=3600               # TTL URL firmada PDF (segundos)
BILLING_STORAGE_DISK=private            # disco para PDFs de facturas
PDF_DRIVER=dompdf                       # motor PDF
```

### 17.14 Endpoints de billing (resumen)

| Método | URL | Permission |
|---|---|---|
| GET | `/api/v1/billing/plans` | `billing.read,read` |
| GET | `/api/v1/billing/subscription` | `billing.read,read` |
| GET | `/api/v1/billing/invoices` | `billing.read,read` |
| GET | `/api/v1/billing/invoices/{id}` | `billing.read,read` |
| GET | `/api/v1/billing/invoices/{id}/download` | `billing.read,read` |
| GET | `/api/v1/billing/invoices/{id}/pdf` | URL firmada (TTL 3600s) |
| GET | `/api/v1/billing/invoices/export.csv` | `billing.read,read` |
| POST | `/api/v1/exports/billing/pdf` | `billing.read,read` |

---

## 18. Configuración personal (`/settings/*`)

Páginas: `pages/settings/{profile,password,appearance}.tsx` y `pages/me/index.tsx`. Layout: `layouts/settings/layout.tsx` con sidebar de subnavegación. Controllers: `App\Http\Controllers\Settings\{ProfileController, PasswordController}`.

Todas las rutas en este grupo usan middleware Laravel **`auth`** (sesión web tradicional o JWT por cookie HttpOnly), **sin** `company.access`. Esto permite que un usuario sin empresa activa (ej. todavía en enrollment) llegue a su perfil.

### 18.1 Perfil (`/settings/profile`)

Página: `pages/settings/profile.tsx`. Layout: `SettingsLayout`. Tres secciones independientes:

#### Sección "Información del perfil"

| Campo | Tipo | Validación | Notas |
|---|---|---|---|
| `name` | string | `required, string, max:255` | Nombre completo legacy |
| `email` | string | `required, email, max:255, unique:users,email,{$id}` | Si cambia, `email_verified_at` se nulifica |

##### Endpoint `PATCH /settings/profile`

Controller: `ProfileController::update`. FormRequest: `App\Http\Requests\Settings\ProfileUpdateRequest`.

```php
public function update(ProfileUpdateRequest $request): RedirectResponse {
    $user = $request->user();
    $user->fill($request->validated());

    if ($user->isDirty('email')) {
        $user->email_verified_at = null;  // ← obliga re-verificación
    }

    $user->save();

    return redirect()->route('profile.edit')->with('status', 'profile-updated');
}
```

Si el email cambió, el usuario debe verificar el nuevo (Laravel envía mail automáticamente vía `MustVerifyEmail` interface).

#### Sección "Verificación de email"

Visible sólo si `email_verified_at IS NULL`. Botón "Reenviar verificación" → `POST /email/verification-notification` (`EmailVerificationNotificationController@store`). Rate limit `throttle:6,1`.

#### Sección "Eliminar cuenta" (zona destructiva)

Componente `DeleteUser` (`resources/js/components/delete-user.tsx`). Texto en rojo "Esta acción es irreversible".

##### Endpoint `DELETE /settings/profile`

Middleware: `auth, password.confirm`. Es decir, antes de poder hacer este request el usuario debe haber confirmado password en los últimos 3 horas (`auth.password_timeout`).

Para Google OAuth users sin password, el confirm-password no aplica directamente — pero el endpoint sí pide validación de campo:

```php
public function destroy(Request $request): RedirectResponse {
    if ($request->user()->password) {  // legacy con password
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);
    } else {
        // Google OAuth: pide confirmación textual
        $request->validate([
            'confirmation' => ['required', 'in:ELIMINAR'],
        ]);
    }

    $user = $request->user();
    Auth::logout();
    $user->delete();  // hard delete (no SoftDeletes en User)

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    audit('user.deleted', $user, $user, ['self_deletion' => true]);

    return Redirect::to('/');
}
```

**Cascade**: al borrar `users`, las FK `CompanyUser`, `UserAcceptance`, `UserActiveToken` se borran por `ON DELETE CASCADE`. Las órdenes/chats que apuntaban al user no — quedan con `user_id = null` o sin asociación.

**Auto-eliminación bloqueada en algunas vistas**: el usuario no puede eliminarse si es el último Propietario de alguna empresa (validación lazy en el endpoint).

### 18.2 Cambiar contraseña (`/settings/password`)

Página: `pages/settings/password.tsx`. Sólo aplicable a usuarios legacy con `users.password IS NOT NULL`.

#### Endpoint `PUT /password`

Controller: `Auth/PasswordController::update` (parte de Breeze).

```php
public function update(Request $request): RedirectResponse {
    $validated = $request->validateWithBag('updatePassword', [
        'current_password' => ['required', 'current_password'],
        'password' => ['required', Password::defaults(), 'confirmed'],
    ]);

    $request->user()->update([
        'password' => Hash::make($validated['password']),
    ]);

    return back()->with('status', 'password-updated');
}
```

Reglas:
- `current_password` valida contra hash actual de `users.password`.
- `password` debe cumplir `Password::defaults()` (Laravel: min:8, mixed case, números, símbolos según config).
- `password_confirmation` debe coincidir.

**Para Google OAuth users**: no pueden establecer password en este flujo. La página los redirige a `/settings/profile` con un mensaje "Tu cuenta usa Google OAuth, no tienes contraseña".

### 18.3 Apariencia (`/settings/appearance`)

Página: `pages/settings/appearance.tsx`. **Sin endpoints API** — sólo localStorage.

#### Hook `useAppearance`

```ts
type Appearance = 'light' | 'dark' | 'system';

const useAppearance = () => {
  const [appearance, setState] = useState<Appearance>(() =>
    (localStorage.getItem('appearance') as Appearance) ?? 'system'
  );

  const updateAppearance = (mode: Appearance) => {
    localStorage.setItem('appearance', mode);
    setState(mode);
    applyTheme(mode);
  };

  return { appearance, updateAppearance };
};

const applyTheme = (mode: Appearance) => {
  const root = document.documentElement;
  if (mode === 'system') {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.classList.toggle('dark', prefersDark);
  } else {
    root.classList.toggle('dark', mode === 'dark');
  }
};
```

`<html class="dark">` → Tailwind activa el modo oscuro vía `dark:` variants. Sin server-side; el SSR de Inertia se hidrata en `light` y al montar React aplica el tema.

#### Componentes selectores

- `appearance-tabs.tsx`: 3 botones segmentados (Light / Dark / System).
- `appearance-dropdown.tsx`: variante dropdown del mismo, usado en el header móvil.

### 18.4 Vista de perfil pública (`/me`)

Página: `pages/me/index.tsx`. Endpoint: `GET /api/v1/me`.

Vista **sólo lectura** — para acciones de edición se usa `/settings/profile`.

#### Response

```json
{
  "data": {
    "id": 22,
    "name": "Cristian Marín",
    "first_name": "Cristian",
    "last_name": "Marín",
    "email": "cristianmarint@gmail.com",
    "cedula": "1010100001",
    "email_verified_at": "2026-04-15T19:32:00Z",
    "active_company": {
      "nit": "1",
      "commercial_name": "SuperPasas",
      "role": {
        "name": "Propietario",
        "color": "#C0FD79",
        "is_system": true
      }
    },
    "memberships_count": 1
  }
}
```

`MeController::destroy` (DELETE `/api/v1/me`) es la versión API del eliminar cuenta — mismo flow que `/settings/profile` DELETE pero retorna JSON.

### 18.5 Tabla de endpoints de configuración personal

| Método | URL | Auth | Notas |
|---|---|---|---|
| GET | `/settings/profile` | `auth, verified` | Renderiza `profile.tsx` |
| PATCH | `/settings/profile` | `auth` | Update name + email |
| DELETE | `/settings/profile` | `auth, password.confirm` | Eliminar cuenta |
| GET | `/settings/password` | `auth, verified` | Renderiza `password.tsx` |
| PUT | `/password` | `auth` | Cambiar password (legacy) |
| GET | `/settings/appearance` | `auth` | Renderiza `appearance.tsx` |
| GET | `/settings` | `auth` | Redirect a `/settings/profile` |
| GET | `/me` | `auth` (Inertia) | Renderiza `me/index.tsx` |
| GET | `/api/v1/me` | `jwt` | API de perfil |
| DELETE | `/api/v1/me` | `jwt + password.confirm` | API eliminar cuenta |

---

## 19. Atajos de teclado

Mapa canónico en `src/lib/shortcuts.ts`. Motor de secuencias en `src/components/global-shortcuts.tsx` (montado en el shell autenticado). Inactivos cuando el foco está en input/textarea/contenteditable.

**Rediseño anti-conflictos (#50):** la navegación usa el patrón **"go to" con tecla líder `G`** — se pulsa `G` y, dentro de 1.5s, la tecla del destino (ej. `G` luego `D` → Dashboard). Antes era `Alt+<letra>`, que choca con comandos del navegador y del SO: en macOS `Option+<letra>` inserta caracteres especiales (µ, π…) y en Firefox/Windows `Alt+<letra>` activa los mnemónicos de menú. Al no usar modificadores, las secuencias no se cruzan con navegador, Windows/macOS ni otros programas. Los pocos acordes que quedan se validan contra `RESERVED_SHORTCUTS` (`hooks/use-keyboard-shortcut.ts`).

**Palette al sostener `G` (`ShortcutPalette`):** si en vez de un tap rápido se **mantiene pulsada** la tecla `G` (~350ms), aparece un overlay que oscurece la UI y lista los destinos con su segunda tecla; se elige sin soltar `G`, o se cierra al soltar / con `Esc`. Cubre el caso de quien no recuerda el atajo. Maneja auto-repeat del teclado y el `blur` de ventana (Alt+Tab) para no quedar pegado.

| Atajo | Sección | Ruta |
|-------|---------|------|
| `G` luego `D` | Dashboard | `/dashboard` |
| `G` luego `O` | Órdenes › Tablero | `/orders/board` |
| `G` luego `C` | Órdenes › Caja | `/orders/cashier` |
| `G` luego `V` | Órdenes › Ventas del día | `/orders/deliveries` |
| `G` luego `M` | Menú | `/menu` |
| `G` luego `S` | Chats | `/chats` |
| `G` luego `P` | Cupones | `/coupons` |
| `G` luego `H` | Horarios | `/hours` |
| `G` luego `E` | Mi Empresa › Información | `/company/settings` |
| `G` luego `F` | Mi Empresa › Configuraciones | `/company/preferences` |
| `G` luego `T` | Mi Empresa › Métricas | `/company/metrics` |
| `G` luego `R` | Mi Empresa › Informes | `/company/reports` |
| `G` luego `U` | Identidades › Usuarios | `/identities/users` |
| `G` luego `L` | Identidades › Roles | `/identities/roles` |
| `Ctrl/Cmd + .` | Toggle barra lateral | — |
| `?` | Modal de ayuda | — |

---

## 20. Modelo de permisos (RBAC) — Tabla consolidada

Esta sección consolida la matriz completa: cada feature × cada acción × cada rol del sistema, con el endpoint que la consume y el comportamiento exacto en runtime.

### 20.0 Algoritmo `FeaturePermissionService::hasPermission`

```php
public function hasPermission(Request $request, string $featureSlug, string $action): bool {
    $companyNit = $request->attributes->get('active_company_nit');
    $jwtPayload = $request->attributes->get('jwt_payload');
    $userId = $jwtPayload['sub'] ?? null;

    // 1. Membresía existe?
    $membership = CompanyUser::where('company_nit', $companyNit)
        ->where('user_id', $userId)
        ->where('status', 'active')
        ->with('role')
        ->first();
    if (!$membership) return false;

    // 2. Rol del sistema → BYPASS TOTAL (excepto owner_only)
    if ($membership->role->is_system) {
        $feature = Feature::where('slug', $featureSlug)->first();
        if ($feature?->is_owner_only) {
            // Owner-only requiere rol específico de Propietario
            return $membership->role->name === config('roles.role_names.owner');
        }
        return true;
    }

    // 3. Override individual del usuario?
    if (!empty($membership->custom_permissions)) {
        foreach ($membership->custom_permissions as $override) {
            if ($override['feature_slug'] === $featureSlug) {
                return (bool) ($override["can_{$action}"] ?? false);
            }
        }
    }

    // 4. Permiso de matriz del rol
    $feature = Feature::where('slug', $featureSlug)->first();
    if (!$feature) return false;

    $perm = CompanyRolePermission::where('company_role_id', $membership->company_role_id)
        ->where('feature_id', $feature->id)
        ->first();

    return $perm ? (bool) $perm["can_{$action}"] : false;
}
```

**Orden de evaluación (primer match gana):**
1. Sin membresía activa → `false`.
2. Rol `is_system=true` + feature **NO** `is_owner_only` → `true` (bypass).
3. Rol `is_system=true` + feature **SÍ** `is_owner_only` → sólo si rol es exactamente `Propietario`.
4. Override individual existe → usa el override.
5. Matriz del rol.

### 20.1 Matriz CRUD completa (default templates)

Leyenda:
- `✓` — permiso concedido por default.
- `✗` — denegado.
- `🔒` — `is_owner_only=true` (no se puede otorgar a admin/employee aunque tengan otros permisos).
- `cfg` — configurable por la empresa (no en template default, pero la UI permite activarlo).

| Feature | Slug | Owner C/R/U/D | Admin C/R/U/D | Employee C/R/U/D | Endpoints protegidos |
|---|---|:---:|:---:|:---:|---|
| Pedidos (lectura) | `orders.read` | —/✓/—/— | —/✓/—/— | —/cfg/—/— | `GET /api/v1/orders`, `GET /api/v1/orders/{id}`, web gate `/orders/cashier`, `/orders/deliveries` |
| Pedidos (crear) | `orders.create` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/orders` |
| Pedidos (actualizar) | `orders.update` | —/—/✓/— | —/—/✓/— | ✗ | `PATCH /api/v1/orders/{id}/status` |
| Pedidos (eliminar) | `orders.delete` | —/—/—/✓ | —/—/—/✓ | ✗ | (no expuesto actualmente) |
| Menú (CRUD) | `menu.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/menus`, `/menu`, public menu |
| | `menu.create` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/menus`, categorías, ítems, duplicar |
| | `menu.update` | —/—/✓/— | —/—/✓/— | ✗ | `PUT /api/v1/menus/{id}`, activate, schedule, image upload, availability |
| | `menu.delete` | —/—/—/✓ | —/—/—/✓ | ✗ | `DELETE /api/v1/menus/{id}`, categorías, ítems |
| Domicilios (CRUD) | `deliveries.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/deliveries`, métricas, available-deliverers |
| | `deliveries.create` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/orders/{id}/assign-courier`, `POST /api/v1/deliveries` |
| | `deliveries.update` | —/—/✓/— | —/—/✓/— | ✗ | `PATCH /api/v1/deliveries/{id}/complete`, reassign |
| | `deliveries.delete` | —/—/—/✓ | —/—/—/✓ | ✗ | `DELETE /api/v1/deliveries/{id}` |
| Horarios | `hours.read` | —/✓/—/— | —/✓/—/— | —/cfg/—/— | `GET /api/v1/hours`, status, exceptions, web gate `/hours` |
| | `hours.update` | —/—/✓/— | —/—/✓/— | ✗ | `PUT /api/v1/hours`, CRUD excepciones |
| Cupones (CRUD) | `coupons.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/coupons`, redemptions, web gate `/coupons` |
| | `coupons.create` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/coupons` |
| | `coupons.update` | —/—/✓/— | —/—/✓/— | ✗ | `PUT /api/v1/coupons/{id}`, status |
| | `coupons.delete` | —/—/—/✓ | —/—/—/✓ | ✗ | `DELETE /api/v1/coupons/{id}` |
| Usuarios | `users.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/users`, web gate `/identities/users` |
| | `users.update` | ✓/—/✓/✓ | ✓/—/✓/✓ | ✗ | `PUT /role`, `/permissions`, `PATCH /status`, `DELETE`, `POST /invitations` |
| Roles | `roles.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/roles`, `GET /api/v1/features`, web gate `/identities/roles` |
| | `roles.create` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/roles` |
| | `roles.update` | —/—/✓/— | —/—/✓/— | ✗ | `PUT /api/v1/roles/{id}` (bloqueado si `is_system`) |
| | `roles.delete` | —/—/—/✓ | —/—/—/✓ | ✗ | `DELETE /api/v1/roles/{id}` (bloqueado si `is_system` o tiene users) |
| Reportes | `reports.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/reports/orders`, métricas (10 endpoints), exports orders/metrics, web gates `/dashboard` (deferred), `/company/metrics`, `/company/reports` |
| Empresa | `company.update` | —/✓/✓/— | —/✓/✓/— | ✗ | `GET/PUT /api/v1/company`, `GET/PATCH /api/v1/companies/settings`, web gates `/company/settings`, `/company/preferences` |
| Facturación | `billing.read` | —/✓/—/— | —/✓/—/— | ✗ | `GET /api/v1/billing/{plans,subscription,invoices}`, export CSV/PDF, web gate `/billing` |
| Chats (lectura) | `chats.read` | —/✓/—/— | —/✓/—/— | —/cfg/—/— | `GET /api/v1/chats`, `/chats/{id}`, mark-read, client, web gate `/chats` |
| Chats (responder) | `chats.update` | —/—/✓/— | —/—/✓/— | ✗ | `POST /api/v1/chats/{id}/messages`, bot toggle, contact edit |
| WhatsApp (lectura) | `whatsapp.read` | —/✓/—/— | —/✓/—/— | —/cfg/—/— | `GET /api/v1/whatsapp`, verification request/verify, web gate `/company/whatsapp` |
| WhatsApp (conectar) | `whatsapp.connect` | ✓/—/—/— | ✓/—/—/— | ✗ | `POST /api/v1/whatsapp/embedded-signup-callback`, naas-request (+OTP) |
| WhatsApp (editar) | `whatsapp.update` | —/—/✓/— | —/—/✓/— | ✗ | (preferences via company.update; no endpoint dedicado actualmente) |
| WhatsApp (swap phone) 🔒 | `whatsapp.swap_phone` | —/—/—/✓ | ✗ 🔒 | ✗ 🔒 | `DELETE /api/v1/whatsapp/phone` (+OTP+Policy) |
| WhatsApp (desconectar) 🔒 | `whatsapp.disconnect` | —/—/—/✓ | ✗ 🔒 | ✗ 🔒 | `DELETE /api/v1/whatsapp` (+OTP+Policy) |

### 20.2 Casos especiales y gotchas

#### `is_owner_only` y degradabilidad

Marcado en `features.is_owner_only=true`:
- `whatsapp.swap_phone`
- `whatsapp.disconnect`

Aunque la UI te deje configurar un rol custom con esos permisos, `FeaturePermissionService` siempre verifica que el rol sea exactamente `Propietario`. **No es degradable** — ni con override individual.

#### Doble validación en endpoints sensibles

Los endpoints de RBAC se protegen en dos capas:

1. **Middleware en routes/api.php**: `->middleware('permission:menu.update,update')`
2. **assertPermission en el controller**: `$this->permissionService->assertPermission($request, 'menu', 'update');`

Razón: defensa en profundidad. Si alguien removiera el middleware por error en un refactor, el assertPermission seguiría protegiendo. Si alguien removiera el assertPermission, el middleware seguiría protegiendo.

#### Bypass de roles del sistema vs feature granular

Un rol `is_system=true` (Propietario, Administrador, Empleado) **NO consulta** `company_role_permissions` para features no `owner_only`. Esto significa:

- Si haces seeding de un rol con `is_system=true` y permisos parciales → los permisos parciales se ignoran en runtime.
- Si quieres restringir un sistema role, debes ponerle `is_system=false` y reconfigurar la matriz.

#### `chats.update` sin `chats.read`

Configuración inválida: el frontend muestra "responder mensaje" pero no puedes leer chats. Backend no la previene activamente — es responsabilidad de la UI no permitirla. Recomendación: tratar `chats.read` como prerrequisito de `chats.update`.

#### Empleado configurable

Ciertos slugs marcados `cfg` permiten activación individual al empleado. La UI lo soporta vía `UserPermissionsEditor`:

- `orders.read` — para que cocina pueda ver órdenes
- `chats.read` — para que el cajero responda chats
- `hours.read` — para ver horario abierto/cerrado
- `whatsapp.read` — para ver estado de WhatsApp

### 20.3 Reglas inviolables del sistema

| Regla | Implementación |
|---|---|
| Cada feature tiene 4 acciones independientes | Schema `company_role_permissions.can_{create,read,update,delete}` |
| Roles `is_system=true` bypassean RBAC granular | `FeaturePermissionService::hasPermission` línea 2 |
| Features `is_owner_only=true` requieren rol exacto Propietario | mismo, línea 2 (else branch) |
| Overrides sobrescriben rol | mismo, línea 3 |
| Actor no puede otorgar permisos que no tiene | `UserRoleController::updatePermissions` validación |
| Actor no puede modificarse a sí mismo | `users` controller validación pre-update |
| Último miembro con rol `is_system` no se puede degradar/eliminar | `users.destroy` y `users.role` validaciones |
| Roles `is_system` no se editan ni eliminan | `CompanyRolePolicy::update`/`delete` retornan `false` |
| Webhooks externos no requieren rol (sólo HMAC/firma) | bypass total — validación a nivel de signature |
| Public endpoints no requieren auth | rutas en `routes/api.php` fuera del grupo `jwt` |

### 20.4 Auditoría de cambios de permisos

Cada modificación de permisos genera un audit log con `before`/`after`:

| Evento | Trigger | Metadata |
|---|---|---|
| `role.created` | `POST /api/v1/roles` | `{role_name, permissions}` |
| `role.updated` | `PUT /api/v1/roles/{id}` | `{changed_permissions: {feature_slug: [before, after]}}` |
| `role.deleted` | `DELETE /api/v1/roles/{id}` | `{snapshot: full_role_with_permissions}` |
| `user.role_changed` | `PUT /api/v1/users/{id}/role` | `{from_role_id, to_role_id}` |
| `user.permissions_updated` | `PUT /api/v1/users/{id}/permissions` | `{custom_permissions_diff}` |
| `user.status_changed` | `PATCH /api/v1/users/{id}/status` | `{from, to, blacklisted: bool}` |

---

## 21. Endpoints públicos (sin autenticación)

| URL | Propósito |
|-----|-----------|
| `GET /api/v1/legal-document/{type}` | TOS, privacidad, contrato |
| `GET /api/v1/public/menu/{companyNit}` | Menú activo de cualquier empresa por NIT. **Responde 423 cuando**: (1) fuera de horario hábil, (2) no hay menú activo, o (3) la caja de la empresa está cerrada (`reason='cash_register_closed'`). En esos casos `data=null` y `restaurant_status.is_open=false`. Bloquea al bot/cart de tomar órdenes mientras el restaurante no esté operativo |
| `POST /api/v1/public/menu/{nit}/scan` | Telemetría del QR del menú (issue #95). Body opcional: `{ table, session_id, _h }`. Rate-limit 30/min/IP/nit. Dedup por `session_id` (60s). Bot detection: marca `is_bot=true` si UA en blocklist, sin Referer válido, o honeypot triggered. Append-only en `menu_scan_events` particionada |
| `GET /menus/{nit}` | Página web pública del menú (issue #95). Destino del QR fijo del restaurante. El cliente guarda el NIT en `localStorage['menu_last_nit']` y reemplaza la URL a `/menus/` via `history.replaceState` — el NIT se ve por un flash y queda una URL limpia. Branding (logo/color/nombre) se hidrata desde la respuesta de la API. Soporta `?table=N` persistido en `sessionStorage` para preselección al confirmar pedido |
| `GET /menus` | Misma página, sin NIT en URL. El cliente resuelve el NIT desde localStorage (recarga, link directo). Si no hay NIT cacheado, empty state pidiendo escanear el QR |
| `GET /api/v1/webhooks/whatsapp` | Handshake Meta (verify_token) |
| `POST /api/v1/webhooks/whatsapp` | Eventos Meta (validados por HMAC) |
| `GET /api/v1/whatsapp/verification/reject?token=...` | Botón "no fui yo" en correo OTP |
| `POST /api/v1/csp-report` | Reporte de violación CSP del navegador |

### 21.1 QR fijo del menú (issue #95)

El QR impreso por mesa/sede apunta a `/menus/{nit}` y nunca cambia — el menú detrás del QR sí. Tres componentes:

1. **Página pública (`/menus/{nit}`)**: Inertia sin auth. Lee `?table=N` (cliente-preseleccionado) y lo persiste en `sessionStorage['cart:preselected_table:'+nit]` para que el flujo de checkout futuro lo lea. Maneja 423 (cerrado/fuera de horario, caja cerrada) y 404 (sin menú activo). Telemetría: 1 POST por sesión a `/scan` con `keepalive: true`.

2. **Generador del poster (cliente)**: en `/company/settings`, tarjeta "QR del Menú" renderiza un `<canvas>` con QR + logo + nombre comercial + color primario. **Reactivo**: cualquier cambio de logo o color en la misma página actualiza la preview al instante. Botón "Descargar PNG" exporta el canvas. Sin almacenamiento en backend (componente `components/company/menu-qr-poster.tsx`, dependencia `qrcode`). Input opcional "Mesa N" → QR específico con `?table=N`.

3. **Analítica de escaneos (`menu_scan_events`)**: tabla particionada mensualmente por `scanned_at` con red de seguridad (default partition). Cron horario `partitions:ensure` pre-crea particiones del mes anterior, actual, siguiente y +2; drena la default re-routing filas a sus particiones mensuales correctas. `AggregateMenuScansJob` (diario) UPSERT a `menu_scan_daily_rollup` para reportes baratos. `DropOldMenuScanPartitionsJob` (diario) borra particiones >90 días. Bot detection vía `BotDetectionService` (UA blocklist + Referer check + honeypot) marca `is_bot=true` sin descartar — los reportes filtran via índices parciales.

4. **Panel de escaneos en `/company/metrics` (#294)**: `GET /api/v1/metrics/menu-scans` (permiso `reports.read`, períodos today/week/month/custom, consolidación multi-sede) lee el rollup diario y le une el día en curso agregado en vivo desde `menu_scan_events`. El panel (`MenuScansPanel`) muestra total de escaneos, sesiones únicas (suma de únicos por día×mesa×sede — el rollup no conserva `session_id`), barras escaneos/día y desglose por mesa (top 10) y por sede (solo vista consolidada).

---

## 22. Variables de entorno relevantes al negocio

### Frontend

| Variable | Uso |
|----------|-----|
| `VITE_APP_NAME` | Nombre en `<title>` |
| `VITE_API_URL` | Base URL para llamadas REST (fallback `/api/v1`) |
| `VITE_PUSHER_*` | WebSocket Pusher (futuro) |

### Backend (operativos)

| Variable | Propósito |
|----------|-----------|
| `JWT_SECRET` / `JWT_PAYLOAD_ENCRYPTION_KEY` | Cifrado JWT |
| `JWT_TTL` (21600) | Vida del token (6 h) |
| `BOT_JWT_SECRET` | JWT bots externos |
| `CART_JWT_SECRET` | JWT carrito anónimo |
| `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` | OAuth Google |
| `META_*` | WhatsApp Cloud API (ver sección 14) |
| `BILLING_CURRENCY` (COP) | Moneda |
| `BILLING_GRACE_MONTHS` (2) | Meses gracia |
| `BILLING_DUE_DAY` (10) | Día vencimiento |
| `BILLING_DIAN_UNIT_PRICE` (10) | COP por documento DIAN emitido en el período (Plan Plus) |
| `BILLING_GENERATE_DAY` (20) | Día generación |
| `DELIVERY_MAX_ACTIVE_PER_COURIER` (3) | Concurrentes por repartidor |
| `DELIVERY_NOTIFY_ON_*` | Notificaciones WhatsApp |
| `MENU_IMAGE_DISK` / `MENU_IMAGE_MAX_SIZE_KB` (2048) | Imágenes platos |
| `COUPON_CODE_MAX_LENGTH` (20) / `COUPON_MAX_VALUE_PERCENTAGE` (80) | Cupones |
| `PDF_MAX_ROWS` (500) | Cap exports PDF |
| `REPORT_MAX_DATE_RANGE_DAYS` (90) | Rango máximo filtros |
| `REPORT_DOWNLOAD_TTL` (30 min) | TTL token descarga |
| `METRICS_CACHE_TTL` (60s) + `DASHBOARD_*_CACHE_TTL` | Caché métricas |
| `JWT_BLACKLIST_ENABLED` (true) | Habilita revocación |

---

## 23. Auditoría — eventos registrados (catálogo completo)

Servicio: `App\Services\AuditService`. Modelo: `App\Models\AuditLog`. Todos los eventos quedan en la tabla `audit_logs` con la siguiente estructura:

```sql
audit_logs (
  id,
  event,                           -- string slug (ej. 'order.status_changed')
  user_id,                         -- FK users (actor; null para eventos del sistema)
  model_type,                      -- string nullable (ej. 'App\\Models\\Order')
  model_id,                        -- bigint nullable (ID del modelo afectado)
  data,                            -- JSONB con metadata específica del evento
  ip,                              -- string nullable
  user_agent,                      -- string nullable
  created_at
)
```

### 23.0 Cómo se registran

```php
// AuditService::log
public function log(
    string $event,
    ?Authenticatable $actor,
    ?Model $model,
    array $metadata = [],
    ?Request $request = null
): AuditLog {
    return AuditLog::create([
        'event' => $event,
        'user_id' => $actor?->getAuthIdentifier(),
        'model_type' => $model ? get_class($model) : null,
        'model_id' => $model?->getKey(),
        'data' => $metadata,
        'ip' => $request?->ip(),
        'user_agent' => $request?->userAgent(),
    ]);
}
```

**Append-only**: nunca se actualiza ni elimina un audit log. La purga (si aplica) sería externa (ej. archive a S3 después de N años por compliance).

### 23.1 Catálogo completo de eventos

#### Autenticación

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `auth.login` | Login exitoso (Google OAuth callback o `/login`) | User | User | `{provider, companies_count, ip}` |
| `auth.login_cancelled` | Usuario canceló en Google | null | null | `{reason}` |
| `auth.login_state_mismatch` | CSRF state mismatch | null | null | `{provider}` |
| `auth.logout` | `POST /api/v1/auth/logout` o `/logout` | User | User | `{blacklist_enabled, jti}` |
| `auth.company.selected` | `POST /api/v1/auth/select-company` | User | Company | `{from_nit, to_nit}` |
| `auth.company.switched` | `POST /api/v1/auth/switch-company` | User | Company | `{from_nit, to_nit, blacklisted_jti}` |

#### Empresa

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `company.created` | `CompanyEnrollmentController::store` | User | Company | `{nit, owner_id, plan}` |
| `company.updated` | `PUT /api/v1/company` | User | Company | `{changed_fields, before, after}` |
| `company.settings_updated` | `PATCH /api/v1/companies/settings` | User | Company | `{changes: [keys], invalidated_cache}` |
| `company.unauthorized_access` | `EnsureCompanyAccess` rechaza | User | null | `{attempted_nit, path, reason}` |
| `company.status_changed` | `BillingService::updateCompanyStatusFromInvoices` | null (sistema) | Company | `{from, to, overdue_count}` |

#### Usuarios

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `user.created` | `User::firstOrCreate` en GoogleAuthController callback | null/User | User | `{email, domain, google_id, status}` |
| `user.enrolled` | `UserEnrollmentController::store` | User | User | `{accepted_versions: {tos, privacy}, enrollment_step}` |
| `user.deleted` | `DELETE /settings/profile` o `/api/v1/me` | User | User | `{self_deletion: bool, reason?}` |
| `user.role_changed` | `PUT /api/v1/users/{id}/role` | User (admin) | User (target) | `{from_role_id, to_role_id, from_role_name, to_role_name}` |
| `user.permissions_updated` | `PUT /api/v1/users/{id}/permissions` | User (admin) | User (target) | `{custom_permissions_diff: [{feature, action, before, after}]}` |
| `user.status_changed` | `PATCH /api/v1/users/{id}/status` | User (admin) | User (target) | `{from, to, blacklisted: bool}` |

#### Invitaciones

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `invitation.created` | `POST /api/v1/invitations` | User | CompanyInvitation | `{invited_email, role_id, role_name, expires_at}` |
| `invitation.accepted` | `POST /api/v1/enrollment/invited` | User | CompanyInvitation | `{company_nit, role_assigned}` |
| `invitation.expired` | Validación lazy en accept (cuando intenta aceptar tarde) | null | CompanyInvitation | `{expired_at_check}` |
| `invitation.user_already_member` | Bloqueo en create (no log si rechaza pre-create — sólo validación) | — | — | — |

#### Roles

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `role.created` | `POST /api/v1/roles` | User | CompanyRole | `{role_name, color, permissions_count}` |
| `role.updated` | `PUT /api/v1/roles/{id}` | User | CompanyRole | `{changed_permissions: {feature_slug: [before_crud, after_crud]}, name_changed?, color_changed?}` |
| `role.deleted` | `DELETE /api/v1/roles/{id}` | User | CompanyRole | `{snapshot: {role, permissions: [...]}}` (preservado para audit forense) |

#### Menú

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `menu.created` | `POST /api/v1/menus` | User | RestaurantMenu | `{name, version: 3, categories_count}` |
| `menu.activated` | `PATCH /api/v1/menus/{id}/activate` | User | RestaurantMenu | `{previous_active_id}` |
| `menu.duplicated` | `POST /api/v1/menus/{id}/duplicate` | User | RestaurantMenu | `{source_id, target_name}` |
| `menu.deleted` | `DELETE /api/v1/menus/{id}` | User | RestaurantMenu | `{name, was_active: bool}` |
| `menu.scheduled` | `PATCH /api/v1/menus/{id}/schedule` | User | RestaurantMenu | `{from_active_days, to_active_days}` |
| `menu.synced` | `POST /api/v1/menus/sync-schedule` o cron | User/null | null | `{activated_id, deactivated_ids}` |
| `menu.category_created` | `POST /menus/{id}/categories` | User | RestaurantMenu | `{category_id, category_name}` |
| `menu.category_updated` | `PUT /menus/{id}/categories/{catId}` | User | RestaurantMenu | `{category_id, changed_fields}` |
| `menu.category_deleted` | `DELETE /menus/{id}/categories/{catId}` | User | RestaurantMenu | `{category_id, items_count}` |
| `menu.item_created` | `POST .../items` | User | RestaurantMenu | `{item_id, name, price}` |
| `menu.item_updated` | `PUT .../items/{itemId}` | User | RestaurantMenu | `{item_id, changed_fields}` |
| `menu.item_deleted` | `DELETE .../items/{itemId}` | User | RestaurantMenu | `{item_id, name}` |
| `menu.item_availability_changed` | `PATCH .../availability` | User | RestaurantMenu | `{item_id, from: bool, to: bool}` |
| `menu.item_image_uploaded` | `POST /menus/{id}/items/{itemId}/image` | User | RestaurantMenu | `{item_id, file_size, mime_type, old_path}` |

#### Pedidos

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `order.created` | `POST /api/v1/orders` (caja) o desde bot externo | User/null (bot) | Order | `{order_type, total, items_count, source: 'cashier'\|'bot'}` |
| `order.status_changed` | `PATCH /api/v1/orders/{id}/status` o cascade desde delivery | User/null | Order | `{from, to, source: 'manual'\|'delivery_cascade'}` |
| `order.items_appended` | `POST /api/v1/orders/{id}/items` | User | Order | `{order_id, added_total, new_total, lines_added}` |
| `order.closed_with_payment` | `POST /api/v1/orders/{id}/close-with-payment` | User | Order | `{order_id, method, amount, tip_amount, reference?, change_returned?}` |
| `order.cancelled` | `POST /api/v1/orders/{id}/cancel` | User | Order | `{order_id, reason?}` |
| `order.refunded` | `POST /api/v1/orders/{id}/refund` | User | Order | `{order_id, original_method, total_refunded, is_partial, remaining_refundable, reference?, reason?}` |

#### Caja (sesiones de turno)

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `cash_register.opened` | `POST /api/v1/cash-register/open` | User | CashRegisterSession | `{opening_amount, notes?}` |
| `cash_register.closed` | `POST /api/v1/cash-register/close` | User | CashRegisterSession | `{opening_amount, closing_amount, expected_cash, cash_difference}` |
| `cash.expense.recorded` | `POST /api/v1/cash-register/expenses` | User | CashRegisterExpense | `{amount, category, payment_method, description, cash_session_id}` |

#### Domicilios

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `delivery.assigned` | `POST /api/v1/orders/{id}/assign-courier` | User (admin) | Delivery | `{order_id, courier_id, courier_name, reason?}` |
| `delivery.completed` | `PATCH /api/v1/deliveries/{id}/complete` | User (courier o admin) | Delivery | `{order_id, duration_minutes, on_time: bool}` |
| `delivery.reassigned` | `POST /api/v1/deliveries/{id}/reassign` | User (admin) | Delivery (nueva) | `{order_id, from_courier_id, to_courier_id, reason, previous_delivery_id}` |
| `delivery.cancelled` | `DELETE /api/v1/deliveries/{id}` | User (admin) | Delivery | `{order_id, courier_id, cancellation_reason}` |

#### Cupones

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `coupon.created` | `POST /api/v1/coupons` | User | Coupon | `{code, type, value, min_order_amount, valid_until}` |
| `coupon.updated` | `PUT /api/v1/coupons/{id}` | User | Coupon | `{changed_fields, before, after}` |
| `coupon.status_changed` | `PATCH /api/v1/coupons/{id}/status` | User | Coupon | `{from, to: 'active'\|'inactive'}` |
| `coupon.deleted` | `DELETE /api/v1/coupons/{id}` | User | Coupon | `{code, uses_count, soft_delete: true}` |
| `coupon.redeemed` | `POST /api/v1/cart/apply-coupon` y subsecuente order create | null (bot) | CouponRedemption | `{coupon_code, order_id, discount_amount, client_phone_masked}` |

#### Horarios

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `hours.updated` | `PUT /api/v1/hours` | User | null (bulk) | `{schedule_diff: [{day, before, after}]}` |
| `hours.exception_created` | `POST /api/v1/hours/exceptions` | User | BusinessHourException | `{exception_date, is_open, reason}` |
| `hours.exception_updated` | `PUT /api/v1/hours/exceptions/{id}` | User | BusinessHourException | `{date, changed_fields}` |
| `hours.exception_deleted` | `DELETE /api/v1/hours/exceptions/{id}` | User | BusinessHourException | `{date, reason}` |

#### Reportes y exports

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `report.exported` | `POST /api/v1/reports/export` (async) o exports síncronos | User | null | `{format: 'pdf'\|'csv', filters, row_count, date_range, async: bool}` |

#### Facturación

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `invoice.generated` | `billing:generate-monthly-invoices` cron | null (sistema) | Invoice | `{company_nit, amount, days_billed, type: 'monthly'\|'proration'}` |
| `invoice.overdue` | `billing:mark-overdue-invoices` cron | null | Invoice | `{days_overdue, due_date}` |
| `invoice.paid` | Insert manual en `invoice_payments` (vía panel admin externo) | User (staff) | Invoice | `{amount_paid, payment_method, reference}` |
| `invoice.voided` | Creación de credit-note (manual) | User (staff) | Invoice | `{voided_by_invoice_id, reason}` |
| `discount.expired` | `billing:expire-discounts` cron | null | SubscriptionDiscount | `{count_expired_in_run}` |

#### WhatsApp

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `whatsapp.connected` | `POST /api/v1/whatsapp/embedded-signup-callback` exitoso | User | CompanyWhatsappAccount | `{waba_id, phone_number_id, phone_e164, provisioning_mode}` |
| `whatsapp.naas_requested` | `POST /api/v1/whatsapp/naas-request` | User | CompanyWhatsappAccount | `{preferred_country_code, business_description}` |
| `whatsapp.swap_phone` | `DELETE /api/v1/whatsapp/phone` | User (owner) | CompanyWhatsappAccount | `{old_phone_number_id}` |
| `whatsapp.disconnected` | `DELETE /api/v1/whatsapp` | User (owner) | CompanyWhatsappAccount | `{soft_delete: true, was_active: bool}` |
| `whatsapp.verification_sent` | `POST /api/v1/whatsapp/verification/request` | User | WhatsappVerificationCode | `{action, expires_at}` |
| `whatsapp.verification_used` | Consume del OTP en endpoint sensible | User | WhatsappVerificationCode | `{action, attempts}` |
| `whatsapp.verification_rejected` | `GET /api/v1/whatsapp/verification/reject?token=...` | null | WhatsappVerificationCode | `{action}` |
| `whatsapp.verification_failed` | Code wrong o expired | User | WhatsappVerificationCode | `{action, attempts, reason}` |
| `whatsapp.webhook_received` | `POST /api/v1/webhooks/whatsapp` | null | null | `{event_type, phone_number_id, message_count}` |
| `whatsapp.webhook_signature_invalid` | HMAC mismatch en webhook | null | null | `{ip, signature_provided}` |

#### Chats

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `chat.message_sent_by_operator` | `POST /api/v1/chats/{id}/messages` | User | ChatMessage | `{chat_id, body_length, has_media}` |
| `chat.bot_paused` | `PATCH /api/v1/chats/{id}/bot` con `bot_paused=true` | User | Chat | `{client_phone}` |
| `chat.bot_resumed` | mismo con `bot_paused=false` | User | Chat | `{client_phone}` |
| `chat.contact_updated` | `PATCH /api/v1/chats/{id}/contact` | User | Contact | `{name_changed, notes_changed}` |
| `chat.read_receipt_sent` | `POST /api/v1/chats/{id}/mark-read` cuando despacha el job | User | Chat | `{wamid_marked}` |
| `chat.purged` | `chats:purge-old` cron | null | null | `{count_purged, cutoff_date}` |

#### Sistema

| Evento | Trigger | Actor | Model | Metadata |
|---|---|---|---|---|
| `webhook.replayed` | `whatsapp:replay-events` comando manual | User | WebhookEvent | `{event_id, original_received_at}` |

### 23.2 Visualización y consulta de audit logs

**No existe UI dedicada** para ver audit logs. Consulta directa via Tinker o BD:

```php
// Buscar últimos 50 eventos de un usuario
AuditLog::where('user_id', 22)
    ->orderByDesc('created_at')
    ->take(50)
    ->get(['event', 'model_type', 'model_id', 'data', 'created_at']);

// Buscar todos los cambios de rol del último mes
AuditLog::where('event', 'user.role_changed')
    ->where('created_at', '>=', now()->subMonth())
    ->with('user')
    ->get();

// Eventos de seguridad
AuditLog::whereIn('event', [
    'company.unauthorized_access',
    'whatsapp.webhook_signature_invalid',
    'whatsapp.verification_failed',
    'auth.login_state_mismatch',
])->latest()->take(100)->get();
```

### 23.3 Retención de datos

- **Sin política de purga automática** — los logs se conservan indefinidamente.
- **Tamaño esperado**: ~50 eventos/empresa/día → ~1.5M eventos/año/empresa activa.
- **Recomendación futura**: archivar a S3/Glacier después de 2 años (no implementado).

### 23.4 Eventos NO auditados (gaps conocidos)

Por simplicidad, los siguientes flujos NO generan audit logs:

- Lecturas (`GET /api/v1/orders`, `/menus`, etc.) — sólo escrituras se auditan.
- Cambios de filtros en frontend (sólo client-side).
- Logins fallidos por password incorrecto en flujo legacy (sólo successful).
- Cambios de tema (`/settings/appearance`) — sólo localStorage.
- Polling de métricas (sería ruidoso).

Si compliance lo requiere, agregar el log en el endpoint correspondiente con `audit('event_slug', ...)`.

---

## 24. Multi-tenancy y aislamiento

- Cada request autenticado lleva `active_company_nit` en el JWT.
- Middleware `EnsureCompanyAccess` valida la membresía y lo inyecta en `request->attributes`.
- Todas las queries filtran por `forCompany($companyNit)` (scope local del modelo).
- **Olvidarse de filtrar por NIT es un bug de seguridad**: rompe el aislamiento entre empresas.

### Excepciones por diseño

- `GET /api/v1/public/menu/{companyNit}` — pasa NIT por URL.
- `/api/v1/cart/...` — empresa viene del Cart JWT.
- `/api/external/...` — empresa viene del Bot JWT.
- Webhook WhatsApp — empresa se resuelve via `phone_number_id`.

---

## 25. Empty states y edge cases — referencia exhaustiva

Tabla larga con causa → comportamiento esperado → status code → ubicación en código. Útil para troubleshooting y QA.

### 25.1 Autenticación

| Caso | Status | Body / Comportamiento | Audit | Ubicación |
|---|---|---|---|---|
| Usuario cancela en Google OAuth | 302 → `/` | Flash `error: 'No completaste el inicio de sesión'` | `auth.login_cancelled` | `GoogleAuthController::callback` |
| `state` mismatch (CSRF) | 419 | Página de error genérica | `auth.login_state_mismatch` | Socialite middleware |
| `code` OAuth expirado | 302 → `/` | Flash error genérico | — | `Socialite::user()` lanza |
| `GOOGLE_CLIENT_*` no configurado | 500 | Excepción Socialite | — | `config/services.php` |
| Rate limit `/auth/google` excedido (10/min IP) | 429 | Header `Retry-After: NN` | — | `RateLimiter::for('oauth')` |
| Rate limit `/login` excedido (5/min) | 429 | mismo | — | `throttle:5,1` |
| Rate limit reset password (6/min) | 429 | mismo | — | `throttle:6,1` |
| Email no verificado intenta `/dashboard` | 302 → `/verify-email` | Banner "Verifica tu email" | — | `verified` middleware |
| Login con password incorrecto (legacy) | 422 | `{errors:{email:['credentials']}}` | — | Breeze `LoginRequest` |
| Login en cuenta `status=inactive` | 401 | `{message: 'Tu cuenta está desactivada'}` | — | Custom check |
| JWT firma inválida | 401 | `{message: 'Token inválido'}` | — | `ValidateJwt::handle` |
| JWT expirado sin refresh posible | 401 | `{message: 'Sesión expirada'}` | — | mismo |
| JWT con `exp - now < 300s` | (transparente) | Auto-reissue + Cookie::queue | — | `ValidateJwt` |
| JWT en blacklist (revocado) | 401 | `{message: 'Sesión revocada'}` | — | `JwtService::verify` |
| Cookie HttpOnly faltante + Bearer válido (legacy) | (transparente) | Backend siembra cookie | — | `ValidateJwt::handle` |
| `JWT_SECRET` no configurado | 500 | RuntimeException al issue | — | `config/auth.php` |
| `JWT_BLACKLIST_ENABLED=false` + revocación intentada | 200 (no-op) | Token sigue válido hasta exp natural | — | `JwtService::revoke` |
| Logout duplicado (token ya revocado) | 200 | Idempotente | `auth.logout` | `AuthController::logout` |

### 25.2 Multi-empresa

| Caso | Status | Body / Comportamiento | Audit | Ubicación |
|---|---|---|---|---|
| User 1 empresa: post-login va directo a `/dashboard` | 302 | mismo | `auth.company.selected` (auto) | callback Google |
| User N empresas: post-login va a selector | 302 → `/auth/company-selector` | Página con tarjetas | — | callback Google |
| Selector sin empresas (huérfano) | 302 → `/enrollment/company` | Wizard nueva empresa | — | callback Google |
| `select-company` con NIT ajeno | 403 | `{message: 'No eres miembro de esa empresa'}` | `company.unauthorized_access` | `AuthController::selectCompany` |
| `switch-company` mientras tiene operación abierta | 200 | JWT viejo blacklisteado, nuevo emitido | `auth.company.switched` | `AuthController::switchCompany` |
| `switch-company` con NIT mismo (no-op) | 200 | Sin reissue | — | mismo |
| `EnsureCompanyAccess` falla (membresía borrada o desactivada) | 403 | `{message: 'Acceso denegado'}` | `company.unauthorized_access` | middleware |

### 25.3 Onboarding

| Caso | Status | Body | Audit |
|---|---|---|---|
| Usuario en `pending_enrollment` accede a `/dashboard` | 302 → `/enrollment/user` | — | — |
| `/enrollment/user` con cedula duplicada | 422 | `{errors:{cedula:['unique']}}` | — |
| Versión TOS aceptada no es la vigente | 422 | `{code: 'legal_document.version_mismatch'}` | — |
| `/enrollment/company` con NIT ya existe | 422 | `{errors:{nit:['unique']}}` | — |
| QR > 5 MB | 422 | `{errors:{qr_code:['max:5120']}}` | — |
| QR no PNG/JPG | 422 | `{errors:{qr_code:['mimes']}}` | — |
| Usuario con invitación pendiente y acepta otra empresa primero | 200 | Crea membership en empresa nueva, invitación queda pending | `company.created` |
| Aceptar invitación expirada | 422 | `{code: 'invitation.expired'}` | `invitation.expired` |
| Aceptar invitación con email diferente | 403 | `{message: 'El email no coincide'}` | — |
| Re-aceptar invitación ya aceptada | 422 | `{code: 'invitation.already_accepted'}` | — |

### 25.4 Pedidos (kanban + caja)

| Caso | Status | Body | Audit |
|---|---|---|---|
| Empresa sin órdenes hoy | 200 | `{data: []}` (kanban vacío) | — |
| `PATCH /orders/{id}/status` con orden de otra empresa | 404 | `{message: 'Not found'}` (no 403 — evita info leak) | — |
| `PATCH /orders/{id}/status` con status inválido | 422 | `{errors:{status:['in']}}` | — |
| `PATCH /orders/{id}/status` salto raro (`pending → completed` directo) | 200 | **Permitido** (sin máquina de estados estricta) | `order.status_changed` |
| `POST /orders` con items vacío | 422 | `{errors:{items:['required','min:1']}}` | — |
| `POST /orders` con `order_type=table` sin `table_number` | 422 | `{errors:{table_number:['required_if']}}` | — |
| `POST /orders` con `order_type=delivery` sin `delivery_address` | 422 | mismo | — |
| `POST /orders` con item id no existente en menú activo | 422 | `{errors:{items:['no disponibles']}}` | — |
| `POST /orders` con item `available=false` | 422 | mismo (genérico) | — |
| `POST /orders` sin menú activo en empresa | 422 | `{errors:{menu:['No hay un menú activo']}}` | — |
| `POST /orders` con menú activo no aplicable hoy (`active_days`) | 422 | `{errors:{menu:['no aplica para hoy']}}` | — |
| `POST /orders` cliente intenta inyectar `price` | 200 | Backend ignora, calcula del menú | `order.created` |
| Drag-drop kanban a misma columna | 200 (no-op) | Sin cambio en BD | — |
| Drag-drop kanban falla en backend | UI revierte | `apiFetch` revierte estado optimista + toast | — |

### 25.5 Domicilios

| Caso | Status | Body | Audit |
|---|---|---|---|
| Asignar courier a orden no `ready/in_delivery` | 422 | `{message: 'No está lista'}` | — |
| Asignar courier que ya tiene `MAX_ACTIVE_PER_COURIER` activos | 422 | `{message: 'Máximo de entregas alcanzado'}` | — |
| Asignar a usuario que no es repartidor en la empresa | 403 | `{message: 'No es repartidor'}` | — |
| Reasignar a misma persona | 422 | `{message: 'Mismo repartidor'}` | — |
| Reasignar entrega ya `completed` | 409 | `{message: 'Orden completada, no editable'}` | — |
| Reasignar sin `reason` | 422 | `{errors:{reason:['required']}}` | — |
| Marcar `completed` una `cancelled` | 422 | `{message: 'Sólo entregas pendientes'}` | — |
| WhatsApp no conectado al asignar | 200 | Notif silenciosamente omitida | log warning |
| Notif WhatsApp falla por error de Meta | 200 | Delivery se crea igual; log warning | — |
| Eliminar entrega ya soft-deleted | 404 | (sin `withTrashed` en query) | — |
| Asignar nueva entrega tras soft-delete de la anterior | 200 | Permitido por UNIQUE parcial | `delivery.assigned` |

### 25.6 Menú

| Caso | Status | Body | Audit |
|---|---|---|---|
| Activar menú: desactiva el activo actual a `scheduled` | 200 | (cascade transparente) | `menu.activated` |
| Eliminar item con órdenes activas | 409 | `{message: 'Tiene órdenes activas'}` | — |
| Subir imagen > 2 MB | 422 | `{errors:{image:['max:2048']}}` | — |
| Subir imagen tipo prohibido (ej. GIF, WEBP) | 422 | `{errors:{image:['mimes']}}` | — |
| Reordenar drag-drop > 1× rápidamente | 1 PUT (debounce 300ms) | Sólo el último gana | — |
| Toggle availability falla | UI revierte | optimistic rollback + toast | — |
| Public menu de NIT inexistente | 404 | `{message: 'Empresa no encontrada'}` | — |
| Public menu sin menú activo | 200 | `{data: {company, menu: null}}` | — |
| Sync schedule con varios menús matching hoy | 200 | El primero por id ascendente gana | `menu.synced` |

### 25.7 Cupones

| Caso | Status | Body | Audit |
|---|---|---|---|
| Crear cupón con código duplicado en la empresa | 422 | `{errors:{code:['unique']}}` | — |
| Crear cupón `percentage` con value > 80 | 422 | `{errors:{value:['max:80']}}` | — |
| Crear cupón `fixed_amount` con value > 100 000 | 422 | `{errors:{value:['max:100000']}}` | — |
| Crear cupón con `valid_until < valid_from` | 422 | `{errors:{valid_until:['after_or_equal']}}` | — |
| Editar cupón con `uses_count > 0` | 403 | `{message: 'Cupón ya redimido'}` | — |
| Toggle `is_active` con `uses_count > 0` | 200 | **Permitido** (no es edición de reglas) | `coupon.status_changed` |
| Validar cupón expirado | 200 | `{valid: false, reason: 'expired'}` | — |
| Validar cupón con `min_order_amount` no alcanzado | 200 | `{valid: false, reason: 'min_order', min_order_amount: N}` | — |
| Validar `first_order_only` con cliente recurrente | 200 | `{valid: false, reason: 'not_first_order'}` | — |
| Aplicar mismo cupón 2× a misma sesión carrito | 200 | Sobrescribe el anterior | — |
| Cupón alcanza `max_uses` | (auto sync) | `status='exhausted'` automático | `coupon.status_changed` |

### 25.8 Horarios

| Caso | Status | Body | Audit |
|---|---|---|---|
| Configurar `close_time` que cruza medianoche (ej. 02:00 AM) | (limitación) | DB acepta TIME pero `getStatus` no soporta — mejora futura | — |
| Crear excepción con `is_open=true` sin `open_time/close_time` | 422 | `{errors:{open_time:['required_if']}}` | — |
| Crear excepción duplicada (misma fecha, mismo NIT) | 422 | `{errors:{exception_date:['unique']}}` | — |
| Excepción del día actual con `is_open=false` | 200 | `getStatus.is_open=false` aunque base sea día abierto | — |
| `BOT_JWT_SECRET` no configurado, llamada a `/api/external/hours/status` | 401 | `{message: 'Bot auth not configured'}` (no 500) | — |
| Bot JWT inválido o expirado | 401 | `{message: 'Invalid bot token'}` | — |

### 25.9 Chats

| Caso | Status | Body | Audit |
|---|---|---|---|
| Búsqueda con string > 100 chars | 422 | `{errors:{q:['max:100']}}` | — |
| Búsqueda con `%` o `_` o `!` | 200 | Escapados con `ESCAPE '!'` (anti-LIKE-injection) | — |
| Mark-read con `whatsapp_read_receipts=false` | 200 | `{skipped: 'read_receipts_disabled'}` | — |
| Mark-read sin client messages | 200 | `{skipped: 'no_client_messages'}` | — |
| Mark-read mismo wamid 2× en <5min | 200 | `{skipped: 'throttled'}` | — |
| Enviar mensaje > 4096 chars | 422 | `{errors:{body:['max:4096']}}` (límite WhatsApp) | — |
| Enviar mensaje sin WhatsApp conectado | 200 | Persiste en BD pero no llega al cliente; status=`failed` después | — |
| Enviar mensaje con error transitorio Meta | 200 | Persiste; status=`failed`; UI muestra "no entregado" | — |
| Webhook llega con HMAC inválido | 401 | `{message: 'Invalid signature'}` | `whatsapp.webhook_signature_invalid` |
| Webhook con `phone_number_id` desconocido | 200 | Logged warning, mensaje ignorado | — |
| Webhook reenviado con mismo `wamid` | 200 | Idempotente — `firstOrCreate` no duplica | — |
| Chat sin actividad > 60 días | (purga automática) | `chats:purge-old` lo elimina junto con messages | `chat.purged` |

### 25.10 WhatsApp

| Caso | Status | Body | Audit |
|---|---|---|---|
| OTP wrong | 422 | `{valid: false, reason: 'wrong_code', remaining: N}` | `whatsapp.verification_failed` |
| OTP attempts > 3 | 422 | `{valid: false, reason: 'too_many_attempts'}` | mismo |
| OTP expirado (>10 min) | 422 | `{valid: false, reason: 'expired_or_not_found'}` | mismo |
| OTP rejected via "No fui yo" link | 200 | `{rejected: true}`; código inválido permanente | `whatsapp.verification_rejected` |
| Solicitar 4° OTP en 30 min | 429 | `{message: 'Demasiadas solicitudes'}` | — |
| `swap_phone` por admin (no owner) | 403 | `{message: 'Acceso denegado'}` (Policy bloquea) | — |
| `disconnect` por admin | 403 | mismo | — |
| `embedded-signup-callback` con `phone_number_id` ya en otra empresa | 422 | `{message: 'Número ya conectado'}` | — |
| Token de Meta expirado en outbound | (auto retry job) | Job reintenta; si falla 3×, marca message `failed` | — |

### 25.11 Reportes y exports

| Caso | Status | Body | Audit |
|---|---|---|---|
| `period=custom` rango > 90 días | 422 | `{errors:{date_to:['rango máx 90']}}` | — |
| `date_to < date_from` | 422 | `{errors:{date_to:['after_or_equal']}}` | — |
| Status inválido en filtro | 422 | `{errors:{status:['in']}}` | — |
| Exportar PDF sin datos en rango | 422 | `{message: 'No hay datos'}` (`PdfEmptyDataException`) | — |
| PDF supera 500 filas | 200 | PDF con aviso `limitApplied=true` (no bloquea) | `report.exported` |
| CSV exporta dataset enorme | 200 (streamed) | Sin cap; chunked con BOM UTF-8 | `report.exported` |
| Export async: download token expirado | 404 | `{message: 'Enlace expirado o inexistente'}` | — |
| Export async: token de otra empresa | 403 | `{message: 'No tienes acceso'}` | — |
| Export async: archivo aún no listo | 404 | `{message: 'No disponible aún'}` | — |

### 25.12 Facturación

| Caso | Status | Body | Audit |
|---|---|---|---|
| Cron generate llama 2× el mismo día | (idempotente) | Skip por chequeo `Invoice::exists()` | — |
| Empresa sin Subscription activa al generar cron | (skip) | No genera invoice | — |
| Subscription empezó a mitad de mes | (proration) | `type='proration'`, `days_billed < total_days` | `invoice.generated` |
| Descuento aplicable cubre 100% del precio | 200 | Invoice con `amount=0`, `status='paid'` automático | `invoice.generated` |
| Factura `paid` se intenta editar | 405 | (no hay endpoint de edit) — sólo se voida con credit-note | — |
| URL firmada de PDF expirada | 403 | `URL::hasValidSignature` falla | — |
| PDF aún no generado al pedir download | (auto-genera) | Lazy generation en `servePdf` | — |

### 25.13 Identidades (usuarios + roles)

| Caso | Status | Body | Audit |
|---|---|---|---|
| Eliminar último Propietario | 409 | `{message: 'No puedes eliminar al último Propietario'}` | — |
| Eliminar último Admin | 409 | mismo con Administrador | — |
| Eliminar último Empleado | 409 | mismo con Empleado | — |
| Actor se modifica a sí mismo (rol/status/permissions) | 422 | `{message: 'No puedes editarte'}` | — |
| Actor otorga permiso que no tiene | 403 | `{message: 'No puedes otorgar permisos que no posees'}` | — |
| Editar rol `is_system=true` | 403 | `{message: 'Roles del sistema no se editan'}` (Policy) | — |
| Eliminar rol `is_system=true` | 403 | mismo | — |
| Eliminar rol con users asignados | 409 | `{message: 'Cambia los users primero'}` | — |
| Bulk role change: algunos fallan | 200 | `{updated: 2, failed: [{user_id, reason}]}` | per success |
| Invitar email ya miembro | 409 | `{message: 'Ya es miembro'}` | — |
| Invitar email con invitación pending | 409 | `{message: 'Ya tiene invitación pendiente'}` | — |
| Desactivar miembro | 200 | JWT del afectado se blacklistea | `user.status_changed` |
| Miembro desactivado intenta usar app | 401 | `{message: 'Sesión revocada por administrador'}` | — |

### 25.14 Empresa y settings

| Caso | Status | Body | Audit |
|---|---|---|---|
| `PATCH /companies/settings` con key fuera del allowlist | 422 | `{errors:{<key>:['invalid']}}` | — |
| `menu_primary_color` con regex inválida | 422 | `{errors:{menu_primary_color:['regex']}}` | — |
| Logo > 5 MB | 422 | `{errors:{logo:['max:5120']}}` | — |
| Logo formato no permitido | 422 | `{errors:{logo:['mimes:png,jpg,jpeg,webp,svg']}}` | — |
| Cambiar `commercial_name` | (auto reissue JWT) | Sidebar refrescado | `company.updated` |
| Cambiar NIT (intento) | (silently ignored) | NIT no está en `$fillable` ni en FormRequest | — |
| Cache de settings sin invalidar | (auto on update) | `Cache::forget("company_settings:{nit}")` | — |

### 25.15 Sistema y operaciones

| Caso | Status | Body / Comportamiento | Audit |
|---|---|---|---|
| `php artisan db:seed` en producción | (DELEGA a ProductionSeeder) | Solo datos de referencia, sin demo | — |
| `php artisan db:seed` en QA/local | (DELEGA a QaSeeder) | Crea empresa demo + datos operativos | — |
| Re-seed (correr 2×) | (idempotente) | `clearOperationalData()` + `updateOrCreate` | — |
| Migration nueva en producción | `php artisan migrate --force` | Sin downtime esperado para migraciones aditivas | — |
| `vendor/bin/pint --dirty --format agent` falla | (bloquea commit) | Hay que corregir formato manualmente | — |
| Frontend cambio sin `npm run build` | UI desactualizada | Reload duro con `Ctrl+Shift+R` o build | — |
| Vite manifest faltante | 500 | `Illuminate\Foundation\ViteException`. Solución: `npm run build` | — |
| Cron `schedule:work` no corriendo | (silencioso) | Facturas, sync menús, purga chats no se ejecutan | — |
| Queue worker no corriendo | (silencioso) | Jobs (PDF reports, WA media, mark-read) quedan en `jobs` table | — |
| Storage disk lleno | 500 | `Storage::putFile` lanza | — |

### 25.16 UI / Frontend

| Caso | Comportamiento |
|---|---|
| Tab inactiva durante polling de chats | Polling sigue (no se pausa) — TODO mejorar a `visibilitychange` |
| Pérdida de conexión durante drag-drop kanban | Estado revierte; toast "Error de conexión, intenta de nuevo" |
| `lib/api.ts` recibe 401 con "revoc"/"invalid"/"expired" | `clearToken()` + `router.visit('/')` |
| Cambio de empresa mientras hay un modal abierto | Modal se cierra; toda la app re-renderiza |
| Secuencia `G` `D` con foco en input | **No** dispara (`GlobalShortcuts` ignora inputs/textarea/contenteditable) |
| Líder `G` sin segunda tecla en 1.5s | Secuencia se cancela; no pasa nada |
| Acorde reservado por navegador/SO (`Ctrl+B`, `Alt+D`…) | No se usa para atajos de la app; validado contra `RESERVED_SHORTCUTS` |
| `usePoll` Inertia pierde la pestaña | Sigue (hasta unmount) — TODO pausar en hidden |
| `useLivePolling` no se renueva tras 5 min | Se apaga solo (auto-off); operador debe re-activar |
| `useImageUpload` validation falla | No envía request; muestra error en componente |
| `setToken('')` con string vacío | Trata como logout — limpia localStorage + redirect |
| Cookie HttpOnly bloqueada por navegador (Safari ITP) | Fallback a Bearer en localStorage (back-compat) |

---

## 25.b Impresión de comandas térmicas (issue #116)

**Página**: `/company/printers` (gate `company.update`).

CRUD de impresoras térmicas por empresa. Cada impresora tiene `type` (kitchen/bar/cashier/customer_receipt), `connection` (usb/bluetooth/lan), `address` (URL del agente local PrintNode-style), `paper_width` (58 o 80 mm) y `categories[]` (lista cerrada de nombres de categoría del menú que atiende).

**Flujo de comanda**:
1. Servicio `CommandTicketService::printForOrder($order)` lee impresoras activas de la empresa con `type ∈ {kitchen, bar}`.
2. Particiona `order.items` por `category` y mapea cada partición a las impresoras cuyo `categories` contenga la categoría.
3. Ítems sin impresora destino se loguean como warning (no bloquean la orden — la comanda no es comprobante fiscal).
4. Despacha un `PrintCommandTicketJob` por cada (printer, items_subset). El job genera el buffer ESC/POS con `EscposTicketBuilder` y lo envía vía `HttpAgentDriver` al agente local.

**Reintentos**: el job tiene `tries=3` con backoff `[10, 30, 90]`. Cada éxito o fallo definitivo se audita.

**Auditoría**: `printer.created`, `printer.updated`, `printer.deleted`, `printer.tested`, `order.command_printed`, `order.command_reprinted`, `order.command_print_failed`.

**Botón Probar**: encola un job en modo `isTest` con un ítem ficticio "PRUEBA DE IMPRESION". Actualiza `printers.last_test_at` al éxito.

**Pendientes (no en este PR)**: disparador automático en transición a `in_kitchen`, botón Re-imprimir en detalle de orden, cliente WebUSB/WebBluetooth.

**Endpoints**:

| Método | Ruta | Permiso |
|--------|------|---------|
| GET | `/api/v1/company/printers` | `company.update,read` |
| POST | `/api/v1/company/printers` | `company.update,update` |
| PUT | `/api/v1/company/printers/{id}` | `company.update,update` |
| DELETE | `/api/v1/company/printers/{id}` | `company.update,update` |
| POST | `/api/v1/company/printers/{id}/test` | `company.update,update` |

---

## 26. Documentación complementaria

| Recurso | Ubicación | Propósito |
|---------|-----------|-----------|
| Wiki técnico | `../docs/wiki/` (raíz del repo) | Markdown público con páginas por dominio: Autenticación, Empresas, Multi-tenancy, Menú, Pedidos, Repartidores, Cupones, Horarios, Dashboard-Métricas, WhatsApp-Bot, Facturación, Usuarios-Roles-Permisos, Variables-de-Entorno, Errores-API, Guía-de-Contribución, Frontend |
| Guía visual | `FRONTEND_UI_GUIDELINES.md` | Paleta, espaciados, ejemplos UI |
| Provisioning EC2 | `../aws/ec2/install.sh`, `../aws/ec2/scripts/{deploy,healthcheck}.sh` | Bootstrap idempotente + deploy |
| Skills locales | `**/skills/**` | Domain-specific Claude skills |

Cada PR debe actualizar la página correspondiente del wiki cuando se modifique un endpoint, un permiso, una variable `.env` o un código de error.

---

## 27. Convenciones del proyecto

### URLs

- Todas las URLs son **en inglés**.
- Los **breadcrumbs son en español** y reflejan la jerarquía de la URL.
- Aliases de back-compat (302) para URLs antiguas que conservan `?token=`.

### Comandos típicos

```bash
# Backend
cd application
php artisan serve
php artisan migrate --no-interaction
php artisan tinker --execute 'User::count();'
vendor/bin/pint --dirty --format agent  # antes de commit

# Frontend
npm run dev              # vite + HMR
npm run build            # producción
npm run lint
npm run format

# Combo
composer run dev         # backend + frontend en paralelo
```

> La suite Playwright vivió en `testing/playwright-ui/` hasta #219 (HU de
> reestructuración). El workflow `playwright-qa.yml` también se retiró
> hasta que se reincorpore con su propia ruta versionada (sub-issue
> futuro).

### Política de testing

- **Este proyecto NO usa Pest/PHPUnit.** No se crean archivos `tests/` ni se ejecuta `php artisan test`.
- Verificación backend: Tinker, llamadas API manuales, log inspection o ejercer la feature en la UI.
- Verificación E2E: Playwright UI mode local.

### Política de documentación

> **Cualquier cambio en frontend o backend** debe actualizar `docs/wiki/FUNCIONALIDADES_APP.md`, `docs/wiki/FRONTEND_FILES.md` y `docs/wiki/BACKEND_FILES.md`.

(Regla en `CLAUDE.md` raíz del proyecto.)

---

## PWA (Progressive Web App) — Fase 1 (issue #103)

La app es instalable como PWA en Android, iOS y desktop. La Fase 2 (modo offline real con IndexedDB y `sync-batch`) se trabaja en el issue #140.

### Manifest dinámico

- Endpoint: `GET /manifest.webmanifest` → `App\Http\Controllers\PwaManifestController@show` (ruta nombrada `pwa.manifest`).
- Si el visitante tiene JWT válido con `active_company_nit`, el manifest hereda:
  - `name`: `"flexyflow · {commercial_name}"`.
  - `theme_color` y `background_color`: leídos de `CompanySettingsService::get($nit, 'menu_primary_color', '#FF6B35')`.
- Sin JWT o sin empresa activa → branding por defecto flexyflow (`#FF6B35`).
- Cache headers: `Cache-Control: private, max-age=300` (5 min) — evita golpear DB en cada visita y acepta un retraso pequeño en cambios de color.
- Fallback estático: `public/manifest.webmanifest` (mismo branding por defecto, usado si la ruta dinámica falla por cualquier razón).

### Service Worker

- Configurado en `vite.config.js` vía `vite-plugin-pwa` con `strategies: 'generateSW'` (Workbox).
- El bundle emite `public/build/sw.js`. Se sirve desde `/sw.js` vía `PwaManifestController::serviceWorker` para que el `scope` por defecto sea `/`. El controlador reescribe las URLs internas (`./workbox-*.js` y `assets/*` del precache) a paths absolutos `/build/...` para que sigan resolviendo correctamente.
- Registro manual en `resources/js/app.tsx` solo en `import.meta.env.PROD` para no interferir con HMR en desarrollo.
- Estrategias runtime:
  - `NetworkFirst` (timeout 5s, TTL 1d) para `GET /api/v1/(menus|orders|cash-register/current)`.
  - `CacheFirst` (TTL 7d) para imágenes en `/storage/`, `/images/`, `/icons/`.
  - `CacheFirst` (TTL 30d) para fuentes de `fonts.bunny.net`.
  - `NetworkOnly` por defecto para POST/PUT/DELETE (Workbox default).
- Precache: `**/*.{js,css,woff2}` del bundle de Vite.
- `clientsClaim: true`, `skipWaiting: false` — la nueva versión queda en `waiting` y el usuario decide cuándo recargar.

### Instalabilidad

- Iconos en `public/icons/`: `icon-192.png`, `icon-512.png`, `icon-192-maskable.png`, `icon-512-maskable.png`, `apple-touch-icon-180.png` (generados por `bistro/frontend/scripts/generate-pwa-icons.mjs` desde el SVG fuente del logo).
- Meta tags PWA inyectados en `resources/views/app.blade.php`: `theme-color`, `apple-touch-icon`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`, `apple-mobile-web-app-title`.
- Banner de instalación (`/dashboard`):
  - Android/desktop: `InstallPwaPrompt` captura `beforeinstallprompt`, banner sticky inferior con CTA "Instalar". Dismiss persiste 14 días en `localStorage` (`pwa_install_dismissed_at`).
  - iOS Safari: `IosInstallHint` muestra instrucciones "Compartir → Añadir a pantalla de inicio" (Safari no expone `beforeinstallprompt`). Dismiss persiste 14 días (`pwa_ios_hint_dismissed_at`).
  - Ambos componentes se ocultan automáticamente en `display-mode: standalone`.
- Atajos de la app (`shortcuts`): Caja, Tablero de órdenes, Menú.

### Actualizaciones

- `app.tsx` escucha el evento `waiting` del Workbox y emite el evento custom `pwa:update-available`.
- `UpdateAvailableToast` (montado en dashboard) muestra una notificación con botón "Recargar" cuando hay un bundle nuevo precacheado.

### Limitaciones conocidas

- iOS Safari no soporta `beforeinstallprompt` programático ni `Background Sync`. La Fase 2 (#140) cubre el caso vía polling cada 30s + listener `online`.
- IndexedDB en iOS Safari tiene quota más estricta (~50 MB en algunos modelos). La Fase 2 monitorea con `navigator.storage.estimate()` y avisa al 80%, bloquea nuevas órdenes offline al 95%.

---

## PWA — Fase 2: Modo offline real con IndexedDB y sync (issue #140)

Sobre la PWA base de Fase 1, la caja (`/orders/cashier`) sigue creando órdenes y registrando cobros sin internet. La cola se persiste en IndexedDB y se sincroniza idempotentemente al recuperar conexión.

### Iconos PWA por empresa (logo dinámico)

- `App\Services\LogoIconRasterizer` (driver GD via Intervention Image v3) genera 5 PNGs cuando la empresa sube su logo en `/company/settings`:
  - `companies/logos/{nit}/icon-192.png` (any · 192x192)
  - `companies/logos/{nit}/icon-512.png` (any · 512x512)
  - `companies/logos/{nit}/icon-192-maskable.png` (maskable con safe area 22%)
  - `companies/logos/{nit}/icon-512-maskable.png` (maskable con safe area 22%)
  - `companies/logos/{nit}/apple-touch-180.png`
- Fondo sólido = `menu_primary_color` de la empresa (CompanySettings).
- Best-effort: si la rasterización falla (formato exótico, GD sin soporte), se loggea y se sigue — el manifest cae al logo flexyflow por defecto.
- `PwaManifestController::show` apunta `icons[]` a estos archivos cuando existen; si no, cae a `/icons/icon-*.png` (flexyflow black-font sobre blanco).
- Apple Touch Icon dinámico: nueva ruta `GET /apple-touch-icon.png` (`pwa.apple-touch-icon`) que sirve la versión rasterizada de la empresa activa o el fallback flexyflow.
- Comando artisan para regenerar en bulk: `php artisan pwa:rasterize-logos [--nit=...]`.

### Logo por defecto

- Cuando NO hay JWT o la empresa no subió logo, los iconos se generan a partir de `public/images/logo-black-font.svg` (logo flexyflow texto negro sobre blanco). El script `scripts/generate-pwa-icons.mjs` produce los 5 PNGs base con `sharp`.

### Sincronización idempotente — endpoint

- `POST /api/v1/orders/sync-batch` (`api.orders.syncBatch`) — `App\Http\Controllers\Api\OrderSyncController@syncBatch`.
- Recibe `{ orders: [{ client_uuid (UUID v4), company_nit, order_type, items, created_at, payment? }] }`.
- Garantías:
  - **Multitenant estricto**: el `company_nit` se resuelve SOLO del JWT activo (`active_company_nit`). Cualquier `company_nit` del payload se compara y, si difiere, se rechaza el batch entero (422 `orders.{i}.company_nit`). Esto impide que un cliente comprometido empuje órdenes a otra empresa.
  - **Idempotencia**: por `(company_nit, client_uuid)` con `lockForUpdate`. Reintentos del mismo batch devuelven `status: 'duplicate'` con el `server_id` existente — NO se crean duplicados ni dobles cobros (CLAUDE.md: receipts inmutables).
  - **Re-validación de precios server-side**: el cliente NO inyecta precios; se leen del menú activo en BD.
  - **Conflictos no rechazan**: items marcados `unavailable` ahora se persisten con `sync_warnings: [{ type: 'item_unavailable', menu_item_id, sold_at }]` en `orders.sync_warnings` JSONB. El cliente real ya consumió en sitio.
  - **Skew temporal**: si `paid_at` del cliente difiere del server por >24h, se flagea `sync_warning.clock_skew` pero la orden se persiste.
  - **Audit log**: cada orden insertada (no las idempotencia hits) genera `order.synced_offline` en `audit_logs` con metadatos completos.
- Respuesta: `{ results: [{ client_uuid, status: 'created'|'duplicate'|'warning'|'failed', server_id, total, warnings, receipt_created }], summary: { orders_synced, receipts_synced, failed } }`.

### Bloqueo de cierre de caja con pendientes

- Decisión: **bloqueo duro sin escotilla**. Si el cajero tiene órdenes en IndexedDB sin sincronizar, el cierre se bloquea hasta drenar la cola.
- Doble validación: (1) `useCashRegister.closeSession` chequea `countPendingOrders()` antes de pegarle al backend; (2) `CashRegisterService::closeSession($pendingSyncCount)` rechaza con `422` si recibe `pending_sync_count > 0`.
- Mensaje al cajero: "Cierre bloqueado: hay N operaciones pendientes de sincronizar. Espera al sync antes de cerrar."

### UI offline

- `OfflineBanner` (sticky en `app-sidebar-layout`):
  - Online + 0 pending → no se renderiza.
  - Online + N pending → amarillo "N pendientes de sincronizar" + CTA Reintentar.
  - Offline + N pending → naranja "Modo offline · N en cola".
  - Offline >5 min → rojo "⚠ Riesgo de pérdida si borras los datos del navegador" + botón "Exportar pendientes (JSON)".
- `SyncToast` (top): toast efímero "✓ Sincronizadas N operaciones" cuando la cola se drena.
- `StorageQuotaWarning`: chequea `navigator.storage.estimate()` cada 60s; banner amarillo a >80%, rojo crítico a >95%.
- En cashier: si POST `/api/v1/orders` falla por red y `navigator.onLine === false`, encola la orden con `client_uuid` y muestra: "Orden encolada offline. Se sincronizará al recuperar conexión."

### Persistencia y mitigaciones de pérdida

IndexedDB es "best-effort"; mitigaciones implementadas:

1. **`navigator.storage.persist()`**: se solicita al montar la PWA. Chrome lo concede automático si la app está instalada.
2. **Sync agresivo**: drena en cuanto vuelve `online` + polling cada 30s mientras hay pendientes (cubre iOS sin Background Sync).
3. **Banner de riesgo a 5 min**: avisa explícitamente que borrar datos del navegador implica perder pendientes.
4. **Export JSON manual**: botón "Exportar" en el banner descarga todos los pendientes como respaldo.
5. **Cierre de caja bloqueado**: fuerza drenar antes de cerrar la sesión, evitando olvidar pendientes durante días.

Lo que NO se garantiza: si el usuario borra "Datos del sitio" en el navegador, los pendientes se pierden. La operación offline está pensada para horas, no días.

### Métricas de operación offline

- `GET /api/v1/metrics/offline/operation?period=today|week|month|custom` (`api.metrics.offline.operation`).
- Agrega `offline_sync_events` (tabla append-only que el sync controller alimenta por batch) y devuelve totales (orders_synced, receipts_synced, amount_synced, failed) + serie diaria.
- Se renderiza en `/company/metrics` mediante `OfflineOperationPanel`. Se oculta automáticamente si la empresa nunca operó offline.

### Stack técnico

| Capa | Herramienta | Versión |
|---|---|---|
| IndexedDB wrapper | `idb` | ^8.0 |
| Sync engine | Custom queue + backoff exponencial 1s-60s con jitter | — |
| Rasterización logos | `intervention/image` (driver GD) | ^3.11 |
| Service Worker (heredado) | `vite-plugin-pwa` (Workbox) | ^1.3 |

## Escalado horizontal multi-EC2 (issue #43)

Hasta este issue el backend corría con `DesiredCapacity=1` por estado local en
EC2 (uploads en FS, sesiones en disk, scheduler corriendo en cada nodo).
Ahora el stack está listo para N=2+ instancias.

### Qué cambió

- **Storage cross-node:** los uploads viven en S3 (bucket público
  `flexyflow-panel-{env}-assets`). PDFs de factura y reportes en
  bucket privado `*-documents` (DIAN, 10 años). Local: MinIO en Docker.
- **Sesiones cross-node:** tabla `sessions` en Supabase (compartida). JWT
  cifrado (cookie `flexyflow_jwt`) lleva la identidad sin depender de
  stickiness del ALB. Inertia flash sigue en session.
- **Schedules sin duplicar:** todos usan `->onOneServer()` que se coordina
  vía `cache_locks` compartido. Job canary `healthcheck:heartbeat` corre
  cada minuto y permite verificar que sólo una EC2 ejecuta cada tick.
- **Health check sólido:** `/health/ready` valida DB (Supabase) + S3 antes
  de responder al ALB. Si una EC2 pierde la DB, se la saca de rotación
  (180s = 3×60s).
- **Migraciones single-runner:** corren una sola vez desde el workflow GHA
  contra una EC2 elegida, antes del SSM general. Sin race en multi-nodo.

### Detalle técnico

Ver `docs/wiki/BACKEND_FILES.md` → sección "Escalado horizontal multi-EC2"
para configuración de discos, env vars, comandos y guardas anti-fuga DIAN.

### Operación

- Subir capacidad: editar `aws/iac/cloudformation/parameters/{env}.json`
  → `DesiredCapacity`, redesplegar stack `06-asg`.
- Validar post-merge: revisar CloudWatch Logs Insights buscando
  `healthcheck.heartbeat` — debe haber 1 entrada/minuto sin importar
  cuántos nodos haya.

---

## Verificación de propiedad de la empresa (#154)

Toda empresa nueva debe adjuntar al final del enrolamiento un **documento de
propiedad** (cámara de comercio, RUT, cédula del representante legal, etc.).
Esa evidencia se guarda en S3 privado y la empresa queda en
`pending_activation` hasta que un operador externo la transicione a `verified`
o `rejected` vía GitHub Action.

### Estados canónicos (`config/companies.php`)

- `pending_activation` — default tras enrolamiento (estado existente reutilizado).
- `verified` — única que habilita uso pleno de la plataforma.
- `rejected` — la verificación fue rechazada (la empresa puede contactar soporte).
- `active` — equivalente legacy a `verified`; preservado por compatibilidad.

### Captura de la evidencia

- Campo `proof_document` en `POST /api/v1/enrollment/company` (multipart/form-data).
- Formatos: PDF, Word (`.doc`/`.docx`), JPG, PNG. Máx 10 MB. Validado por MIME real.
- Persistencia: tabla `enrollment_proofs` (1:1 con `companies`) + archivo en S3
  disk `s3_documents`, key `enrollment-proofs/{nit}/{timestamp}-{slug}.{ext}`.
- Auditoría: `audit_logs.action = 'enrollment.proof_uploaded'` con metadata
  (key, mime, tamaño, filename, uploader).
- Conservación: soft-delete obligatorio (DIAN 5–10 años).

### Gate de operación

- Middleware `EnsureCompanyVerified` (alias `company.verified`) bloquea toda
  ruta de mutación si `company.status` no está en `config('companies.verified')`.
- Respuesta 403 JSON: `{ message, code: 'company_not_verified', status }`.
- El frontend (`lib/api.ts`) detecta `code` y redirige a
  `/company/under-review`, que muestra estado + opción de cerrar sesión.
- Excepciones del gate: `GET /api/v1/companies/active` y
  `GET /api/v1/enrollment/proof/preview` (URL firmada al uploader para revisar
  lo que adjuntó).

### Cambio de estado (operación externa)

Se ejecuta desde el workflow **`Company Status (per env)`**
(`.github/workflows/company-status.yml`).

- Inputs: `environment` (qa|pdn), `nit`, `status` (verified|rejected|pending_activation),
  `reason` (5-500 chars, obligatorio), `actor` (default `github-actions:<actor>`).
- Conexión psql directa a Supabase con TLS. Credenciales del GitHub Environment.
- Transacción atómica: `SELECT ... FOR UPDATE` → validación de transición →
  `UPDATE companies` → `INSERT audit_logs` con `action='company.status_changed_external'`,
  `source='github_action'`, `workflow_run_url`, `reason`.
- Idempotente: si el estado ya coincide, marca `OUTCOME=no_op` y no audita.
- Transiciones permitidas: `pending_activation → verified|rejected` y
  `rejected → pending_activation` (reintento).
- Recomendado activar **Required reviewers** en el GitHub Environment `pdn`
  para que el workflow requiera aprobación humana antes de tocar producción.

Detalle técnico y queries de auditoría: `BACKEND_FILES.md` → sección
"Enrolamiento — Verificación de propiedad (#154)".


---

## 13. Colaboradores y planificador de turnos (#182)

Módulo nuevo HU #182. Permite gestionar perfiles HHRR de colaboradores
(con o sin usuario en el sistema), asignar turnos semanales por sede,
controlar estados de vinculación (active/inactive/vacation/sick_leave/
compensatory) y consultar informes de horas + costo estimado.

### Pantallas

- `/configuracion/colaboradores` — listado con filtros (sede, cargo,
  estado), búsqueda por nombre/documento/email, paginación. Permiso:
  `employees.read`.
- `/configuracion/colaboradores/nuevo` — formulario HHRR completo
  (identidad, contacto, seguridad social, contrato y pago). Crea fila
  en `employees` y, si el email coincide con un user de la empresa,
  enlaza automáticamente `user_id`.
- `/configuracion/colaboradores/{id}` — detalle + edición + cambio de
  estado de vinculación (con cascada de cancelación de turnos en
  vacaciones/incapacidad/compensatorio) + revelar salario auditado.
- `/configuracion/colaboradores/informes` — tabla agregada por
  colaborador (horas asignadas/ejecutadas/canceladas + costo estimado)
  con exportación CSV y PDF.
- `/planner` — vista semanal de turnos (filas: colaborador, columnas:
  días). Click en celda permite asignar/cancelar turno. Permiso:
  `shifts.read` para ver, `shifts.manage` para mutar.
- `/planner/calendar` — calendario mensual con horas planificadas
  vs canceladas; drill-down a vista semanal.
- `/me/agenda` — agenda del colaborador (sin permisos especiales, requiere
  perfil `employees` vinculado).
- `/me/perfil` — perfil + salario enmascarado con icono 👁 para revelar
  (audita `employee.salary_viewed_self`).

### Reglas contables

- `pay_rate` y `base_salary`: `decimal(12,2)` con cast `decimal:2`.
- Mutaciones envueltas en `DB::transaction` + `lockForUpdate` sobre el
  `employee` o `shift` afectado.
- Toda operación audita vía `AuditService::log` con metadata accionable
  (`employee.created`, `shift.cancelled`, `employee.vinculation_changed`,
  etc.).
- Soft-delete obligatorio (`archived_at`) para conservación DIAN 5/10 años.
- Cálculos de reportes en SQL (subqueries por estado de turno) — no se
  itera en PHP. Costo estimado normaliza pay_rate × horas según `pay_type`.

### Reglas de desactivación (§12 del plan)

`EmployeeVinculationPolicy::denialReason()` centraliza las 4 reglas:

1. Auto-desactivación prohibida (`REASON_SELF`).
2. Owner indesactivable mientras tenga rol `Propietario`
   (`REASON_TARGET_IS_OWNER`).
3. Admin no puede desactivar a un owner
   (`REASON_ADMIN_CANNOT_DEMOTE_OWNER`).
4. Owners pueden con cualquiera salvo a sí mismos y a otros owners
   (cubierto por reglas 1 y 2).

Cada bloqueo audita `employee.vinculation_change_denied` con el motivo.

### Caja con turno activo

`ShiftActiveGuardService::assertActiveShift($user, $companyNit, $branchId)`
se invoca en `CashRegisterController::open()` y `close()`. Verifica que
el actor tenga un `employee_shift` `scheduled` en la sede con `NOW()`
dentro de la ventana. **Propietario** y **Administrador** bypasean — pueden
operar caja sin turno por responsabilidad supervisoria. El rol Empleado y
los roles custom (Cocina, Domiciliario, etc.) sí requieren turno activo.

### Sugerencias automáticas

`ShiftSuggestionService::suggestForWeek(...)` genera un borrador equitativo
(round-robin sobre horas acumuladas) respetando jornada máxima y mínimo de
días libres por empresa (con override por empleado). Solo opera dentro de
la sede principal del colaborador.

### Permisos RBAC

| Slug | Owner | Admin | Empleado |
|---|---|---|---|
| `employees.read/create/update/delete` | ✅ CRUD | ✅ CRUD | ❌ |
| `employees.view_salary` | ✅ | ✅ | ❌ |
| `shifts.read` | ✅ | ✅ | ✅ (solo /me) |
| `shifts.manage` | ✅ | ✅ | ❌ |
| `shifts.suggest` | ✅ | ✅ | ❌ |
| `workforce.reports` | ✅ | ✅ | ❌ |
| `workforce.settings` | ✅ | ✅ | ❌ |

Las features se siembran en `FeatureSeeder` (grupo Colaboradores /
Planificador / Reportes). `EmployeesFeatureBackfillSeeder` (idempotente,
en `ProductionSeeder`) proyecta los permisos sobre los roles del sistema
de empresas existentes. `WorkforceSettingsBackfillSeeder` siembra la
configuración default. Empresas nuevas reciben ambas en el
`CompanyEnrollmentController`.

### Fuera de alcance

- Notificaciones (in-app/email/WhatsApp) al asignar o cancelar turnos.
- Self-service del colaborador (solicitar cambio, días libres).
- Check-in/check-out explícito (la validación por hora actual basta).
- Integración de nómina (prestaciones, parafiscales, retención).
- Facturación electrónica DIAN para OPS.
- Definición de demanda por sede (hoy el admin la define).

---

## HU #200 — Sanitización transversal de inputs

**Estado**: Implementada. Política única en `docs/wiki/SECURITY_INPUT_HANDLING.md`.

**Alcance**:
- Backend: capa central de saneamiento (trait `SanitizesInput`, reglas
  `NoControlCharacters` + `SafePlainText`, middleware `NormalizeStrings`
  con NFC sobre web+api). 10 FormRequests críticos hardeneados + 17
  controllers con `validate()` inline migrados.
- Migración one-off de data histórica con trazabilidad en `audit_logs`
  (`action='sanitize.migrated'`, hash SHA-256 before/after, idempotente,
  batched 500).
- Frontend: helper `lib/input-sanitize.ts`, schemas zod, primitive
  `<SanitizedInput>`, refactor de `<Markdown>` con `rehype-sanitize` +
  `rehype-external-links`. 6 formularios críticos sanean en `onChange`.
- Canales de salida: `EscposTicketBuilder` filtra bytes ESC/POS de
  texto del usuario; CSP report persiste en `audit_logs` para dashboards.
- Config CSP: `.env.example` documenta el rollout gradual. QA/PDN flip
  fuera de scope del PR.

**Impacto RBAC**: Ninguno. La sanitización aplica por igual a todos los
roles (owner incluido — no es excepción). Sin permisos nuevos ni cambios
al catálogo.

**Compatibilidad N-instance**: La capa es por-request; no comparte
estado entre instancias. La migración one-off corre en deploy una sola
vez (idempotente).

