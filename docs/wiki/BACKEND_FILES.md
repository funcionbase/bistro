# BACKEND_FILES.md — Inventario Técnico Backend

> Referencia técnica completa del backend Laravel 12 de flexyflow Restaurante.
> Documento canónico para desarrollo, troubleshooting y manuales operativos.
> Cubre: rutas, controladores, modelos, servicios, middleware, jobs, comandos, configuración, RBAC, auditoría y multi-tenancy.

---

## Stack técnico

| Componente | Versión | Notas |
|-----------|---------|-------|
| PHP | 8.2 | Constructor property promotion, typed returns, enums, readonly |
| Laravel | 12.x | Estructura streamlined: middleware en `bootstrap/app.php`, no hay `Kernel.php` |
| PostgreSQL | 14+ | NIT como FK; UTF-8; índices compuestos `(company_nit, *)` |
| JWT | HS256 + AES-256 | Implementación propia (`JwtService`); cookie HttpOnly + Bearer fallback |
| RBAC | Feature-based | 4 acciones por feature (read/create/update/delete) + overrides por usuario |
| Inertia.js | v2 | Lado servidor: `Inertia::render()` retorna props para React |
| Queue | database / redis | `QUEUE_CONNECTION=database` por defecto (tabla `jobs`) |
| Cache | database / redis | TTL configurable por dominio (`config/metrics.php`, `config/company_settings.php`) |
| PDF | DomPDF | `barryvdh/laravel-dompdf`; `config/dompdf.php`, `config/pdf.php` |
| OAuth | laravel/socialite v5 | Sólo Google habilitado (`auth/google`) |
| Mail | log (local) / ses (qa/pdn) | Amazon SES via IAM instance profile; templates `vendor/mail/html` personalizadas con branding flexyflow. Ver [`EMAIL_SES_SETUP.md`](EMAIL_SES_SETUP.md) |
| Storage | local / s3 | Disco `public` para logos/QR; `private` para PDFs de facturas |
| Pint | v1 | `vendor/bin/pint --dirty --format agent` antes de commitear |

---

## Resumen del inventario

| Categoría | Conteo | Ubicación |
|-----------|--------|-----------|
| Controladores | 78 | `app/Http/Controllers/` (incluye subcarpetas `Api/`, `Auth/`, `Billing/`, `Company/`, `Concerns/`, `Enrollment/`, `Menu/`, `Reports/`, `Settings/`, `Web/`) |
| Modelos Eloquent | 68 | `app/Models/` |
| Servicios | 52 | `app/Services/` (incluye `Alerts/`, `Analytics/`, `Printing/`, `Whatsapp/`) |
| Form Requests | 71 | `app/Http/Requests/` (15 subcarpetas) |
| Middleware | 10 | `app/Http/Middleware/` |
| Jobs | 6 | `app/Jobs/` |
| Comandos Artisan | 13 | `app/Console/Commands/` |
| Notificaciones | 3 | `app/Notifications/` |
| Policies | 4 | `app/Policies/` |
| Migraciones | 20 | `database/migrations/` (consolidadas en bloques `0001_01_01_*` + deltas recientes `2026_05_*`) |
| Seeders | 15 | `database/seeders/` |
| Rutas (totales) | ~260 | `routes/{web,api,console}.php` |
| Páginas Inertia (web) | 60 | rutas web |
| Endpoints API v1 | ~200 | `routes/api.php` |
| Endpoints externos (bot) | 7 | `api/external/*` (hours/status, chats/handoff + messages, loyalty/lookup + redeem) |
| Configuraciones | 32 | `config/` (incluye `payments.php` #203, `rbac.php` #203, `employees.php` #204) |
| Catálogos canónicos (referencia humana) | 20 | `bistro/backend/constants/` — `.md` por dominio; NO se bundlea ni se autoloadea |

---

## Catálogos canónicos compartidos (`bistro/backend/constants/`)

Carpeta `bistro/backend/constants/` con archivos `.md` que actúan como fuente única de verdad documental para conceptos que viven duplicados entre backend y frontend (RBAC, estados de orden, métodos de pago, tipos de documento legal, etc.). **NO se importa en runtime** — ningún `vite.config.js` ni `composer.json` la consume. Referencia para desarrolladores y agentes Claude antes de modificar catálogos cerrados.

Archivos clave:

- `README.md` — propósito + exclusión de builds + plantilla.
- Núcleo RBAC (#201): `ROLES_SYSTEM.md`, `ROLES_TEMPLATES.md`, `ROLES_DEMO.md`, `PERMISSIONS_CATALOG.md`, `COURIER_MODE.md`, `BRANCH_RBAC.md`, `RBAC_CHECKLIST.md`.
- Operaciones y contabilidad (#202): `ORDER_STATUSES.md`, `PAYMENT_METHODS.md`, `ACCOUNTING_RULES.md`, `MIDDLEWARE_MAP.md`, `AUDIT_EVENTS.md`, `FEATURES_INDEX.md`.
- Usuarios, legal y entregas (#203): `USER_STATUSES.md`, `LEGAL_DOCUMENT_TYPES.md`, `DELIVERY_STATUSES.md`.
- Colaboradores y tributario (#204): `EMPLOYEE_STATUSES.md` (espejo de `config/employees.php`), `TAXES_AND_REGIMES.md` (espejo de `config/taxes.php`).
- Empresa (#205): `COMPANY_STATUSES.md` (espejo de `config/companies.php` + `lib/company-status.ts`; cubre buckets `verified` / `pending` / `blocked` / `fully_blocked` y workflow ops).

Regla obligatoria en `CLAUDE.md` §7: antes de tocar un permiso, rol, estado de orden, método de pago, tipo de documento legal o columna que dispare cambio de catálogo cerrado, consultar el `.md` correspondiente y actualizarlo en el mismo PR.

---

## Arquitectura de alto nivel

```
┌────────────────────────────────────────────────────────────────┐
│  Cliente (navegador)                                            │
│  ┌──────────────┐    ┌──────────────────┐                       │
│  │ React 19 SPA │ ←→ │ Inertia.js bridge │                       │
│  └──────────────┘    └──────────────────┘                       │
│         │                       │                                │
└─────────┼───────────────────────┼────────────────────────────────┘
          ↓ apiFetch              ↓ Inertia visit
   ┌──────────────────┐    ┌──────────────────┐
   │ /api/v1/* (REST) │    │ routes/web.php   │
   │ ValidateJwt +    │    │ resolveWebJwt()  │
   │ EnsureCompany +  │    │ Inertia::render  │
   │ permission       │    │                  │
   └────────┬─────────┘    └────────┬─────────┘
            ↓                       ↓
       Controllers                Closures + Inertia
            ↓
       Services / Models / DB

       Eventos asíncronos:
       Webhook WhatsApp → handler → chat/messages
       Cron: facturas, expiración, sync menús, purga chats
       Jobs: PDF reports, media WhatsApp, mark-read
```

**Multi-tenancy:** cada request autenticado lleva `active_company_nit` en el JWT y se inyecta en `request->attributes` por `EnsureCompanyAccess`. Todas las queries de dominio filtran por ese NIT (no hay scope global automático — es responsabilidad del controlador/servicio).

---

## Autenticación: JWT + acceso dual (Google OAuth / correo+contraseña)

**Acceso dual**: una cuenta = un correo; `google_id` y `password` son dos credenciales de la MISMA fila `users`. Registrado por email que luego entra con Google → el callback vincula por email verificado. Creado con Google → fija contraseña vía forgot-password o en Ajustes › Contraseña (sin contraseña actual si `password` es null). Endpoints JSON: `POST /api/v1/auth/{login,register,forgot-password,reset-password}` (guest, limiters `auth-login` 20/min IP + lockout 5/60s por email+IP en `EmailAuthController`, `auth-register` y `auth-forgot` 5/15min IP) y `GET|POST /api/v1/auth/verification/{status,resend}` (JWT, resend 3/10min por user). Registro: honeypot `website` (éxito falso), `Password::defaults()` = min 8 + `uncompromised()` (HIBP), crea `pending_enrollment` sin verificar + `VerifyEmailAddressNotification` (URL firmada 60 min → `verification.verify`, pública `signed`, marca `email_verified_at` y redirige al SPA según cookie JWT: mismo user → `/enrollment/user` o `/enrollment/company`; sin cookie → `/login?verified=1`). **Gate**: `CompanyEnrollmentRequest::authorize()` → 403 `email_not_verified` si el correo no está verificado (corre ANTES de las reglas). Cuentas Google legacy: `User::ensureGoogleEmailVerified()` backfillea `email_verified_at` en callback/status/gate. Post-login por email: `PostLoginService` espeja las reglas del callback Google devolviendo rutas SPA (sin verificar → `/verify-email`; pending → `/enrollment/user`; activo sin empresas → `/enrollment/company`; 1 activa → dashboard/my-deliveries; resto → company-selector). Reset de contraseña marca el correo verificado (probó posesión). Anti-enumeración: login y forgot responden genérico siempre.

### Flujo end-to-end (Google)

```
1. Usuario → /auth/google                    [GoogleAuthController@redirect]
2. Google → /auth/google/callback?code=...   [GoogleAuthController@callback]
   - Si email no existe → User::create(status='pending_enrollment')
   - Persiste email_verified_at (Google ya verificó; backfill legacy)
   - JwtService::issue(user) → cookie HttpOnly
   - Si tiene 1 empresa → /dashboard
   - Si tiene varias → /auth/company-selector
3. Usuario selecciona empresa → POST /api/v1/auth/select-company
   [AuthController@selectCompany]
   - Reissue JWT con active_company_nit
   - Cliente recibe nuevo token (cookie + X-Refresh-Token)
4. Cada request autenticado:
   - ValidateJwt extrae cookie/Bearer/session/query
   - Verifica HS256 + descifra payload AES-256
   - Si exp - now < 300s → reissue automático
   - Inyecta jwt_payload, jwt_user_id en request attrs
5. EnsureCompanyAccess valida membresía y inyecta active_company_nit
6. permission:feature,action evalúa RBAC
```

### Estructura del payload JWT

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `sub` | int | User ID |
| `email` | string | Email del usuario |
| `enrollment_step` | string | `pending_enrollment` / `pending_company` / `completed` |
| `active_company_nit` | string | NIT de la empresa activa (null si está en multi-selector) |
| `companies` | array | `[{nit, commercial_name, role: {name, permissions}}, ...]` |
| `active_branch_id` | uuid \| null | Sede activa (multi-sede #117). Auto-seleccionada si N=1; null si usuario debe elegir entre N. |
| `active_branch_name` | string \| null | Nombre informativo de la sede activa. |
| `active_branch_slug` | string \| null | Slug de la sede activa. |
| `branches` | array | `[{id, name, slug, is_default, address, city}, ...]` accesibles para `active_company_nit`. |
| `iat` | int | Issued at (timestamp) |
| `exp` | int | Expiration (timestamp) |

### Configuración

| Variable | Default | Descripción |
|----------|---------|-------------|
| `JWT_SECRET` | (requerido) | Clave HS256 para firmar |
| `JWT_PAYLOAD_ENCRYPTION_KEY` | (requerido) | Clave AES-256-CBC para cifrar el payload |
| `JWT_TTL` | 21600 | Vida del token / ventana de inactividad deslizante (segundos, 6 h) |
| `JWT_MAX_LIFETIME` | 43200 | Tope absoluto de sesión (segundos, 12 h); el refresh no lo supera |
| `JWT_REFRESH_TTL` | 20160 | TTL de refresh (minutos, ~14 días) |
| `JWT_BLACKLIST_ENABLED` | true | Si `true`, los tokens revocados van a caché y `verify()` los rechaza |
| `JWT_COOKIE_NAME` | `flexyflow_jwt` | Nombre de la cookie HttpOnly |

### Cookie de JWT

- **Nombre:** `flexyflow_jwt` (excluida de Laravel `EncryptCookies` porque ya viene cifrada).
- **HttpOnly:** `true` (no accesible a JavaScript).
- **Secure:** `true` en producción (HTTPS) — `config('session.secure')`.
- **SameSite:** `config('session.same_site')`. En qa/pdn es `none` (deploy cross-origin: SPA y API en hosts distintos). `JwtService::buildCookie` aplica un guard: si `same_site=none` con `secure=false` (caso local sobre HTTP) lo degrada a `lax` para no descartar la cookie.
- **Path:** `/`.
- **TTL:** `ceil(JWT_TTL / 60)` minutos.

### Servicios JWT

| Servicio | Propósito | Variables |
|----------|-----------|-----------|
| `JwtService` | JWT estándar de usuario (issue, verify, reissue, revoke, blacklist) | `JWT_*` |
| `BotJwtService` | JWT para bots externos (WhatsApp, app de pedidos) | `BOT_JWT_SECRET`, `BOT_JWT_TTL` |
| `CartJwtService` | JWT de sesión de carrito anónimo (sin user) | `CART_JWT_SECRET`, `CART_JWT_TTL` |

`BotJwtService` y `CartJwtService` son resueltos perezosamente vía `Container::make()`; si las claves no están configuradas el middleware retorna 401 (no 500).

---

## RBAC — Modelo de permisos

### Tabla `features`

`features.slug` único. Cada feature pertenece a un grupo. Lista canónica seedeada por `FeatureSeeder` (60 filas).

| Slug | Display | Grupo | Owner-only? |
|------|---------|-------|-------------|
| `orders.read` / `orders.create` / `orders.update` / `orders.delete` | Pedidos (CRUD) | Órdenes | no |
| `users.read` / `users.update` | Usuarios | Usuarios | no |
| `roles.create` / `roles.read` / `roles.update` / `roles.delete` | Roles | Usuarios | no |
| `menu.read` / `menu.create` / `menu.update` / `menu.delete` | Menú | Menú | no |
| `coupons.create` / `coupons.read` / `coupons.update` / `coupons.delete` | Cupones | Cupones | no |
| `deliveries.read` / `deliveries.create` / `deliveries.update` / `deliveries.delete` | Entregas | Entregas | no |
| `deliveries.self_assign` | Auto-asignación de entregas (mobile-first del courier #119) | Entregas | no — default rol Domiciliario, asignable a otros |
| `hours.read` / `hours.update` | Horarios | Horarios | no |
| `reports.read` | Reportes (incluye métricas y alertas) | Reportes | no |
| `metrics.view_all_branches` | Reportes consolidados cross-sede | Reportes | no |
| `company.update` | Editar empresa | Empresa | no |
| `billing.read` | Ver facturación | Facturación | no |
| `chats.read` / `chats.update` | Chats | Chats | no |
| `inventory.read` / `inventory.create` / `inventory.update` / `inventory.delete` | Inventario de insumos (#111) | Inventario | no |
| `warehouses.manage` | Bodegas multi-bodega (#120) | Inventario | no |
| `suppliers.read` / `suppliers.create` / `suppliers.update` / `suppliers.delete` | Proveedores | Compras | no |
| `purchases.read` / `purchases.create` / `purchases.update` / `purchases.receive` / `purchases.pay` / `purchases.delete` | Órdenes de compra (#118) | Compras | no |
| `branches.manage` | Crear/editar/archivar sedes (#117) | Sedes | no |
| `branches.assign_users` | Asignar usuarios a sedes | Sedes | no |
| `branches.copy_menu` | Copiar menú entre sedes | Sedes | no |
| `branches.view_all` | Ver todas las sedes | Sedes | no |
| `clients.read` / `clients.update` / `clients.delete` | CRM básico (#123) | Clientes | no |
| `loyalty.read` | Ver fidelización (#122) | Fidelización | no (en `DEFAULT_EMPLOYEE_PERMISSIONS`) |
| `loyalty.update` | Ajustar puntos y canjear | Fidelización | no |
| `whatsapp.read` | Ver WhatsApp | WhatsApp | no |
| `whatsapp.connect` | Conectar WhatsApp | WhatsApp | no (admin+) |
| `whatsapp.update` | Editar WhatsApp | WhatsApp | no (admin+) |
| `whatsapp.swap_phone` | Cambiar número | WhatsApp | **sí** (`is_owner_only=true`) |
| `whatsapp.disconnect` | Desconectar WhatsApp | WhatsApp | **sí** (`is_owner_only=true`) |

Notas:
- **Alertas accionables (#124)** y **recetas (#112)** no tienen feature slug propio; reutilizan `reports.read` (alertas) y `menu.create/update/delete` (recetas).
- **Food cost (#113)** y **menu engineering (#114)** consumen `reports.read` (métricas).
- **Cash register / sesiones de caja** consume `orders.read`/`orders.create` (apertura ligada al rol que vende).
- Las features con `is_owner_only=true` se muestran como **no degradables** en la UI de roles y exigen verificación adicional por OTP al correo del owner.

### Tabla `company_role_permissions`

```
company_role_id  → company_roles.id
feature_id       → features.id
can_create  bool
can_read    bool
can_update  bool
can_delete  bool
```

### Roles del sistema (`is_system=true`)

Definidos por seeder `permission_templates` y replicados en cada empresa al crearla. Los roles del sistema **omiten la verificación RBAC** (por bypass en `FeaturePermissionService` cuando `is_system=true`).

| Rol | Permisos |
|-----|----------|
| `Propietario` (owner) | Todos (full CRUD en todas las features) |
| `Administrador` (admin) | Casi todo, sin `whatsapp.swap_phone`/`disconnect` |
| `Empleado` (employee) | Sólo `orders.read`, `chats.read` (configurable) |

Los nombres canónicos están en `config/roles.php` (`role_names`). En BD pueden ser personalizados por empresa.

### Overrides por usuario

Los `CompanyUser.custom_permissions` (JSONB) permiten otorgar/quitar permisos específicos sobre el rol base. Reglas:

- El actor **no puede otorgar** permisos que él mismo no tiene (validado en `UserRoleController::updatePermissions`).
- Sólo aplican a usuarios no-owner.
- Si el usuario tiene `is_system=true` en su rol base, los overrides no aplican.

### Middleware `permission:`

Sintaxis: `->middleware('permission:slug.feature,verb')` o `permission:slug,verb`.

```php
Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])
    ->middleware('permission:orders.update,update');
```

El primer parámetro es el slug (puede traer punto si la feature está nombrada con punto: `whatsapp.read`); el segundo es uno de `read|create|update|delete`.

---

## Middleware

| Clase | Alias | Tipo | Propósito y efectos |
|-------|-------|------|---------------------|
| `ValidateJwt` | `jwt` | Auth | Extrae JWT (cookie → Bearer → session flash → query). Verifica + descifra. Auto-refresca si exp-now < 300s. Inyecta `jwt_token`, `jwt_payload`, `jwt_user_id`. 401 si falla. |
| `EnsureCompanyAccess` | `company.access` | Auth/multi-tenant | Lee `active_company_nit` del payload, valida membresía vía `CompanyUser`. Inyecta `active_company_nit`, `user_role`, `company_role_id`. Audita `company.unauthorized_access` y devuelve 403 si no es miembro. |
| `EnsureCompanyVerified` | `company.verified` | Gate de verificación (#154) | Aplica DESPUÉS de `company.access`. Devuelve 403 con `{ code: 'company_not_verified', status }` si `companies.status` no está en `config('companies.verified')`. El frontend usa `code` para redirigir a `/company/under-review`. |
| `EnsureCompanyNotBlocked` | `company.not_blocked` | Gate de mora (#175 + #193) | Bloquea rutas operativas si `companies.status` está en `config('companies.fully_blocked')` (hoy `suspended`). **Dual context**: API responde `403 + JSON` con `code='company_payment_blocked'`; web (registrado en `bootstrap/app.php` después de `HandleInertiaRequests`) redirige `302 → /dashboard` con flash `payment_blocked`. Allow-lists separadas por contexto (API: `api.billing.*`, `api.companies.active`, `api.auth.logout`, `api.auth.switch-company`; web: `dashboard`, `company.settings`, `company.preferences`, `billing`, `auth.*`, `password.*`, `pwa.*`, `health.*`, `public.*`, etc.). En web resuelve `active_company_nit` extrayendo el JWT inline (cookie/header) cuando no está en attributes. Audita `company.access_blocked_by_suspension` con throttle 1/min por user+ruta vía `Cache::add` (requiere cache store compartido en PDN). |
| `EnsureBranchAccess` | `branch.access` | Auth/multi-sede (#117) | Lee `active_branch_id` del payload. Valida que pertenezca a la empresa activa, no esté archivada y exista fila en `branch_users`. Inyecta `active_branch_id` + `active_branch` (modelo). Códigos: `NO_ACTIVE_BRANCH` (422), `BRANCH_FORBIDDEN` (403), `BRANCH_ARCHIVED` (422), `BRANCH_COMPANY_MISMATCH` (422). |
| `EnsureFeaturePermission` | `permission` | RBAC | Parametrizado: `permission:feature,action`. Roles `is_system` bypass. Consulta `company_role_permissions` + overrides. 403 si denegado, 500 si action inválida. |
| `ValidateBotJwt` | `bot.jwt` | Auth externa | Valida Bearer con `BOT_JWT_SECRET`. Inyecta `bot_jwt_payload`, `bot_company_nit`. Sin `EnsureCompanyAccess`. 401 si falta secret en `.env`. |
| `ForceJsonResponse` | (prepend api) | Negociación | Aplicado al grupo `api`: setea `Accept: application/json` en cada request a `/api/*` para que ValidationException devuelva 422+JSON aunque el cliente olvide el header. |
| `SecurityHeaders` | (auto-aplicado) | Seguridad | Setea `X-Frame-Options=DENY`, `X-Content-Type-Options=nosniff`, `Referrer-Policy`, `Permissions-Policy`, CSP con nonce, HSTS. |
| `HandleInertiaRequests` | (auto-aplicado) | Inertia | Comparte props globales: `auth.user`, `flash`, `errors`, `csp_nonce`, `app.name`. |

Registro: `bootstrap/app.php → withMiddleware(function (Middleware $middleware) { ... })`.

---

## Multi-sede (#117)

Una empresa (NIT) puede tener N sedes (`branches`). Cada sede tiene su propio inventario, caja, cupones, ingresos y reportes — los datos NO se cruzan entre sedes (regla innegociable).

### Tablas

| Tabla | Notas |
|-------|-------|
| `branches` | uuid PK, FK `company_nit`, `slug` único por empresa, `is_default` (informativo), `archived_at` soft-archive. |
| `branch_users` | Pivot user × branch con `granted_by_user_id` y `granted_at` para auditoría. |
| 28 tablas operativas | Cada una con `branch_id` uuid NOT NULL + FK `restrictOnDelete` + índice `(company_nit, branch_id)`: orders, payment_receipts, cart_*, cash_register_*, deliveries, business_hours/_exceptions, printers, restaurant_menus, coupons (+ `scope` + `valid_in_branches`), coupon_redemptions, ingredients, ingredient_movements, recipes, menu_item_cost_history, suppliers, supplier_ingredients, purchase_orders/items/credit_notes/attachments, menu_scan_events, menu_scan_daily_rollup, offline_sync_events, chats, contacts. |

### Permisos (Feature seeder)

`branches.manage`, `branches.assign_users`, `branches.copy_menu`, `branches.view_all`, `metrics.view_all_branches` — asignados a owner por default.

### Aislamiento por capa

- **`BranchScope` global** (`App\Models\Scopes\BranchScope`): trait `BelongsToBranch` lo aplica a los 27 modelos operativos. Lee `active_branch_id` del request y agrega `WHERE branch_id = ?` automáticamente. Si no hay request HTTP (consola/seeders), no aplica.
- **Bypass explícito**: `Model::withoutBranchScope()` para reportes consolidados — el caller debe haber verificado `metrics.view_all_branches`.
- **Mutaciones**: `OrderController::store/refund`, `CashRegisterService::openSession` (sesión por sede, índice unique partial actualizado a `(company_nit, branch_id) WHERE status='open'`), `CouponController::store`, `IngredientController::store`, `SupplierController::store`, `PrinterController::store`, services (`InventoryService::recordMovement`, `PurchaseService::createDraft`, `PurchaseAttachmentService`) — todos persisten `branch_id` desde `active_branch_id` o lo heredan del modelo padre. Nunca del payload del cliente.
- **Coupons cross-sede**: `scope='branch'` (sede dueña) o `scope='company'` (con `valid_in_branches` array uuid o NULL = todas). `CouponService::validateCoupon` recibe `?branchId` y valida scope; `redeemCoupon` ancla la redención a la sede de la orden, no la del cupón.
- **WhatsApp inbound**: `Chat`/`Contact` se crean en la sede default de la empresa (no hay routing por sede entrante todavía). Reasignación manual desde la UI luego.

### Endpoints

| Método | Path | Permiso | Notas |
|--------|------|---------|-------|
| GET | `/api/v1/auth/branches-available` | (autenticado) | Lista sedes accesibles para la empresa activa. |
| POST | `/api/v1/auth/switch-branch` | (autenticado) | Reemite JWT con `active_branch_id`. Audita `auth.branch.switched`. |
| GET | `/api/v1/company/branches` | (membresía) | Lista sedes de la empresa (filtro `?include_archived=1`). |
| POST | `/api/v1/company/branches` | `branches.manage` create | Crea sede; gestiona `is_default` exclusivo. |
| PATCH | `/api/v1/company/branches/{branch}` | `branches.manage` update | Actualiza atributos. |
| DELETE | `/api/v1/company/branches/{branch}` | `branches.manage` delete | Soft archive (bloquea archivar la última activa). |
| GET | `/api/v1/company/branches/{branch}/users` | `branches.assign_users` read | Lista usuarios asignados. |
| POST | `/api/v1/company/branches/{branch}/users` | `branches.assign_users` update | Asigna usuario (debe ser miembro de la empresa). |
| DELETE | `/api/v1/company/branches/{branch}/users/{userId}` | `branches.assign_users` update | Revoca acceso. |
| POST | `/api/v1/company/branches/{branch}/menu/copy` | `branches.copy_menu` update | Duplica menú activo de sede origen como draft en destino. Audita `branch.menu_copied`. |

Las rutas operacionales (`/orders/*`, `/coupons/*`, `/deliveries/*`, `/hours/*`, `/inventory/*`, `/suppliers/*`, `/purchases/*`, `/cash-register/*`, `/reports/*`, `/metrics/*`) viven dentro de un grupo `branch.access`, garantizando aislamiento automático vía `BranchScope`.

### Auditoría

`AuditService::log` agrega automáticamente:
- `branch_id` (sede del recurso) si el modelo lo tiene seteado.
- `actor_active_branch_id` (sede que el usuario tenía activa al ejecutar) desde `request()->attributes`.

Esto permite reconstruir intentos cross-sede aunque ocurran entre sedes distintas.

### Onboarding

`CompanyEnrollmentController::store` crea automáticamente una `Branch` con `slug='principal'`, `is_default=true` y la fila correspondiente en `branch_users` para el creador. Sin esto, ninguna mutación operativa funcionaría (todas las tablas operativas tienen `branch_id` NOT NULL).

### Política contable delta

- **Caja por sede**: `cash_register_sessions` ahora tienen `UNIQUE INDEX (company_nit, branch_id) WHERE status='open'`. Cada sede opera caja independiente.
- **Refunds**: deben ejecutarse en la sede del receipt original. El `BranchScope` global filtra `Order::find($id)` automáticamente — un usuario en sede A no puede siquiera resolver una orden de sede B.
- **Reportes**: la regla DIAN sobre estabilidad histórica de KPIs sigue vigente; los reportes por defecto se filtran a la sede activa. Modo consolidado (`metrics.view_all_branches`) está protegido pero el endpoint `?branch=all` aún no se expone — usar `withoutBranchScope()` cuando se implemente.
- **Cupones cross-sede**: `coupons.scope='company'` con `valid_in_branches` permite múltiples sedes; la redención se inscribe en la sede de la orden (no la del cupón).

---

## Multi-bodega (#120) + Costeo multi-sede (#costeo-multibodega)

**Modelo actual (#costeo-multibodega).** La bodega es un **recurso de empresa** asignable a N sedes (pivot `branch_warehouses`). El insumo es **catálogo de empresa** (sin `branch_id`, único por `(company_nit, name)`). El stock y el **WAC viven por bodega** en `ingredient_stocks (quantity, current_cost)`. El costeo de recetas es **por sede + por bodega**: cada línea costea desde la bodega de la línea (`recipes.warehouse_id` NOT NULL). Ver `constants/ACCOUNTING_RULES.md` (sección "Inventario: WAC por bodega").

### Tablas

| Tabla | Notas |
|-------|-------|
| `warehouses` | uuid PK, FK `company_nit` (**sin `branch_id`** — company-scoped). `name`, `type`, `is_default` (informativo a nivel empresa), `archived_at`. Unique `(company_nit, slug)`. |
| `branch_warehouses` | **Pivot sede↔bodega** (fuente de verdad de la relación). uuid PK, `company_nit`, `branch_id`, `warehouse_id`, `is_default` (default operativa por sede). Unique `(branch_id, warehouse_id)` + índice parcial único `is_default` por sede. |
| `ingredients` | **Catálogo de empresa**: sin `branch_id`, sin `current_cost`. Unique `(company_nit, name)`. |
| `ingredient_stocks` | `(ingredient_id, warehouse_id)` UNIQUE. `quantity decimal(12,3)`, `min_stock`, **`current_cost decimal(12,2)` = WAC por bodega**. |
| `ingredient_movements` | append-only. FK `warehouse_id`. Transferencia = dos filas hermanas (`-`/`+`) con `dest_warehouse_id` cruzado y mismo `reference`. El WAC se recomputa leyéndolas, nunca mutándolas. |
| `recipes` | `warehouse_id` **NOT NULL** (fuente de costo de la línea). Unique parcial `(company_nit, branch_id, menu_item_id, ingredient_id)`. |
| `menu_item_cost_history` | Unique `(company_nit, branch_id, menu_item_id, snapshot_date)` — un snapshot por sede/día. |
| `warehouse_stock_snapshots` | valoriza con `ingredient_stocks.current_cost` por bodega (`inventory:snapshot-daily`). |

### Permisos

- `warehouses.manage` (owner+admin) — CRUD de bodegas.
- `warehouses.assign_branches` (owner+admin, acción `update`) — asignar/desasignar bodegas a sedes y marcar la default por sede.

### Aislamiento

- Insumos y bodegas son **config de empresa** (sin `BranchScope`). El costeo y las recetas **sí** son por sede (filtran explícito por `branch_id`, incluso en crons sin `active_branch_id` — `RecipeCostService::compute($companyNit, $branchId, $itemId)`).
- Mutaciones de inventario validan que la bodega esté **asignada a la sede** (pivot) — `assertWarehouseAssignedToBranch`. Sede sin bodega → bloqueo duro `BRANCH_HAS_NO_WAREHOUSE` (422).
- Food cost por sede: el snapshot branch-keyed cubre todas las cartas (antes "ganaba la última sede" con cartas clonadas). El clonado de carta regenera `menu_item_id` (D4).

### Endpoints

| Método | Path | Permiso |
|--------|------|---------|
| GET | `/api/v1/company/warehouses` | `warehouses.manage` read |
| POST | `/api/v1/company/warehouses` | `warehouses.manage` create |
| PATCH | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage` update |
| DELETE | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage` delete |
| POST | `/api/v1/inventory/transfers` | `inventory.update` |

### Política operativa

- **Archivar bodega**: bloqueado si tiene `on_hand_qty > 0` en cualquier ingrediente. Soft archive (`archived_at`) — los movimientos históricos preservan FK.
- **Bodega default por sede**: al crear sede, `CompanyEnrollmentController` crea automáticamente una bodega `'Principal'` con `is_default=true`. Sin esto las primeras compras no tendrían destino.
- **Transferencias**: atomic + lock. Si origen no tiene stock suficiente, 422 `INSUFFICIENT_STOCK` (mensaje "Bodega origen no tiene suficiente {ingrediente}"). No se permiten transferencias entre sedes (issue futura).

### Auditoría

- `inventory.transfer` con metadata `{from_warehouse_id, to_warehouse_id, ingredient_id, quantity, unit_cost}`.
- `warehouse.created/updated/archived` para cambios de catálogo.

---

## Rutas — Inventario completo

180 rutas totales. Distribución:

| Grupo | Rutas |
|-------|-------|
| `web` (Inertia + auth tradicional) | 53 |
| `api/v1/auth` | 3 |
| `api/v1/billing` | 7 |
| `api/v1/cart` | 3 |
| `api/v1/chats` | 7 |
| `api/v1/companies` | 4 |
| `api/v1/company` | 2 |
| `api/v1/coupons` | 8 |
| `api/v1/deliveries` | 9 |
| `api/v1/enrollment` | 3 |
| `api/v1/exports` | 6 |
| `api/v1/features` | 1 |
| `api/v1/hours` | 7 |
| `api/v1/invitations` | 1 |
| `api/v1/me` | 2 |
| `api/v1/menus` | 18 |
| `api/v1/metrics` | 10 |
| `api/v1/orders` | 6 |
| `api/v1/public` | 1 |
| `api/v1/reports` | 3 |
| `api/v1/roles` | 4 |
| `api/v1/users` | 5 |
| `api/v1/webhooks` | 2 |
| `api/v1/whatsapp` | 8 |
| `api/v1/csp-report` | 1 |
| `api/external/*` | 4 |

### Rutas web (Inertia)

| Método | URL | Nombre | Permiso (gate web) | Página |
|--------|-----|--------|---------------------|--------|
| GET | `/` | `home` | público | `welcome.tsx` |
| GET | `/login` | `login` | público | `auth/login.tsx` |
| POST | `/login` | `login.post` | `throttle:5,1` (deshabilitado, redirige a /) | — |
| GET | `/register` | `register` | público | `auth/register.tsx` |
| POST | `/register` | `register.post` | `throttle:5,1` (deshabilitado) | — |
| GET | `/forgot-password` | `password.request` | público | `auth/forgot-password.tsx` |
| POST | `/forgot-password` | `password.email` | `throttle:6,1` | — |
| GET | `/reset-password/{token}` | `password.reset` | público | `auth/reset-password.tsx` |
| POST | `/reset-password` | `password.store` | público | — |
| GET | `/verify-email` | `verification.notice` | `auth` | `auth/verify-email.tsx` |
| GET | `/verify-email/{id}/{hash}` | `verification.verify` | `auth, signed, throttle:6,1` | redirige |
| POST | `/email/verification-notification` | `verification.send` | `auth, throttle:6,1` | — |
| GET | `/confirm-password` | `password.confirm` | `auth` | `auth/confirm-password.tsx` |
| POST | `/confirm-password` | — | `auth` | — |
| PUT | `/password` | `password.update` | `auth` | — |
| GET | `/auth/google` | `auth.google` | `throttle:oauth` (10/min) | redirect a Google |
| GET | `/auth/google/callback` | `auth.google.callback` | `throttle:oauth` | callback OAuth |
| GET | `/auth/company-selector` | `auth.company-selector` | autenticado | `auth/company-selector.tsx` |
| POST | `/logout` | `logout` | `auth` | — |
| GET | `/dashboard` | `dashboard` | JWT + `reports.read` (gate por servicio) | `dashboard.tsx` |
| GET | `/me` | `me` | JWT | `me/index.tsx` |
| GET | `/menu` | `menu` | `menu.read` | `menu/index.tsx` |
| GET | `/menu/{id}` | `menu.show` | `menu.read` | `menu/show.tsx` |
| GET | `/menus` | `public.menu.alias` | **Pública** (sin auth) | `menu/public.tsx` — el cliente resuelve el NIT desde `localStorage['menu_last_nit']` |
| GET | `/menus/{nit}` | `public.menu` | **Pública** (sin auth) | `menu/public.tsx` (issue #95: destino del QR fijo). El cliente guarda el NIT en localStorage y reemplaza la URL a `/menus/` |
| GET | `/coupons` | `coupons` | `coupons.read` | `coupons/index.tsx` |
| GET | `/coupons/{id}` | `coupons.show` | `coupons.read` | `coupons/show.tsx` |
| GET | `/orders/cashier` | `orders.cashier` | `orders.read` (gate web) | `caja/index.tsx` |
| GET | `/caja` | (alias) | redirige 302 a `/orders/cashier` preservando `?token=` | — |
| GET | `/orders/board` | `orders.board` | JWT (kanban) | `orders/board.tsx` |
| GET | `/orders/tables` | `orders.tables` | `orders.read` (gate web) | `orders/tables/index.tsx` |
| GET | `/orders/deliveries` | `orders.deliveries` | `deliveries.read` (gate web) | `deliveries/index.tsx` |
| GET | `/deliveries` | (alias) | redirige 302 a `/orders/deliveries` | — |
| GET | `/deliveries/metrics` | `deliveries.metrics` | `deliveries.read` | `deliveries/metrics.tsx` |
| GET | `/identities/users` | `identities.users` | `users.read` (gate web) | `users/Users.tsx` |
| GET | `/identities/roles` | `identities.roles` | `roles.read` (gate web) | `roles/Roles.tsx` |
| GET | `/roles` | (alias) | redirige 302 a `/identities/roles` | — |
| GET | `/hours` | `hours` | `hours.read` | `hours/index.tsx` |
| GET | `/chats` | `chats` | `chats.read` | `chats.tsx` |
| GET | `/clients` | `clients` | `clients.read` (gate web, #123) | `clients/index.tsx` |
| GET | `/clients/{contact}` | `clients.show` | `clients.read` (gate web) | `clients/show.tsx` (param `{contact}` = `contacts.id`, refactor #235 — phone ya no es único) |
| GET | `/cart/{jwt}` | `cart` | sin auth (CartJwt) | `cart.tsx` |
| GET | `/company/preferences` | `company.preferences` | `company.update` (gate web) | `company/preferences.tsx` |
| GET | `/company/settings` | `company.settings` | `company.update` (gate web) | `company/settings.tsx`. Inertia props: `activeCompany`, `availableBanks`, `acceptedContract` (snapshot del contrato aceptado por el owner, #170). |
| GET | `/company/whatsapp` | `company.whatsapp` | `whatsapp.read` (gate web) | `company/whatsapp.tsx` |
| GET | `/company/metrics` | `company.metrics` | `reports.read` (gate web) | `metrics/index.tsx` |
| GET | `/company/reports` | `company.reports` | `reports.read` (gate web) | `reports/index.tsx` |
| GET | `/billing` | `billing` | `billing.read` | `billing/index.tsx` |
| GET | `/enrollment/user` | `enrollment.user` | JWT + `pending_enrollment` | `enrollment/user.tsx` |
| GET | `/enrollment/company` | `enrollment.company` | JWT + `pending_company` | `enrollment/company.tsx` |
| GET | `/settings/profile` | `profile.edit` | `auth` | `settings/profile.tsx` |
| PATCH | `/settings/profile` | `profile.update` | `auth` | — |
| DELETE | `/settings/profile` | `profile.destroy` | `auth, password.confirm` | — |
| GET | `/settings/password` | `password.edit` | `auth` | `settings/password.tsx` |
| GET | `/settings/appearance` | `appearance.edit` | `auth` | `settings/appearance.tsx` |
| GET | `/settings` | (redirect) | `auth` | redirige a `/settings/profile` |

**Aliases de back-compat (302):** `/caja → /orders/cashier`, `/deliveries → /orders/deliveries`, `/roles → /identities/roles`. Conservan el query string (`?token=` para deep links).

### API v1 — Endpoints por dominio

#### Autenticación (`/api/v1/auth/*`)

| Método | URL | Permiso | Controlador@método |
|--------|-----|---------|---------------------|
| POST | `auth/select-company` | JWT | `AuthController@selectCompany` |
| POST | `auth/switch-company` | JWT | `AuthController@switchCompany` |
| POST | `auth/logout` | JWT | `AuthController@logout` |

`selectCompany()` recibe `{nit}`, valida membresía, reissue JWT con `active_company_nit`. `switchCompany()` invalida la sesión actual (blacklist) y emite nueva.

#### Yo (`/api/v1/me`)

| Método | URL | Permiso | Controlador@método |
|--------|-----|---------|---------------------|
| GET | `me` | JWT | `MeController@show` |
| DELETE | `me` | JWT | `MeController@destroy` |

#### Empresa (`/api/v1/companies/*`, `/api/v1/company`)

| Método | URL | Permiso | Controlador@método |
|--------|-----|---------|---------------------|
| GET | `companies/active` | JWT + company.access | `ActiveCompanyController@show` |
| GET | `company` | `company.update,read` | `CompanyController@show` |
| PUT | `company` | `company.update,update` | `CompanyController@update` |
| GET | `companies/settings` | `company.update,read` | `CompanySettingsController@index` |
| PATCH | `companies/settings` | `company.update,update` | `CompanySettingsController@update` |
| GET | `companies/settings/{key}` | `company.update,read` | `CompanySettingsController@show` |

`PUT /company` permite actualizar logo (`mimes:png,jpg,jpeg,webp,svg`, `max:5120` KB) y QR (`mimes:png,jpg,jpeg`, `max:5120` KB). Reemite el JWT cuando cambia `commercial_name` para refrescar el sidebar.

#### Enrollment (`/api/v1/enrollment/*`)

| Método | URL | Permiso | Controlador@método |
|--------|-----|---------|---------------------|
| POST | `enrollment/user` | JWT | `UserEnrollmentController@store` |
| POST | `enrollment/company` | JWT | `CompanyEnrollmentController@store` |
| POST | `enrollment/invited` | JWT | `InvitedEnrollmentController@store` |

Todos validan el `enrollment_step` del JWT. `enrollment/company` valida NIT único global y crea los 3 roles del sistema vía `permission_templates`.

#### Identidades — usuarios y roles (`/api/v1/users/*`, `/api/v1/roles/*`, `/api/v1/invitations`, `/api/v1/features`)

| Método | URL | Permiso | Controlador@método |
|--------|-----|---------|---------------------|
| GET | `users` | `users.read,read` | `UserRoleController@index` |
| PUT | `users/{id}/role` | `users.update,update` | `UserRoleController@update` |
| PUT | `users/{id}/permissions` | `users.update,update` | `UserRoleController@updatePermissions` |
| PATCH | `users/{id}/status` | `users.update,update` | `UserRoleController@updateStatus` |
| DELETE | `users/{id}` | `users.update,delete` | `UserRoleController@destroy` |
| GET | `roles` | `roles.read,read` | `RoleController@index` (incluye `users_count` por rol vía `withCount('users')`) |
| POST | `roles` | `roles.create,create` | `RoleController@store` |
| PUT | `roles/{id}` | `roles.update,update` | `RoleController@update` |
| DELETE | `roles/{id}` | `roles.delete,delete` | `RoleController@destroy` |
| POST | `invitations` | `users.update,create` | `InvitationController@store` |
| GET | `features` | `roles.read,read` | `FeatureController@index` |

`updatePermissions()` valida que el actor no otorgue permisos que él mismo no tiene. `updateStatus()` con `status=inactive` invalida el JWT activo del usuario afectado (vía `JwtService::invalidateUserActiveSession`). `destroy()` bloquea si dejaría 0 miembros con un rol del sistema.

#### Menú (`/api/v1/menus/*`)

18 endpoints. Todos en `MenuController` (excepto `showPublic`).

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `menus` | `menu.read,read` |
| POST | `menus` | `menu.create,create` |
| GET | `menus/{id}` | `menu.read,read` |
| PUT | `menus/{id}` | `menu.update,update` |
| DELETE | `menus/{id}` | `menu.delete,delete` |
| POST | `menus/{id}/duplicate` | `menu.create,create` |
| PATCH | `menus/{id}/activate` | `menu.update,update` |
| PATCH | `menus/{id}/schedule` | `menu.update,update` |
| POST | `menus/sync-schedule` | `menu.update,update` |
| POST | `menus/{id}/categories` | `menu.create,create` |
| PUT | `menus/{id}/categories/{catId}` | `menu.update,update` |
| DELETE | `menus/{id}/categories/{catId}` | `menu.delete,delete` |
| POST | `menus/{id}/categories/{catId}/items` | `menu.create,create` |
| PUT | `menus/{id}/categories/{catId}/items/{itemId}` | `menu.update,update` |
| DELETE | `menus/{id}/categories/{catId}/items/{itemId}` | `menu.delete,delete` |
| POST | `menus/{id}/items/{itemId}/image` | `menu.update,update` |
| PATCH | `menus/{id}/categories/{catId}/items/{itemId}/availability` | `menu.update,update` |
| GET | `public/menu/{companyNit}` | **Pública sin auth** — valida horario + caja activa + menú activo. Response incluye bloque `restaurant: { commercial_name, logo_url, primary_color }` en TODAS las variantes (200/423/404) para que la página pública pueda renderizar branding aún cuando el restaurante esté cerrado o sin menú |
| POST | `public/menu/{nit}/scan` | **Pública sin auth** — telemetría del QR (issue #95). Rate-limited a 30/min/IP/nit, dedup por `session_id` (60s) y bot-detection vía heurísticas (UA blocklist, Referer, honeypot `_h`) |

Imágenes de platos: `mimes:jpg,jpeg,png`, `max:5120` KB (5 MB). Storage en `menu.image_disk`. URLs temporales 60 min.

##### Telemetría del QR público (`menu_scan_events`, issue #95)

Tabla **particionada por `scanned_at`** (RANGE mensual) en PostgreSQL. PK compuesta `(id, scanned_at)`. Tipos compactos: `uuid`, `bytea(32)` para IP hash, `varchar(16)` para mesa. Índices parciales `WHERE is_bot=false` (reportes solo recorren tráfico humano) + BRIN sobre `scanned_at` (KB en lugar de MB).

Diseño operativo:
- **Partición DEFAULT** como red de seguridad (clock-skew, cron caído).
- **`php artisan partitions:ensure`** (cron horario): pre-crea particiones del mes anterior, actual, siguiente y +2; drena la default re-routing filas a sus particiones mensuales (transacción por mes con buffer temporal — necesario porque Postgres rechaza `CREATE TABLE PARTITION OF` si la default contiene filas del rango). Soporta clock-skew arbitrario.
- **Sin trigger BEFORE INSERT** que cree particiones inline: Postgres genera auto-deadlock (CREATE TABLE PARTITION OF requiere ACCESS EXCLUSIVE sobre el parent que el INSERT ya tiene en ROW EXCLUSIVE).
- **`AggregateMenuScansJob`** (diario 03:15): UPSERT a `menu_scan_daily_rollup` con `total_scans`, `unique_sessions` agrupado por `(company_nit, scan_date, table_number)` filtrando `is_bot=false`. Los reportes leen de aquí, no de la tabla cruda: `GET /api/v1/metrics/menu-scans` (#294, `MetricsController::menuScans` → `MetricsService::getMenuScans`) une el rollup (historia) con el día en curso agregado en vivo desde `menu_scan_events` — sin eso `period=today` daría siempre cero porque el job corre para D-1. Permiso `reports.read`, consolidación `branch.consolidate`. UI: `MenuScansPanel` en `/company/metrics`.
- **`DropOldMenuScanPartitionsJob`** (diario 03:30): DROP atómico de particiones cuyo límite superior es ≤ hace 90 días. El rollup permanece (filas pequeñas, retención indefinida).
- **`BotDetectionService`** marca `is_bot=true` (no descarta) en base a: UA en blocklist, Referer ausente o no apunta a `/menus/{nit}`, honeypot `_h` con valor. El flag se persiste y los índices parciales lo filtran de reportes — bots quedan auditables.

Fuente del SQL DDL: `database/sql/menu_scan_partition_function.sql` (mantenido por compatibilidad histórica; hoy intencionalmente vacío). Las primitivas viven en `App\Services\Analytics\MenuScanPartitionService`.

#### Pedidos (`/api/v1/orders/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `orders` | `orders.read,read` |
| GET | `orders/tables` | `orders.read,read` |
| POST | `orders` | `orders.create,create` |
| GET | `orders/{id}` | `orders.read,read` |
| PATCH | `orders/{id}/status` | `orders.update,update` |
| POST | `orders/{id}/items` | `orders.update,update` |
| POST | `orders/{id}/close-with-payment` | `orders.update,update` |
| POST | `orders/{id}/cancel` | `orders.update,update` |
| POST | `orders/{id}/refund` | `orders.update,update` |
| GET | `orders/{id}/receipt-escpos` | `orders.read,read` |
| GET | `orders/{orderId}/available-deliverers` | `deliveries.read,read` |
| POST | `orders/{orderId}/assign-courier` | `deliveries.create,create` |
| GET | `deliveries/mine` | `deliveries.read,read` (#119) |
| GET | `deliveries/available` | `deliveries.self_assign,read` (#119) |
| POST | `deliveries/orders/{orderId}/self-assign` | `deliveries.self_assign,read` + throttle 30/min (#119) |
| PUT | `deliveries/{id}/revert` | `deliveries.update,update` + throttle 30/min (#119) |
| PUT | `deliveries/{id}/reject` | `deliveries.update,update` + throttle 30/min (#119) |

**Endpoints del courier mobile-first (#119)**. Toda mutación del courier vive bajo `branch.access` (sede activa obligatoria) y throttle 30/min para evitar toggles abusivos.
- `revert` y `reject` están bloqueados con 409 (`code=DELIVERY_HAS_RECEIPT`) si la orden ya tiene `payment_receipts` — inmutabilidad DIAN, refund requiere intervención de admin.
- `selfAssign` usa `Order::lockForUpdate` para resolver carrera entre dos couriers concurrentes (segundo recibe `code=ORDER_ALREADY_TAKEN` 409); el partial unique index `deliveries_active_order_unique` es el cinturón a nivel BD.
- Las 5 acciones loguean en `delivery_status_logs` (append-only, indexado por `delivery_id`) además de `audit_logs`.

**`GET /api/v1/orders/{id}/receipt-escpos`** — devuelve el binario ESC/POS del recibo de venta (`Content-Type: application/octet-stream`). Solo lectura: NO crea ni muta `payment_receipts`. 409 si la orden no tiene comprobantes registrados. Query params: `?width=58|80` (override del setting), `?copy=true` (marca "*** COPIA ***"). Pipeline: `ReceiptPrintController` → `ReceiptPrintingService` (lee `Order` + `receipts` + `company_settings.printing.*`) → `EscposBuilder` (CP850, alineación, doble alto, corte). Settings asociados: `printing.receipt_width`, `printing.header_lines`, `printing.footer_message`, `printing.show_qr_menu`, `printing.copies`.

`OrderController@index` opera en dos modos:
- **Kanban** (default): filtrado por hoy + estados operativos. Polling 5s desde frontend.
- **Cursor** (`?paginate=cursor`): pagination histórica con `cursor`, `per_page` (default 50, máx `mobile.api_max_page_size`), `status`, `date_from`, `date_to`.

**Estados de orden — fuente única de verdad: `config/orders.php`** (compartido al frontend vía `HandleInertiaRequests::share()`):

| Status | Categoría | Operativo | Revenue |
|---|---|---|---|
| `pending` | operational | ✓ | — |
| `in_kitchen` | operational | ✓ | — |
| `ready` | operational | ✓ | — |
| `in_transit` | operational | ✓ | — |
| `completed` | terminal_success | — | ✓ |
| `failed` | terminal_failure | — | — |
| `cancelled` | terminal_failure | — | — |
| `refunded` | terminal_failure | — | — |
| `abandoned` | terminal_failure | — | — |

Migración `2026_05_07_192524_migrate_order_statuses_to_canonical_set` renombró `successful → completed` e `in_delivery → in_transit`. `config/metrics.php` (deprecado en parte) ahora apunta a la lista canónica de `config/orders.*`.

### Política contable (orders + payment_receipts)

- **Invariante de total**: `orders.total = SUM(items.price * items.quantity)`. Se aplica en `OrderController::store` y `appendItems` vía `computeItemsTotal()`. Cualquier divergencia indica dato corrupto.
- **Costo por plato (food cost)**: cada `menu_items[]` admite un campo opcional `cost` (decimal, COP) en el JSON `restaurant_menus.structure`. Validado por `Store/UpdateItemRequest` con `nullable|numeric|min:0|max:9999999.99`. **Sensible**: `MenuController::showPublic()` strip-ea explícitamente `cost` antes de retornar el menú público (riesgo de leak competitivo). El `?branch_id=` del query se valida contra la empresa de la URL (+ no archivada) con fallback a la sede default — sin esa validación un branch_id ajeno filtraba branding/imagenes/delivery_fee de sedes de otras empresas vía `buildRestaurantPayload` (fix v1.30.3; mismo patrón que ya usaba `recordScan`). Al crear/actualizar una orden, `OrderController::buildOrderLines` y `appendItems` snapshotean `cost` en cada línea de `orders.items[].cost` (inmutable post-creación). `orders.cost` se calcula vía `computeOrderCost()` sumando `qty * cost` y omitiendo líneas con `cost=null` (no se asume 0 para no falsear el food cost). Endpoint nuevo `GET /api/v1/metrics/dishes/margin?period=...` (`MetricsController::dishMargin` → `MetricsService::getDishMargin`) agrega por `item_id` y devuelve `{units_sold, avg_price, avg_cost, gross_revenue, gross_cost, margin_amount, margin_pct}`; excluye platos sin costo registrado. Permiso `reports.read`. Issue #107.
- **`orders.discount_amount`**: persiste el descuento aplicado al pedido del bot/carrito; `orders.total` ya viene **neto** (con descuento restado). Los reportes leen `total` directamente — **no se debe restar `discount_amount` otra vez**.
- **IVA / impuestos**: parametrizable por empresa. `companies.tax_regime` (preset) + `default_tax_rate` + `default_tax_label` + `tax_included_in_price`. Cada orden persiste snapshot inmutable: `orders.subtotal`, `tax_amount`, `tax_rate`, `tax_regime`, `tax_included_in_price`. Cada línea de `orders.items[]` también lleva su breakdown. Override por ítem: `MenuItem.tax_rate` y `MenuItem.tax_label` (null = hereda default de empresa) — útil para menús mixtos (bebida alcohólica IVA 19% mientras la comida lleva INC 8%). Cálculo en `App\Services\TaxCalculator` (espejo en `lib/tax.ts` para preview UX). Presets: simple, inc_8, iva_19, iva_5, iva_exento, custom.
- **Propina (`orders.tip_amount`)**: voluntaria. **NO** suma a `total`, **NO** suma a `subtotal`, **NO** genera impuesto, **NO** entra al `payment_receipts.amount` (revenue). Se registra al cerrar la cuenta (closeWithPayment) y se reporta como línea informativa separada. El "Total a cobrar al cliente" en caja = `total + tip_amount`. Si pago en efectivo, la devuelta se calcula contra ese total expandido.
- **Refunds parciales**: `OrderController::refund` acepta `amount` opcional. Si se omite, devuelve el remanente completo. Se permiten múltiples refunds por orden hasta agotar `order.total`. Cada parcial crea un `PaymentReceipt` con `payment_method='refund'` y `amount` negativo; `payment_data` incluye `is_partial`, `remaining_refundable`. Status: la orden pasa a `refunded` SOLO cuando el remanente llega a 0; un parcial deja la orden en `completed` para que siga contando como gross — el `SUM(amount)` signed garantiza el net correcto. Validación bloquea solicitudes que excedan el remanente (revalidación dentro del lock; si entre pre-flight y lock entra otro refund concurrente, se rechaza con error en lugar de silenciosamente refundar menos).
- **Refund de item (mesas QR)**: `TableCashierService::refundItem` sigue el mismo principio — la venta queda intacta (`orders.total` NO se recalcula, el item conserva su status) y la devolución vive solo en el receipt negativo. El item se marca con `order_items.refunded_at` + `refund_receipt_id` (bloquea doble refund; antes cada intento con `client_uuid` nuevo creaba otro receipt). Si los refunds acumulados cubren el total de una orden `completed`, pasa a `refunded`. Backend v1.24.4 — antes el item se cancelaba (`cancellation_reason='refunded'`, hoy legacy) y el total se reducía, produciendo doble descuento en reportes.
- **Cupones**: `OrderController::store` acepta `coupon_code` opcional. Si es válido (`CouponService::validateCoupon`), aplica descuento sobre el TOTAL bruto y redistribuye proporcionalmente entre `subtotal` y `tax_amount` para mantener el invariante `total = subtotal + tax_amount` post-descuento. **Política contable DIAN-friendly: el descuento reduce la base gravable** (no es ajuste post-IVA). `discount_amount` y `coupon_code` se persisten; `CouponService::redeemCoupon` registra la redención en la misma transacción. Frontend: `pages/caja/index.tsx` tiene input + Aplicar usando `useCouponValidation`. El `payment_receipts.amount` cobrado al cerrar = `total` post-descuento (lo realmente recibido).
- **Snapshot de tasa default**: `orders.snapshot_default_tax_rate` (separado de `tax_rate` effective ponderado) preserva la tasa default de la empresa al momento de crear la orden. `appendItems` lo usa como fallback para nuevos ítems sin override propio, evitando contaminación con el promedio ponderado de los ítems existentes.
- **Timezone CO**: los timestamps se persisten en **wall-clock del `APP_TIMEZONE`** (America/Bogota) — Laravel guarda `now()` en el tz de la app, NO en UTC. Regla para filtros de fecha: construir los límites en `config('orders.timezone')` y convertirlos con `setTimezone(config('app.timezone'))` antes de comparar; en SQL, `EXTRACT`/`DATE` van directos sobre la columna (sin `AT TIME ZONE`, que re-interpreta contra la sesión PG en UTC). Auditoría 2026-07-01 (backend v1.24.4): las conversiones `->utc()` en kanban, cierre de caja, historial de sesiones y heatmaps corrían todas las ventanas "del día" +5h; corregido en `OrderController::index`, `CashDrawerController`, `CashRegisterController::index`, `MetricsController` y `MetricsService`.
- **Cierre de Caja**: endpoint `GET /api/v1/reports/cash-drawer` retorna ingresos/devoluciones/propinas por método de pago en un rango (default: hoy en TZ Bogotá). Calcula `cash_drawer_expected = opening_amount + cash_gross + cash_tips + cash_incomes - cash_refunds - cash_expenses` para conciliación física (incluye saldo inicial de `cash_register_sessions`, entradas en efectivo de `cash_register_incomes` y egresos en efectivo de `cash_register_expenses`). El summary expone `cash_incomes_total` + `cash_incomes_by_category`; el PDF (`cash-drawer/pdf`) desglosa entradas por categoría. UI en `Mi Empresa › Informes` (`CashDrawerCard`) y en `/orders/deliveries` (gated `reports.read`). Filtra por `payment_receipts.paid_at` (no `orders.ordered_at`) para reflejar la fecha real del movimiento de dinero. Multi-sede: los agregados Eloquent filtran por `BranchScope` y los `DB::table()` (aperturas, egresos, ingresos) replican el filtro por `active_branch_id` a mano — sin eso el arqueo de una sede mezclaba el efectivo de todas (fix backend v1.24.4). Propinas: se suman por receipt desde `payment_data.tip_amount` (NO `orders.tip_amount` vía JOIN, que las multiplicaba por cada receipt en pagos divididos y las duplicaba por método); los refunds de efectivo del drawer se resuelven con `whereExists` sobre el receipt original cash — antes `by_method.cash.refunds` era siempre 0 (los refunds viven bajo `payment_method='refund'`) y el drawer esperado quedaba inflado (fix backend v1.30.3).
- **Entradas de efectivo (no-venta)**: tabla `cash_register_incomes` (espejo append-only de `cash_register_expenses`) para inyecciones de dinero a la caja que no vienen de un cobro: aportes de socio, préstamos, ajustes. `POST /api/v1/cash-register/incomes` (`orders.update`), listado `GET /api/v1/cash-register/sessions/{id}/incomes` (`reports.read`). Categorías en `config('cash_register.income_categories')`. Suman al efectivo esperado del arqueo (`CashRegisterService::computeExpectedCash`). Sync offline vía `cash.income`. Audit: `cash.income.recorded`. El historial de turnos (`/reports/cash-register/sessions`) acepta `?date_from&date_to&detailed=1` para el informe de cierre por turno.
- **Sesiones de caja (turnos)**: tabla `cash_register_sessions` con UNIQUE parcial `(company_nit) WHERE status='open'` — una sola sesión abierta por empresa. Cualquier usuario con permiso ve y opera la misma sesión. `payment_receipts.cash_session_id` asocia cada cobro/refund a la sesión activa al crearlo. `OrderController` (store, appendItems, closeWithPayment, refund) y `MenuController::showPublic` requieren sesión abierta vía `CashRegisterService::requireActiveSession` / `activeSession`. Si la caja está cerrada el menú público responde `423` con `restaurant_status.is_open=false, reason='cash_register_closed'` — clientes (bot/cart) no pueden ordenar. Endpoints: `GET /api/v1/cash-register/current` (devuelve `{session, context: {menu_active, in_business_hours, should_alert}}`, poll 10s en frontend), `POST /open`, `POST /close`. Sesiones cerradas son inmutables (corrección = nueva sesión con notas). Audit log: `cash_register.opened`, `cash_register.closed`. Historial paginado en `/api/v1/reports/cash-register/sessions`. Frontend: `<CashRegisterAlertBanner>` en `AppSidebarLayout` muestra advertencia global persistente cuando `should_alert=true` (caja cerrada + menú activo + horario hábil).
- **`payment_receipts.amount`**: **SIGNED**. Cobros (`cash|card|transfer`) suman positivo; devoluciones (`refund`) suman negativo. `Net = SUM(amount) GROUP BY payment_method`.
- **Pedido público sin mesa (QR de sede)**: `Public\BranchOrderController::store` (`POST /api/v1/public/branch/{menu_qr_token}/orders`, throttle `branch-order-public` 5/min IP+token, FormRequest `Public\StoreBranchOrderRequest` con trait `SanitizesInput`). Crea `Order` `order_type=pickup|delivery`, `status=pending_approval`, items `order_items` con precio del menú activo de la sede; el envío entra como línea sintética `menu_item_id='delivery_fee'` ("Domicilio", tax 0) con el precio de `branch_settings.delivery_fee` (editable vía `BranchController::updateSettings`). Totales via `OrderTotalCalculator`. Contact upsert por phone (CRM). Aprobación staff: `OrderController::approve` (`POST /api/v1/orders/{id}/approve`, `orders.update`, solo órdenes sin `table_session_id`) → orden a `pending`, items a `approved`, audit `order.approved` + SMS dispatcher. Los pedidos aparecen en `TableSessionController::pendingApprovals` (path 2 extendido a `pickup|delivery` con `client_name/client_phone/delivery_address/total`). Push a staff reutiliza `OrderItemSubmittedForApproval`.
- **Atomicidad**: `closeWithPayment`, `refund`, `cancel`, `appendItems`, `updateStatus` están envueltos en `DB::transaction` con `Order::lockForUpdate()` para prevenir doble-cierre o inconsistencias entre `orders.status` y `payment_receipts`.
- **Audit log**: `AuditService::log` se invoca en cada mutación financiera (`order.closed_with_payment`, `order.refunded`, `order.cancelled`, `order.items_appended`, `order.status_changed`) con metadatos accionables.
- **Reportes**: `PdfExportService::exportOrders` agrega `gross / refunds / net` por método con SQL (`SUM(amount)` desde `payment_receipts`); `OrderReportController::buildSummary` retorna `total_revenue` (gross), `total_refunded` (positivo) y `net_revenue`.

Cálculo de `total`: siempre server-side desde el menú activo (línea 144 de `OrderController::store`). El frontend nunca puede inyectar precios.

`OrderController@tables` devuelve las mesas con cuenta abierta: filtra `order_type='table'` y `status` en `pending|in_kitchen|ready`. Consumido por `/orders/tables`.

`OrderController@appendItems` agrega ítems a una orden de mesa abierta (rechaza estados terminales). Lee precios del menú activo en DB y suma al `total` existente. Issue #89.

**Notificaciones SMS al cliente (#275)** — Al mover una orden con `client_phone` a un estado relevante (`in_kitchen`, `ready`, `in_transit`, `completed`; lista en `config/order_notifications.php`, nunca hardcodeada) se envía **un** SMS vía Amazon SNS con nombre comercial + código corto de orden + estado.

- **N-instance safe**: `App\Services\Sms\OrderStatusSmsDispatcher::dispatch()` hace `insertOrIgnore` en `order_sms_notifications` (UNIQUE `(order_id, to_status)`). El segundo intento (otra EC2, doble click, reintento de cola) viola el unique y no reenvía — el dedup es atómico y **no depende del lock**. Debe invocarse **fuera** de la `DB::transaction` que muta `orders.status` (envuelto en `try/catch` internamente) para que ningún fallo del SMS (registro o encolado) pueda abortar la txn y revertir el cambio de estado/cobro. El publish lo hace `SendOrderStatusSmsJob::dispatch(...)` (cola `notifications`, conexión `database`, `ShouldBeUnique`, guard por estado del registro). La cola se fija con `$this->onQueue('notifications')` en el constructor del job — NO con una propiedad `public $queue` (choca con el trait `Queueable` de Laravel 12 → FatalError que reventaba el cambio de status con 500).
- **Puntos de disparo (todos los caminos que mutan `orders.status` a un estado notificable)**: `OrderController::updateStatus` (drag manual kanban), `OrderController::closeWithPayment` (cobro en caja); `KdsTicketService::maybePromoteOrderStatus` (promoción automática `in_kitchen`/`ready` cuando el KDS marca ítems — el camino dominante para esos dos estados); `TableCashierService::payPartial`/`payAll` vía `maybeCloseSession` (cierre de sesión QR de mesa, cobro dividido o completo — puede cerrar varias órdenes en `payAll`); `SyncController::applyOrderClose` y `OrderSyncController::syncSingle` (cobro sincronizado desde caja offline). Bug histórico (corregido en backend v1.21.6): la lógica nació solo cableada en `OrderController`, dejando sin SMS los cuatro caminos de KDS/QR/offline — que en producción son el volumen real de cierres de orden.
- **Teléfono**: `App\Support\PhoneNumber::toE164` normaliza a E.164 con default `+57`; inválido/ausente → no se envía (no rompe el flujo). El cuerpo se transifere a ASCII (GSM-7) en `SnsSmsSender` para mantener 1 segmento (costo).
- **Identidad de origen**: envío internacional best-effort (CO no soporta Sender ID); el nombre comercial va en el cuerpo. `SMSType=Transactional`. Credenciales por IAM instance profile (`config/services.php` → `sns`); permiso `sns:Publish` en el app-role (IaC `02-security.yaml`). Master switch `SNS_SMS_ENABLED` (off en local/qa → registro `skipped`).
- **Código corto de orden**: `Order::shortCode()` = dos primeros segmentos del UUID en mayúscula (ej. `019E7DA6-3C13`). Referencia visual, no clave única (UUIDv7 → segmentos = timestamp). Espejo frontend en `lib/order-code.ts`.
- **Visibilidad por cliente (Fase 2)**: `App\Services\Sms\SmsChatLogger` persiste cada SMS (enviado o fallido) como `ChatMessage` (`sender='bot'`) en un `Chat` `source='sms'` (clave canónica = `contact.phone`); aparece en `/clients/{id}` (`CrmService::profile`) y `/chats`. Sin cambios de schema.
- **Reportes (Fase 3)**: `GET /api/v1/metrics/sms/counts` (`MetricsController::smsCounts` → `MetricsService::getSmsCounts`) — total de empresa + desglose por sede (`COUNT(*) GROUP BY branch_id` en SQL), permiso `reports.read`, consolidación `branch.consolidate`. UI: `SmsSentCard` en `/company/reports`.
- **Auditoría**: `order.sms_sent` / `order.sms_failed` (sin actor — system action de cola; teléfono enmascarado). Ver `constants/AUDIT_EVENTS.md`.
- **Feedback al usuario (Fase 4)**: `order_sms_notifications` guarda `user_id` (quién disparó el cambio) y `user_notified_at`. Si el envío async termina en `failed`, `GET /api/v1/order-sms-failures` devuelve los fallos propios del actor aún no vistos y `POST /api/v1/order-sms-failures/seen` los marca (UPDATE atómico, idempotente, N-instance safe). Controller `OrderSmsFailureController`, **self-scoped** (`user_id` + `company_nit`) → sin permiso RBAC nuevo, solo `company.access`. El SPA los muestra como toast una sola vez (`order-sms-failure-watcher.tsx`).

#### Domicilios (`/api/v1/deliveries/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `deliveries` | `deliveries.read,read` |
| POST | `deliveries` | `deliveries.create,create` |
| GET | `deliveries/{id}` | `deliveries.read,read` |
| PATCH | `deliveries/{id}/complete` | `deliveries.update,update` |
| DELETE | `deliveries/{id}` | `deliveries.delete,delete` |
| POST | `deliveries/{id}/reassign` | `deliveries.update,update` |
| GET | `deliveries/couriers` | `deliveries.read,read` |
| GET | `deliveries/metrics` | `deliveries.read,read` |
| GET | `deliveries/reassign-reasons` | `deliveries.read,read` |

`complete()` calcula `duration_minutes` automáticamente (diff entre `assigned_at` y `delivered_at`). `reassign()` cancela la entrega anterior y crea una nueva con `reason` obligatorio. SoftDeletes activo.

#### Cupones (`/api/v1/coupons/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `coupons` | `coupons.read,read` |
| POST | `coupons` | `coupons.create,create` |
| GET | `coupons/{id}` | `coupons.read,read` |
| PUT | `coupons/{id}` | `coupons.update,update` |
| PATCH | `coupons/{id}/status` | `coupons.update,update` |
| DELETE | `coupons/{id}` | `coupons.delete,delete` |
| GET | `coupons/{id}/redemptions` | `coupons.read,read` |
| GET | `coupons/{code}/validate` | JWT (sin company.access) |
| POST | `cart/apply-coupon` | JWT (sin company.access) |

Reglas: código `[A-Z0-9\-_]{4,20}`, único por empresa. `percentage` máx 80%, `fixed_amount` máx 100 000 COP. Cupón con `uses_count > 0` no puede editarse. SoftDeletes activo.

#### Carrito público (`/api/v1/cart/*`)

| Método | URL | Auth |
|--------|-----|------|
| POST | `cart/migrate-jwt/{jwt}` | CartJwt |
| GET | `cart/{jwt}` | CartJwt |
| POST | `cart/apply-coupon` | CartJwt |

CartController resuelve `CartJwtService` perezosamente. 401 si `CART_JWT_SECRET` no está configurado.

#### Horarios (`/api/v1/hours/*`, `/api/external/hours/status`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `hours` | `hours.read,read` |
| PUT | `hours` | `hours.update,update` |
| GET | `hours/status` | `hours.read,read` |
| GET | `hours/exceptions` | `hours.read,read` |
| POST | `hours/exceptions` | `hours.update,update` |
| PUT | `hours/exceptions/{id}` | `hours.update,update` |
| DELETE | `hours/exceptions/{id}` | `hours.update,update` |
| GET | `external/hours/status` | `bot.jwt` (sin company.access) |

`day_of_week`: 0=domingo, 6=sábado (convención Carbon/JS). Excepciones tienen precedencia sobre horario base.

#### Chats (`/api/v1/chats/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `chats` | `chats.read,read` |
| GET | `chats/{id}` | `chats.read,read` |
| POST | `chats/{id}/messages` | `chats.update,update` |
| POST | `chats/{id}/mark-read` | `chats.read,read` |
| GET | `chats/{id}/client` | `chats.read,read` |
| PATCH | `chats/{id}/bot` | `chats.update,update` |
| PATCH | `chats/{id}/contact` | `chats.update,update` |

`index()` acepta `?q=` (búsqueda case-insensitive con escape de wildcards `%`/`_` con ESCAPE `!`). `mark-read` valida `whatsapp_read_receipts` antes de despachar `MarkWhatsappMessageReadJob`.

#### CRM básico de clientes (`/api/v1/clients/*`, issue #123)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `clients` | `clients.read,read` |
| GET | `clients/{contact}` | `clients.read,read` |
| POST | `clients/{contact}/notes` | `clients.update,update` |
| DELETE | `clients/{contact}/notes/{id}` | `clients.delete,delete` |
| POST | `clients/{contact}/tags` | `clients.update,update` |
| DELETE | `clients/{contact}/tags/{id}` | `clients.delete,delete` |

**Cross-sede**: el bloque CRM **no** está bajo `branch.access`. Las queries usan `Order::withoutBranchScope()` y `Contact::withoutBranchScope()` para consolidar un teléfono = un cliente para toda la empresa. `{phone}` se restringe a `[0-9]+` en la ruta y se normaliza con `CrmService::normalizePhone()` antes de cualquier lookup.

`index()` paginado (default 25, max 100) con filtros `search` (nombre o phone), `segment` (`vip|recurrent|new|inactive|at_risk|regular`), `tag`. Listado base cacheado con `Cache::flexible(['crm:list:base:{nit}', 300, 1800])`; filtros aplicados en memoria sobre la lista. KPIs agregados en una sola query con `FILTER (WHERE ...)`.

`show()` devuelve perfil consolidado: KPIs + 50 órdenes + 20 chats + todas las notas/tags. 404 si phone no tiene órdenes ni contacto.

Mutaciones: `ensureClientExists()` valida que el phone tenga al menos una orden o contact en la empresa antes de aceptar nota/tag (previene crear notas sobre PII de terceros). Todas las mutaciones en `DB::transaction` con `AuditService::log()`:
- `client.note_created` / `client.note_deleted` (incluye excerpt 200 chars)
- `client.tag_added` / `client.tag_removed`

Notas: soft-delete (`deleted_at`). Tags: hard-delete + `firstOrCreate` (idempotente vía UNIQUE).

#### Fidelización con puntos (`/api/v1/loyalty/*` y endpoints públicos/bot, issue #122)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `api/v1/loyalty/accounts` | `loyalty.read,read` |
| GET | `api/v1/loyalty/accounts/{phone}` | `loyalty.read,read` |
| POST | `api/v1/loyalty/accounts/{phone}/adjust` | `loyalty.update,update` |
| POST | `api/v1/loyalty/accounts/{phone}/redeem` | `loyalty.update,update` |
| GET | `api/v1/loyalty/reports/summary` | `loyalty.read,read` |
| POST | `api/v1/public/loyalty/{nit}/lookup` | público, `throttle:loyalty-public` (10/min IP+nit) |
| POST | `api/v1/public/loyalty/{nit}/redeem` | público, `throttle:loyalty-public` |
| POST | `api/external/loyalty/lookup` | `bot.jwt` |
| POST | `api/external/loyalty/redeem` | `bot.jwt` |

**Cross-sede**: las cuentas viven por `(company_nit, client_phone)` sin `branch_id`. Igual que CRM, los endpoints staff NO requieren `branch.access`. Phone se normaliza con `CrmService::normalizePhone()` a `57XXXXXXXXXX`.

**Política contable** (CLAUDE.md):
- Puntos **NO** son moneda. Nunca tocan `payment_receipts`.
- `loyalty_movements` es **append-only** (`UPDATED_AT = null`). Correcciones via `type=adjust` con signo opuesto.
- Idempotencia del earn via UNIQUE PARCIAL Postgres: `(reference_type='order', reference_id, type='earn')`. Dos `closeWithPayment` consecutivos sobre la misma orden NO duplican puntos.
- Refund total → `LoyaltyService::refundReverse` crea movement `type=refund_reverse` con `points = -earn.points`. Refunds parciales NO reversan puntos en v1 (decisión pragmática para mantener incentivo).
- Canje genera un `Coupon` con `source='loyalty_redeem'`, `is_single_use=true`, `locked_to_phone=<phone>`. El cupón es de descuento real (afecta `orders.discount_amount`), no toca puntos al aplicarse.
- Todas las mutaciones en `DB::transaction` + `LoyaltyAccount::lockForUpdate()`.

**Eventos auditados** (`audit_logs.action`):
- `loyalty.awarded` (con order_id, points, tier, balance_after)
- `loyalty.redeemed` (reward_key, coupon_id, balance_after)
- `loyalty.refund_reversed` (order_id, points, reverses_movement_id)
- `loyalty.adjusted` (points, reason, balance_after)

**Configuración por empresa** (overrides via `company_settings`):
- `loyalty.enabled` (default `LOYALTY_ENABLED`)
- `loyalty.points_per_cop` (default `0.001` = 1 pt cada $1.000)
- `loyalty.tiers` (JSON array; default bronze/silver/gold con multiplicadores 1.0/1.2/1.4)
- `loyalty.refund_reverses_points` (default `true`)
- `loyalty.expire_after_months` (default `12`; `0` desactiva)

**Comando programado** (`routes/console.php`):
- `loyalty:expire-stale` — diario a las 04:15 hora local. Marca redenciones `issued` con `expires_at` pasado como `expired`, y expira balances inactivos por más de `expire_after_months` (chunked + lockForUpdate). `--dry-run` no muta BD.

**Reportes** (`LoyaltyReportController::summary`): totales del período (earned/redeemed/expired/reversed), top clientes por lifetime, tasa de canje (`applied/total`), ARPU por tier (con SQL contra `orders.status=completed`), distribución de cuentas por tier. Todas las agregaciones en SQL.

#### Alertas accionables de margen y costos (`/api/v1/alerts/*` y `/api/v1/alert-rules/*`, issue #124)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `api/v1/alerts` | `reports.read,read` |
| GET | `api/v1/alerts/summary` | `reports.read,read` |
| POST | `api/v1/alerts/{id}/dismiss` | `reports.read,read` |
| POST | `api/v1/alerts/{id}/action` | `reports.read,read` |
| GET | `api/v1/alert-rules` | `reports.read,read` |
| PUT | `api/v1/alert-rules/{type}` | `company.update,update` |

**Modelos:**
- `alert_rules(id, company_nit, type ∈ {margin_below, cost_increase, item_low_volume, low_stock}, threshold decimal(12,4), period_days, enabled, notify_dashboard, notify_whatsapp)` — UNIQUE `(company_nit, type)`.
- `alert_events(id, alert_rule_id, company_nit, type, severity ∈ {info, warning, critical}, target_type ∈ {menu_item, ingredient, global}, target_id, payload jsonb, triggered_at, dismissed_at, actioned_at, actioned_note, actioned_by)` — UNIQUE PARCIAL `(alert_rule_id, target_type, COALESCE(target_id,''), DATE(triggered_at))` para dedup diario.

**Servicios** (`app/Services/Alerts/`):
- `AlertEngine` — orquesta evaluadores por empresa. Dedup en dos niveles al persistir: (1) evento del día en cualquier estado (UNIQUE parcial por `DATE(triggered_at)`) → UPDATE payload/severity; (2) evento ACTIVO de días anteriores con el mismo `(rule, target)` → se refresca en vez de crear copia diaria (`triggered_at` se conserva: refleja desde cuándo está activa la condición). Antes solo existía el nivel 1 y una condición persistente acumulaba N alertas idénticas en el feed (bug QA 2026-07-01); la migración `dedupe_active_alert_events` saneó los duplicados históricos. Descartar una alerta re-alerta al día siguiente si la condición persiste. Cada empresa en su propia DB::transaction.
- `AlertSeedService::ensureDefaults($nit)` — crea las 4 reglas con defaults si no existen (margen 30%, incremento 10%/7d, low volume 14d, low stock 1d). Idempotente.
- `Evaluators/MarginBelowEvaluator` — SQL agregada sobre `orders.items::jsonb` (mismo patrón que `FoodCostMetricsService`); excluye items con `cost IS NULL`.
- `Evaluators/CostIncreaseEvaluator` — ventana móvil sobre `purchase_order_items.unit_cost JOIN purchase_orders.received_date`. Requiere >=2 compras por ventana.
- `Evaluators/ItemLowVolumeEvaluator` — JOIN entre `restaurant_menus.structure->categories->items` activos y orders del período.
- `Evaluators/LowStockEvaluator` — `SUM(ingredient_stocks.quantity) <= SUM(ingredient_stocks.min_stock)` por insumo activo.

**Política contable / operativa:**
- `alert_events` son inmutables salvo en `dismissed_at`, `actioned_at`, `actioned_note`, `actioned_by`. El payload refleja el snapshot del momento del disparo.
- Mutaciones (dismiss/action) en `DB::transaction` + `lockForUpdate` para evitar dos usuarios manejando el mismo evento.
- Sin automatizaciones: el usuario actúa manualmente desde `/menu` o `/inventory`. Decisión por riesgo de error.
- Gate por `reports.read` en el feed — el contenido expone márgenes/costos indirectamente. Config por `company.update`.

**Auditoría:** `alert.dismissed`, `alert.actioned`, `alert_rule.updated` (con `before/after` para diffing).

**Comando programado** (`routes/console.php`):
- `alerts:evaluate [--company={nit}]` — diario 05:00 hora local. Corre `AlertEngine::runAll` (o por empresa). Re-corrida no duplica eventos (dedup index).

#### WhatsApp (`/api/v1/whatsapp/*`, webhook, externos)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `whatsapp` | `whatsapp.read,read` |
| POST | `whatsapp/embedded-signup-callback` | `whatsapp.connect,create` + OTP |
| POST | `whatsapp/naas-request` | `whatsapp.connect,create` + OTP |
| DELETE | `whatsapp/phone` | `whatsapp.swap_phone,delete` + Policy + OTP |
| DELETE | `whatsapp` | `whatsapp.disconnect,delete` + Policy + OTP |
| POST | `whatsapp/verification/request` | `whatsapp.read,read` |
| POST | `whatsapp/verification/verify` | `whatsapp.read,read` |
| GET | `whatsapp/verification/reject?token=...` | público (token único) |
| GET | `webhooks/whatsapp` | público (handshake verify_token) |
| POST | `webhooks/whatsapp` | público (HMAC `X-Hub-Signature-256`) |
| POST | `external/chats/handoff` | `bot.jwt` |
| POST | `external/chats/messages` | `bot.jwt` |

Header `X-Whatsapp-Verification-Code` transporta el OTP de 6 dígitos. La policy `WhatsappAccountPolicy` (`swapPhone`, `disconnect`) verifica que el actor sea owner por nombre de rol.

#### Métricas (`/api/v1/metrics/*`)

11 endpoints. Todos requieren `permission:reports.read,read`. Cache TTL configurable.

| Método | URL | TTL caché |
|--------|-----|-----------|
| GET | `metrics/summary` | `dashboard_summary_cache_ttl` (60s) |
| GET | `metrics/menu-scans` | `dashboard_metrics_cache_ttl` (300s) |
| GET | `metrics/kpis/today` | 60s |
| GET | `metrics/orders/active` | 30s |
| GET | `metrics/orders/heatmap` | `dashboard_heatmap_cache_ttl` (600s) |
| GET | `metrics/orders/heatmap/weekly` | 600s |
| GET | `metrics/items/top` | `dashboard_chart_cache_ttl` (300s) |
| GET | `metrics/dishes/ranking` | 300s |
| GET | `metrics/cart/abandonment` | 300s |
| GET | `metrics/carts/abandonment` | 300s (variant plural) |
| GET | `metrics/activity/heatmap` | 600s |

Períodos válidos: `today`, `week`, `month`, `custom` (requiere `date_from` + `date_to`). Validados por `PeriodResolver`.

#### Reportes (`/api/v1/reports/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `reports/orders` | `reports.read,read` |
| POST | `reports/export` | `reports.read,read` |
| GET | `reports/download/{token}` | (token firmado en cache) |
| GET | `reports/cash-drawer` | `reports.read,read` |
| GET | `reports/cash-drawer/pdf` | `reports.read,read` |
| GET | `reports/cash-register/sessions` | `reports.read,read` |
| GET | `reports/cash-register/sessions/{id}` | `reports.read,read` |

`OrderReportController::index()` retorna paginado por offset (`per_page` default 25, máx 100) o cursor (`cursor_based=true`). `summary` incluye `total_orders`, `completed`, `failed`, `cancelled`, `refunded`, `abandoned`, `total_revenue` (gross), `total_refunded` (positivo), `net_revenue`. `total_expenses` no se reporta aquí; el food cost por plato se consulta vía `GET /api/v1/metrics/dishes/margin` (ver issue #107).

`CashDrawerController::index` retorna ingresos / refunds / propinas por método y `cash_drawer_expected = cash_gross + cash_tips - cash_refunds`. Filtra por `payment_receipts.paid_at` (no `ordered_at`) en TZ `America/Bogota`. Acepta `date_from` / `date_to`; default = hoy.

`CashRegisterController::index/show` retornan historial de sesiones de caja paginado con `opening_amount`, `expected_cash`, `closing_amount`, `cash_difference` y usuarios involucrados (apertura/cierre).

#### Caja — Sesión de turno (`/api/v1/cash-register/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `cash-register/current` | `orders.read,read` |
| POST | `cash-register/open` | `orders.create,create` |
| POST | `cash-register/close` | `orders.update,update` |
| POST | `cash-register/expenses` | `orders.update,update` |
| GET | `cash-register/sessions/{id}/expenses` | `reports.read,read` |

`current` retorna `{ session, context: { menu_active, in_business_hours, should_alert } }`. Cuando `should_alert=true` (caja cerrada + menú activo + horario hábil), el frontend renderiza un banner global persistente. Polling cada 10s.

**Egresos de caja (`cash_register_expenses`)** — append-only. Categorías cerradas en `config/cash_register.php` (`domiciliario_pago`, `proveedor`, `imprevisto`, `propina_distribuida`, `otro`). Métodos: `cash | card | transfer`. Reducen `expected_cash` cuando son cash (la sesión calcula `opening + ingresos cash + propinas cash − refunds cash − SUM(expenses cash)`). Para corregir un egreso, registrar otro nuevo (no hay PUT/DELETE). `liveSummary` expone `expenses: { total, count, by_method, by_category }`. Audit: `cash.expense.recorded`.

#### Exportaciones (`/api/v1/exports/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| POST | `exports/orders/pdf` | `reports.read,read` |
| POST | `exports/orders/csv` | `reports.read,read` |
| POST | `exports/metrics/pdf` | `reports.read,read` |
| POST | `exports/couriers/pdf` | `deliveries.read,read` |
| POST | `exports/coupons/pdf` | `coupons.read,read` |
| POST | `exports/billing/pdf` | `billing.read,read` |

Filtros aceptados: `filters: { date_from, date_to, status }`. Cap de filas: `pdf.max_rows` (default 500); aviso `limitApplied=true` si supera. CSV de órdenes NO aplica el cap (streaming chunked con BOM UTF-8 `EF BB BF`).

#### Facturación (`/api/v1/billing/*`)

| Método | URL | Permiso |
|--------|-----|---------|
| GET | `billing/plans` | `billing.read,read` |
| GET | `billing/subscription` | `billing.read,read` |
| GET | `billing/invoices` | `billing.read,read` |
| GET | `billing/invoices/{id}` | `billing.read,read` |
| GET | `billing/invoices/{id}/download` | `billing.read,read` |
| GET | `billing/invoices/{id}/pdf` | URL firmada (TTL 3600s) |
| GET | `billing/invoices/export.csv` | `billing.read,read` |
| GET | `billing/promo-code` (#246) | `billing.read,read` |
| POST | `billing/promo-code/preview` (#246) | `billing.read,read` |
| POST | `billing/promo-code` (#246) | owner/admin estricto |
| DELETE | `billing/promo-code` (#246) | owner/admin estricto |

#### Promo codes públicos (#246)

| Método | URL | Auth |
|--------|-----|------|
| GET | `billing/plans/default` | público, throttle:30,1 |
| GET | `promo-codes/{code}/preview` | público, throttle:30,1 |

#### Documentos legales y CSP

| Método | URL | Auth |
|--------|-----|------|
| POST | `csp-report` | público |

---

## Controladores

### `app/Http/Controllers/Api/` (49 archivos)

| Controlador | Métodos públicos | Propósito |
|-------------|------------------|-----------|
| `ActiveCompanyController` | `show` | Datos de la empresa activa según JWT |
| `AlertController` | `index, summary, dismiss, action` | Feed de alertas accionables (#124): low_stock, cost_increase, margin_below, item_low_volume |
| `AlertRuleController` | `index, upsert` | Configuración por empresa de umbrales (margin/cost/stock/volume) |
| `BillingController` | `plans, subscription, invoices, show, download, servePdf, invoicesCsv` | Suscripciones y facturas |
| `BusinessHoursController` | `index, status, update, indexExceptions, storeException, updateException, destroyException` | Horario comercial + excepciones |
| `CartController` | `migrateJwt, show` | Sesión de carrito anónimo (CartJwt) |
| `CartCouponController` | `apply, activeAutoApply` | Aplicar cupón a carrito; anunciar happy hour activo (#125) |
| `CashRegisterController` | `current, open, close, index, show, storeExpense, deleteExpense` | Sesiones de caja (turnos) por sede. UNIQUE parcial `(company_nit, branch_id) WHERE status='open'`. Gastos chiquitos con `cash_register_expenses` |
| `ChatController` | `index, show, storeMessage, markRead, clientDetail, updateBot, updateContact` | Operador ↔ cliente WhatsApp |
| `ClientController` | `index, show, storeNote, destroyNote, storeTag, destroyTag` | CRM básico (#123). Lista cross-sede con KPIs, perfil consolidado, notas privadas (soft-delete) y etiquetas. Phone normalizado vía `CrmService::normalizePhone()` |
| `CompanySettingsController` | `index, show, update` | Settings key-value de empresa |
| `CouponController` | `index, store, show, update, status, destroy` | CRUD cupones (incluye `valid_days`, `valid_hours_*`, `auto_apply` desde #125) |
| `CouponRedemptionController` | `index` | Historial de redenciones |
| `CouponValidationController` | `validate` | Validación pública de código |
| `DeliveryController` | `index, store, show, complete, destroy, getCouriers, assignCourier` | CRUD entregas + asignación |
| `DeliveryMetricsController` | `index` | Métricas por repartidor |
| `DeliveryStatusController` | `getAvailableDeliverers, reassign, getReassignReasons` | Reasignación |
| `ExternalChatHandoffController` | `__invoke` | Bot → operador (handoff) |
| `ExternalChatMessageController` | `store, index` | Bot → backend (messages) |
| `ExternalHoursStatusController` | `__invoke` | Bot → estado de apertura |
| `ExternalLoyaltyController` | `lookup, redeem` | Bot externo: consultar saldo y canjear puntos (#122) |
| `FeatureController` | `index` | Listado de features para UI de roles |
| `FoodCostController` | `summary, history` | Food cost en tiempo real (#113): % costo/ventas + serie histórica |
| `IngredientController` | `index, show, store, update, destroy, restore, valuation` | Inventario de insumos (#111). Soft-delete + valorización por costo promedio |
| `IngredientMovementController` | `index, recordEntry, recordWaste, recordAdjustment` | Movimientos de inventario (entries, mermas, ajustes manuales). Crea filas inmutables en `ingredient_movements` |
| `InventoryHistoryController` | `series` | Serie temporal de valorización por bodega/sede (Chart) |
| `InventoryTransferController` | `store` | Transferencias entre bodegas (multi-bodega #120) |
| `InvitationController` | `store` | Crear invitación de usuario |
| `LoyaltyController` | `index, show, adjust, redeem` | Programa de fidelización (#122). Cuentas y movimientos por `(company_nit, client_phone)` cross-sede |
| `LoyaltyReportController` | `summary` | KPIs del programa: otorgados/canjeados/expirados, distribución por tier, ARPU, top clientes |
| `MeController` | `show, destroy` | Perfil del usuario actual |
| `MenuEngineeringController` | `matrix` | Matriz popularidad × margen para platos activos (#114): stars/plowhorses/puzzles/dogs |
| `MetricsController` | `summary, kpisToday, activeOrders, orderHeatmap, weeklyHeatmap, topItems, topDishes, dishMargin, cartAbandonment, abandonmentRate, activityHeatmap` | KPIs y gráficos. `dishMargin` agregado en #113 |
| `OrderController` | `index, store, show, updateStatus, tables, appendItems, closeWithPayment, cancel, refund` | Pedidos (kanban + histórico). Mutaciones financieras requieren caja abierta y son atómicas (DB::transaction + lockForUpdate). Audit log en cada operación |
| `OrderSyncController` | `sync` | Sincronización idempotente de órdenes creadas offline (PWA Fase 2, #140) |
| `PublicLoyaltyController` | `lookup, redeem` | Endpoint público (sin auth, sólo throttle): el cliente consulta su saldo y canjea desde `/cart/{jwt}` |
| `PurchaseAttachmentController` | `index, store, download, destroy` | Adjuntos (facturas físicas, comprobantes) de órdenes de compra |
| `PurchaseOrderController` | `index, store, show, update, submit, receive, pay, cancel, void, settleRefund` | Órdenes de compra a proveedor (#118). Recepción mueve inventario; anulación post-recepción genera nota crédito |
| `ReceiptPrintController` | `comanda, receipt` | Emite job de impresión ESC/POS hacia driver `HttpAgentDriver` (#116) |
| `RecipeController` | `show, upsert, cost` | BOM (Bill of Materials) por plato (#112). `cost` calcula costo unitario desde ingredientes |
| `RoleController` | `index, store, update, destroy` | CRUD roles de empresa |
| `SupplierController` | `index, show, store, update, destroy, restore` | Proveedores con `supplier_ingredients` para alias × precio histórico |
| `UserRoleController` | `index, update, updatePermissions, updateStatus, destroy` | Membresía + permisos por usuario |
| `WhatsappAccountController` | `show, embeddedSignupCallback, naasRequest, deletePhone, disconnect` | Onboarding WhatsApp |
| `WhatsappVerificationController` | `request, verify, reject` | OTP para acciones sensibles |
| `WhatsappWebhookController` | `verify, receive` | Handshake + recepción webhooks Meta |

### `app/Http/Controllers/Auth/` (10)

| Controlador | Métodos | Propósito |
|-------------|---------|-----------|
| `AuthController` | `selectCompany, switchCompany, logout` | API auth ops |
| `AuthenticatedSessionController` | `create, store, destroy` | Login/logout web (Breeze) |
| `ConfirmablePasswordController` | `show, store` | Confirmación de password |
| `EmailVerificationNotificationController` | `store` | Reenvío de verify email |
| `EmailVerificationPromptController` | `__invoke` | Pantalla "verifica tu email" |
| `GoogleAuthController` | `redirect, callback` | OAuth Google (Socialite) |
| `NewPasswordController` | `create, store` | Reset password |
| `PasswordResetLinkController` | `create, store` | Forgot password |
| `RegisteredUserController` | `create, store` | Registro web (deshabilitado) |
| `VerifyEmailController` | `__invoke` | Verificación firmada |

### `app/Http/Controllers/Reports/` (3)

| Controlador | Métodos | Propósito |
|-------------|---------|-----------|
| `OrderReportController` | `index, export, download` | Listado paginado de pedidos + export PDF asíncrono. Summary incluye gross/refunded/net |
| `PdfExportController` | `orders, ordersCsv, metrics, couriers, coupons, billing` | Exports síncronos a PDF/CSV. PDF orders y metrics ahora muestran subtotal/IVA/total y net por método |
| `CashDrawerController` | `index, exportPdf` | Cierre de caja por rango de fechas (default hoy en TZ Bogotá). Filtra por `paid_at`. PDF dedicado |

### `app/Http/Controllers/Web/`, `Billing/`, `Company/`, `Enrollment/`, `Menu/`, `Settings/`, `Concerns/`

| Controlador | Ubicación | Propósito |
|-------------|-----------|-----------|
| `DashboardController` | `Web/` | Render `/dashboard` con deferred props |
| `CompanyPreferencesController` | `Web/` | Render `/company/preferences` con `availableSettings` |
| `PublicMenuPageController` | `Web/` | Render `/menus/{nit?}` (público QR). Compone branding + menú activo |
| `PwaManifestController` | (root namespace) | Manifest dinámico `manifest.webmanifest` por empresa (#103). Sirve iconos rasterizados desde logo |
| `BillingController` | `Billing/` | Render `/billing` con plan + invoices |
| `CompanyController` | `Company/` | API `/api/v1/company` (show, update — incluye uploads) |
| `BranchController` | `Company/` | CRUD sedes (#117). Archive/restore, asignar usuarios, copiar menú |
| `WarehouseController` | `Company/` | CRUD bodegas por sede (#120). Cocina/barra/congelador. Tabla `warehouse_stock_snapshots` para histórico |
| `PrinterController` | `Company/` | CRUD impresoras térmicas (#116). HTTP agent driver con polling |
| `CompanyEnrollmentController` | `Enrollment/` | Crear empresa + roles del sistema + sede principal + bodega default |
| `InvitedEnrollmentController` | `Enrollment/` | Aceptar invitación a empresa |
| `UserEnrollmentController` | `Enrollment/` | Completar perfil de usuario |
| `MenuController` | `Menu/` | 27 métodos públicos (CRUD completo de menú + categorías + ítems + uploads + scheduling + recetas embebidas) |
| `ProfileController` | `Settings/` | Edición de perfil web |
| `PasswordController` | `Settings/` | Cambio de password web |
| `ResolvesActiveContext` (trait) | `Concerns/` | Helper para extraer `active_company_nit` + `active_branch_id` del request en controllers compartidos |
| `ResolvesJwtActor` (trait) | `Concerns/` | Helper para extraer al usuario actor del JWT (auditoría) |

---

## Modelos Eloquent (68)

### Identidad y RBAC

| Modelo | Tabla | Campos clave | Relaciones | Casts | SoftDeletes |
|--------|-------|--------------|------------|-------|-------------|
| `User` | `users` | id, name, email, status, last_login_at | hasMany(CompanyUser, UserAcceptance, UserActiveToken) | `email_verified_at: datetime`, `last_login_at: datetime` | no |
| `Company` | `companies` | nit (PK), commercial_name, legal_name, status, plan, logo_path, qr_code_path, breb_key, bank_id, account_number, account_type, **tax_regime, default_tax_rate, default_tax_label, tax_included_in_price** | hasMany(CompanyUser, Order, Delivery, Coupon, Chat, CashRegisterSession, ...) hasOne(Subscription) | `default_tax_rate: decimal:2`, `tax_included_in_price: bool` | no |
| `CompanyUser` | `company_users` | user_id, company_nit, company_role_id, status, custom_permissions (JSONB) | belongsTo(User, Company, CompanyRole) | `custom_permissions: array` | no |
| `CompanyRole` | `company_roles` | name, description, color, is_system | hasMany(CompanyUser, CompanyRolePermission) | `is_system: bool` | no |
| `CompanyRolePermission` | `company_role_permissions` | company_role_id, feature_id, can_create/read/update/delete | belongsTo(CompanyRole, Feature) | bools | no |
| `CompanyInvitation` | `company_invitations` | company_nit, email, company_role_id, token, expires_at, status | belongsTo(Company, CompanyRole) | `expires_at: datetime` | no |
| `Feature` | `features` | slug, name, group, is_owner_only | hasMany(CompanyRolePermission, PermissionTemplate) | `is_owner_only: bool` | no |
| `PermissionTemplate` | `permission_templates` | role_type (owner/admin/employee), feature_id, can_* | belongsTo(Feature) | bools | no |
| `CompanyMember` | (vista) | — | — | — | — |
| `CompanySetting` | `company_settings` | company_nit, key, value (JSONB) | belongsTo(Company) | `value: array` | no |
| `UserAcceptance` | `user_acceptances` | user_id, document_type, accepted_at (snapshot opcional para registros previos al wiki externo) | belongsTo(User) | `accepted_at: datetime` | no |
| `UserActiveToken` | `user_active_tokens` | user_id, token_jti, last_seen_at | belongsTo(User) | `last_seen_at: datetime` | no |

### Operaciones

| Modelo | Tabla | Campos clave | Relaciones | Casts | SoftDeletes |
|--------|-------|--------------|------------|-------|-------------|
| `Order` | `orders` | id, company_nit, status, items (JSON con breakdown tributario por línea), total, subtotal, tax_amount, tax_rate (effective ponderado), snapshot_default_tax_rate, tax_regime, tax_included_in_price, tip_amount, cost, discount_amount, coupon_code, ordered_at, order_type, table_number, delivery_address, client_phone | belongsTo(Company), hasOne(Delivery), hasMany(PaymentReceipt as `receipts`) | `items: array`, `total/subtotal/tax_amount/tax_rate/snapshot_default_tax_rate/tip_amount/cost/discount_amount: decimal:2`, `tax_included_in_price: bool`, `ordered_at: datetime` | no |
| `RestaurantMenu` | `restaurant_menus` | id, company_nit, name, status, structure (JSON), active_days (array) | belongsTo(Company) | `structure: array`, `active_days: array` | no |
| `BusinessHour` | `business_hours` | company_nit, day_of_week, open_time, close_time, is_enabled | belongsTo(Company) | `is_enabled: bool` | no |
| `BusinessHourException` | `business_hour_exceptions` | company_nit, exception_date, is_open, open_time, close_time, reason | belongsTo(Company) | `exception_date: date`, `is_open: bool` | no |
| `Delivery` | `deliveries` | id, company_nit, order_id, user_id, status, assigned_at, delivered_at, duration_minutes, reason | belongsTo(Order, User), hasOne(deliverer = User) | `assigned_at, delivered_at: datetime` | **sí** |
| `Coupon` | `coupons` | id, company_nit, code, type, value, expires_at, max_uses, uses_count, first_order_only, min_order_amount | belongsTo(Company), hasMany(CouponRedemption) | `expires_at: datetime`, `value: decimal:2` | **sí** |
| `CouponRedemption` | `coupon_redemptions` | coupon_id, order_id, client_phone, discount_amount, redeemed_at | belongsTo(Coupon, Order) | `redeemed_at: datetime` | no |
| `CartSession` | `cart_sessions` | id, company_nit, jwt_jti (UNIQUE), client_phone, status, expires_at | belongsTo(Company), hasMany(CartItem) | `expires_at: datetime` | no |
| `CartItem` | `cart_items` | cart_session_id, item_id, name, price, quantity, category | belongsTo(CartSession) | `price: decimal:2` | no |
| `PaymentReceipt` | `payment_receipts` | order_id, company_nit, file_path (nullable), payment_method (cash/card/transfer/refund), amount (signed: positivo=cobro, negativo=refund), reference, paid_at, cash_session_id (FK), payment_data (JSON), created_at | belongsTo(Order, Company, CashRegisterSession) | `payment_data: array`, `amount: decimal:2`, `paid_at: datetime` (sin `updated_at` — receipts inmutables) | no |
| `CashRegisterSession` | `cash_register_sessions` | id, company_nit, opened_by_user_id, opened_at, opening_amount, closed_by_user_id, closed_at, closing_amount, expected_cash, cash_difference, status (open/closed), opening_notes, closing_notes | belongsTo(Company), belongsTo(User as openedBy/closedBy), hasMany(PaymentReceipt) | `opening_amount/closing_amount/expected_cash/cash_difference: decimal:2`, `opened_at/closed_at: datetime` | no |

### Comunicación

| Modelo | Tabla | Campos clave | Relaciones | Casts | SoftDeletes |
|--------|-------|--------------|------------|-------|-------------|
| `Chat` | `chats` | id, company_nit, client_phone, source, last_message_at, bot_paused, meta_sync (JSON) | belongsTo(Company), hasMany(ChatMessage) | `last_message_at: datetime`, `bot_paused: bool`, `meta_sync: array` | no |
| `ChatMessage` | `chat_messages` | id, chat_id, sender, body, meta_message_id, media (JSON), sent_at | belongsTo(Chat) | `media: array`, `sent_at: datetime` | no |
| `Contact` | `contacts` | company_nit, phone, name, last_seen_at | belongsTo(Company) | — | no |
| `ClientNote` | `client_notes` | id, company_nit, client_phone, note, created_by, deleted_at | belongsTo(Company), belongsTo(User as `author`) | — | **sí** (#123) |
| `ClientTag` | `client_tags` | id, company_nit, client_phone, tag, created_by, created_at | belongsTo(Company), belongsTo(User as `author`) | `created_at: datetime` (sin updated_at) | no (#123) |

### WhatsApp Cloud API

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `MetaPlatformCredential` | `meta_platform_credentials` | environment, app_id, app_secret (encrypted), system_user_token (encrypted), is_active | — | secrets `encrypted` |
| `CompanyWhatsappAccount` | `company_whatsapp_accounts` | company_nit, provisioning_mode, status, waba_id, phone_number_id (UNIQUE), access_token (encrypted) | belongsTo(Company), hasMany(CompanyWhatsappAccountEvent) | tokens `encrypted` |
| `CompanyWhatsappAccountEvent` | `company_whatsapp_account_events` | account_id, event_type, payload (JSON), occurred_at | belongsTo(CompanyWhatsappAccount) | `payload: array` |
| `WhatsappVerificationCode` | `whatsapp_verification_codes` | company_nit, action, code_hash, attempts, expires_at, consumed_at, rejected_at, reject_token | — | datetimes |

### Facturación

| Modelo | Tabla | Campos clave | Relaciones |
|--------|-------|--------------|------------|
| `BillingPlan` | `billing_plans` | code, name, price, interval, features (JSONB), sort_order, **is_default (UNIQUE WHERE true), price_includes_tax, tax_regime, tax_rate** (#246) | hasMany(Subscription) |
| `Subscription` | `subscriptions` | company_nit, plan_id, status, current_period_start/end, **plan_*_snapshot + plan_snapshot_at** (#246), deleted_at | belongsTo(Company, BillingPlan), hasMany(Invoice) — SoftDeletes |
| `PromoCode` (#246) | `promo_codes` | code (UNIQUE), name, discount_percent (1-100), months_duration (1-120), max_companies, usage_count, starts_at/ends_at, status (active\|inactive), deleted_at | hasMany(CompanyPromoCode) — SoftDeletes |
| `CompanyPromoCode` (#246) | `company_promo_codes` | company_nit, promo_code_id, discount_percent + months_duration (**snapshot inmutable**), starts_at/ends_at, status (active\|expired\|cancelled), applied_via (enrollment\|github_action\|self_service), applied_by, cancelled_*, deleted_at | belongsTo(Company, PromoCode, User), hasMany(Invoice) — UNIQUE parcial (company_nit) WHERE status='active' |
| `Invoice` | `invoices` | company_nit, subscription_id, type, period_from/to, base_amount, **base_amount_taxable, tax_amount, tax_rate, tax_regime, plan_*_snapshot, company_promo_code_id, electronic_document_id** (#246), amount, status, due_date, pdf_path, voided_by_invoice_id | belongsTo(Company, Subscription, CompanyPromoCode), hasMany(InvoiceLine, InvoicePayment) — inmutable post-create |
| `InvoiceLine` | `invoice_lines` | invoice_id, description, amount | belongsTo(Invoice) |
| `InvoicePayment` | `invoice_payments` | invoice_id, paid_at, reference, amount | belongsTo(Invoice) |

**Servicios y comandos #246:**
- `App\Services\PromoCodeService` — validateBySlug, applyToCompany (lockForUpdate + snapshot + audit), cancelForCompany, expireOverdue.
- `App\Services\Dian\SaaSInvoiceDispatchService` + `App\Jobs\EmitDianInvoiceJob` — emisión DIAN para invoices SaaS (CUFE + consecutivo, ShouldBeUnique).
- `App\Support\Money` — banker's rounding (PHP_ROUND_HALF_EVEN), applyPercent, extractBase, sum.
- `App\Support\Nit\DvCalculator` — DV NIT algoritmo DIAN (factores 3..71).
- Comandos artisan: `billing:backfill-default-plan`, `promo:create`, `promo:toggle`, `promo:apply`, `promo:cancel`, `companies:approve` (#257 — activa empresa `pending_activation`, asegura `Subscription` con snapshot, audita `company.activated`, dispara `CompanyRegistrationApprovedNotification`).

**Servicios y notificaciones #257:**
- `App\Services\BillingPlanPresenter::forSubscription(Subscription): array` — fuente única de presentación del plan en correos billing. Lee snapshots inmutables; cae a `BillingPlan` vivo solo para `description`, `currency`, `billing_cycle`, `price_includes_tax`. Loggea `billing.subscription_snapshot_missing` y `billing.plan_feature_label_missing` para detectar drift.
- `App\Notifications\CompanyRegistrationApprovedNotification` — disparada cuando una empresa pasa de `pending_activation → active`. Receptores: `Company::usersToNotifyForBilling()` (owners + admins activos). Cuerpo: nombre del plan, precio, capacidades amigables, noticia tributaria, fecha fin de trial.
- `App\Notifications\Contracts\BillingNotificationContract` — contrato que TODAS las notifs billing implementan. Exige `idempotencyKey()`, `dispatchMetadata()`, `companyNit()`. Garantiza tracking + defensa contra duplicados.
- `App\Services\NotificationDispatchTracker::dispatchOnce(User, Notification)` — envuelve `$user->notify()` con INSERT a `notification_dispatches` (UNIQUE compuesto). Si conflict → skip silencioso + log `notification.dispatch_skipped_duplicate`. Si otro error de BD → log + re-throw (no se envía sin tracking). Doble capa de proteccion sobre los markers `*_notified_at` (a nivel empresa).
- `notification_dispatches` (tabla) — registro append-only de envíos. UUID id, `notification_class`, `idempotency_key`, `user_id` (uuid FK users), `company_nit`, `target_email` (snapshot), `sent_at`, `metadata` JSONB. Indexada por `(company_nit, notification_class, sent_at)` y `(user_id, sent_at)` para queries históricas. No se borra.
- `config/billing_plan_features.php` — mapeo `slug → label amigable` de features y regímenes tributarios. Cuando un slug no tiene label, se omite + warn (no se muestra slug crudo).
- `Company::usersToNotifyForBilling(): Collection<User>` — destinatarios canónicos de notifs billing: owners + admins activos, deduplicados. Filtro: `is_system=true` AND `LOWER(name) IN (Propietario, Administrador)`. Excluye empleados.

### Multi-sede / multi-bodega (#117, #120)

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `Branch` | `branches` | uuid id, company_nit, name, slug, address, city, phone, is_default, archived_at | belongsTo(Company), belongsToMany(User as `users`) | `is_default: bool`, `archived_at: datetime` |
| `BranchUser` | `branch_users` | branch_id, user_id, granted_by_user_id, granted_at | belongsTo(Branch, User) | `granted_at: datetime` |
| `Warehouse` | `warehouses` | uuid id, company_nit, branch_id, name, type (cocina/barra/congelador/...), is_default, archived_at | belongsTo(Branch, Company), hasMany(IngredientStock, IngredientMovement) | datetimes |
| `WarehouseStockSnapshot` | `warehouse_stock_snapshots` | id, warehouse_id, snapshot_date, total_value, currency | belongsTo(Warehouse) | `total_value: decimal:2`, `snapshot_date: date` |

### Inventario, recetas, food cost (#111, #112, #113, #114)

| Modelo | Tabla | Campos clave | Relaciones | Casts | SoftDeletes |
|--------|-------|--------------|------------|-------|-------------|
| `Ingredient` | `ingredients` | id, company_nit, branch_id, name, unit, current_avg_cost, archived_at | belongsTo(Company, Branch), hasMany(IngredientStock, IngredientMovement, SupplierIngredient) | `current_avg_cost: decimal:4`, `archived_at: datetime` | **sí** |
| `IngredientStock` | `ingredient_stocks` | ingredient_id, warehouse_id, on_hand_qty | belongsTo(Ingredient, Warehouse) | `on_hand_qty: decimal:4` | no |
| `IngredientMovement` | `ingredient_movements` | id, ingredient_id, warehouse_id, type (entry/waste/adjustment/recipe_consumption/transfer_in/transfer_out), quantity (signed), unit_cost, total_cost, reference_type, reference_id, recorded_by_user_id, recorded_at, notes | belongsTo(Ingredient, Warehouse) | datetimes, `quantity/unit_cost/total_cost: decimal:4` | no (inmutable) |
| `Recipe` | `recipes` | id, company_nit, branch_id, menu_item_id (string ref), name, items (JSONB: array de {ingredient_id, quantity, unit}), current_unit_cost | belongsTo(Company, Branch) | `items: array`, `current_unit_cost: decimal:4` | no |
| `Supplier` | `suppliers` | id, company_nit, branch_id, name, tax_id, contact_*, payment_terms_days, archived_at | belongsTo(Company, Branch), hasMany(SupplierIngredient, PurchaseOrder) | datetimes | **sí** |
| `SupplierIngredient` | `supplier_ingredients` | supplier_id, ingredient_id, alias, last_unit_price, last_purchased_at | belongsTo(Supplier, Ingredient) | `last_unit_price: decimal:4`, `last_purchased_at: datetime` | no |
| `PurchaseOrder` | `purchase_orders` | id, company_nit, branch_id, supplier_id, code, status (draft/submitted/received/paid/cancelled/voided), subtotal, tax_amount, total, received_at, paid_at, payment_method, payment_reference | belongsTo(Company, Branch, Supplier), hasMany(PurchaseOrderItem, PurchaseOrderAttachment, PurchaseCreditNote) | datetimes, `subtotal/tax_amount/total: decimal:2` | no |
| `PurchaseOrderItem` | `purchase_order_items` | purchase_order_id, ingredient_id, supplier_alias, quantity, unit, unit_price, line_total | belongsTo(PurchaseOrder, Ingredient) | `quantity/unit_price: decimal:4`, `line_total: decimal:2` | no |
| `PurchaseOrderAttachment` | `purchase_order_attachments` | purchase_order_id, file_path, mime_type, original_name, uploaded_by_user_id | belongsTo(PurchaseOrder) | — | no |
| `PurchaseCreditNote` | `purchase_credit_notes` | id, purchase_order_id, reason, total_refunded, status | belongsTo(PurchaseOrder) | `total_refunded: decimal:2` | no |

### Sesiones de caja y gastos

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `CashRegisterExpense` | `cash_register_expenses` | id, cash_register_session_id, company_nit, branch_id, concept, amount, created_at | belongsTo(CashRegisterSession) | `amount: decimal:2`, datetimes |

### Fidelización con puntos (#122)

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `LoyaltyAccount` | `loyalty_accounts` | id, company_nit, client_phone (cross-sede), balance, lifetime_earned, tier (bronze/silver/gold) | belongsTo(Company), hasMany(LoyaltyMovement, LoyaltyRedemption) | `balance/lifetime_earned: integer` |
| `LoyaltyMovement` | `loyalty_movements` | id, loyalty_account_id, type (earn/redeem/adjust/expire), delta (signed), order_id, reason, created_by_user_id | belongsTo(LoyaltyAccount, Order, User) | datetimes |
| `LoyaltyRedemption` | `loyalty_redemptions` | id, loyalty_account_id, reward_key, coupon_id, points_burned | belongsTo(LoyaltyAccount, Coupon) | datetimes |

Notas contables (#122):
- `loyalty_movements` es inmutable (sólo INSERT). Para corregir, otro movement con `type='adjust'` y `delta` signed.
- `balance` y `lifetime_earned` se mantienen en `loyalty_accounts` para evitar SUM costoso; un trigger lógico lo recompone en cada INSERT.
- Movimientos `earn` se disparan en `OrderController::closeWithPayment` (sólo si la orden cierra exitosa y el cliente trae teléfono).

### Alertas accionables (#124)

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `AlertRule` | `alert_rules` | id, company_nit, type (margin_below / cost_increase / low_stock / item_low_volume), threshold (decimal), period_days, is_active | belongsTo(Company) | `threshold: decimal:4`, `is_active: bool` |
| `AlertEvent` | `alert_events` | id, company_nit, branch_id, rule_id, type, severity (info/warn/critical), ref_type, ref_id, payload (JSONB), opened_at, dismissed_at, dismissed_by_user_id | belongsTo(Company, AlertRule), belongsTo(User as `dismisser`) | `payload: array`, datetimes |

### CRM básico de clientes (#123)

Ver tablas `client_notes` y `client_tags` en "Comunicación". Son cross-sede (sólo `company_nit + client_phone`).

### Impresión térmica (#116)

| Modelo | Tabla | Campos clave | Relaciones | Casts |
|--------|-------|--------------|------------|-------|
| `Printer` | `printers` | id, company_nit, branch_id, name, agent_uuid, paper_width_mm, target (cashier/kitchen/bar), is_active | belongsTo(Branch) | `paper_width_mm: int`, `is_active: bool` |

### Telemetría de menú público y offline

| Modelo | Tabla | Campos clave | Notas |
|--------|-------|--------------|-------|
| `MenuScanEvent` | `menu_scan_events_*` (particionadas por mes) | company_nit, branch_id, scanned_at, ip_hash, table_number | Particionado por mes con DDL en `Jobs/AggregateMenuScansJob` |
| `MenuScanDailyRollup` | `menu_scan_daily_rollup` | company_nit, branch_id, scan_date, count | Agregado por `AggregateMenuScansJob` (cron diario) |
| `OfflineSyncEvent` | `offline_sync_events` | id, company_nit, branch_id, idempotency_key (UNIQUE), order_id (resultado), payload_hash, status (synced/conflict), created_at | Idempotencia de órdenes creadas offline (#140) |

### Auditoría y misc

| Modelo | Tabla | Campos clave | Notas |
|--------|-------|--------------|-------|
| `AuditLog` | `audit_logs` | event, user_id, model_type, model_id, data (JSON), ip, user_agent | `data: array` con `before/after` cuando aplica. Auto-incluye `branch_id` + `actor_active_branch_id` (#117) |
| `WebhookEvent` | `webhook_events` | source, event, payload (JSON), processed_at, error | Idempotencia de webhooks externos |
| `Bank` | `banks` | id, name, code | Catálogo de bancos colombianos |

### Scopes comunes

- `forCompany($nit)` — varios modelos lo implementan (`Order`, `Delivery`, `Chat`, `Coupon`, `CartSession`, ...). Aplica `where('company_nit', $nit)` y se usa en todos los controllers de la API.
- `active()` — `RestaurantMenu` (`where('status', 'active')`).
- `today()` — `Order::whereDate('ordered_at', now()->toDateString())`.

---

## Form Requests (71)

| Carpeta | Archivos | Endpoint(s) |
|---------|----------|-------------|
| `Auth/` | `LoginRequest`, `SelectCompanyRequest` | `/login`, `/api/v1/auth/select-company` |
| `Billing/` | `GetInvoicesRequest` | `GET /api/v1/billing/invoices` |
| `Cart/` | `ApplyCouponRequest`, `ActiveAutoApplyRequest` (#125) | `POST /api/v1/cart/apply-coupon`, `POST /api/v1/cart/active-auto-apply` |
| `Chat/` | `StoreChatMessageRequest`, `UpdateChatBotRequest`, `UpdateChatContactRequest` | `/api/v1/chats/{id}/{messages,bot,contact}` |
| `Clients/` | `StoreNoteRequest`, `StoreTagRequest` | `/api/v1/clients/{contact}/{notes,tags}` (#123, refactor #235 — param `{contact}` = `contacts.id`). Tag se lowercases en `prepareForValidation`; regex slug `/^[a-z0-9_\-]+$/`. |
| `Company/` | `UpdateCompanyRequest`, `StoreBranchRequest`, `UpdateBranchRequest`, `StorePrinterRequest`, `UpdatePrinterRequest` | `PUT /api/v1/company`, `/api/v1/company/branches/*`, `/api/v1/company/printers/*` |
| `Coupon/` | `StoreCouponRequest`, `UpdateCouponRequest`, `UpdateStatusRequest` | `/api/v1/coupons/*`. Incluye reglas para `valid_days`, `valid_hours_from/to` (`required_with` pair, `H:i`), `auto_apply` (#125) |
| `Delivery/` | `AssignCourierRequest`, `CompleteDeliveryRequest`, `ReassignDeliveryRequest`, `StoreDeliveryRequest` | `/api/v1/deliveries/*`, `/api/v1/orders/{id}/assign-courier` |
| `Enrollment/` | `CompanyEnrollmentRequest`, `UserEnrollmentRequest` | `/api/v1/enrollment/*` |
| `Exports/` | `PdfExportRequest` | `POST /api/v1/exports/*` |
| `Hours/` | `StoreBusinessHourExceptionRequest`, `UpdateBusinessHourExceptionRequest`, `UpdateBusinessHoursRequest` | `/api/v1/hours/*` |
| `Inventory/` | `StoreIngredientRequest`, `UpdateIngredientRequest`, `RecordEntryRequest`, `RecordWasteRequest`, `RecordAdjustmentRequest` | `/api/v1/inventory/*` (#111) |
| `Menu/` | 10 requests (Store/Update Menu/Category/Item, Schedule, UploadDishImage, UpdateDishAvailability, DuplicateMenu) | `/api/v1/menus/*` |
| `Metrics/` | 12 requests (uno por endpoint, incluye `GetDishMarginRequest`, `GetFoodCostSummaryRequest`, `GetFoodCostHistoryRequest`, `GetMenuEngineeringRequest`) | `/api/v1/metrics/*` |
| `Purchases/` | `StorePurchaseOrderRequest`, `UpdatePurchaseOrderRequest`, `MarkPaidRequest`, `CancelPurchaseOrderRequest`, `VoidPurchaseOrderRequest`, `StoreAttachmentRequest` | `/api/v1/purchases/*` (#118) |
| `Recipes/` | `UpsertRecipeRequest` | `PUT /api/v1/menus/{menu}/items/{itemId}/recipe` (#112) |
| `Reports/` | `OrderReportRequest`, `ExportReportRequest` | `/api/v1/reports/*` |
| `Settings/` | `ProfileUpdateRequest`, `UpdateCompanySettingsRequest` | `/settings/profile`, `/api/v1/companies/settings` |
| `Suppliers/` | `BaseSupplierRequest`, `StoreSupplierRequest`, `UpdateSupplierRequest` | `/api/v1/suppliers/*` |

Reglas comunes:
- `mimes:png,jpg,jpeg,webp,svg` para uploads de logo (máx 5 MB).
- `mimes:png,jpg,jpeg` para QR e imágenes de plato (máx 5 MB / 2 MB respectivamente).
- `mimes:pdf,jpg,jpeg,png` para adjuntos de órdenes de compra (`Purchases/StoreAttachmentRequest`, máx 10 MB).
- `Rule::in(...)` para enums (status, period, role_type, alert type, payment_method).
- `date_format:Y-m-d` y `after_or_equal:date_from` para rangos.
- `date_format:H:i` para `valid_hours_from/to` (#125).

---

## Servicios (52 archivos)

### Autenticación y JWT

| Servicio | Métodos clave | Config |
|----------|---------------|--------|
| `JwtService` | `issue, verify, reissue, revoke, extractTokenFromRequest, buildCookie, invalidateUserActiveSession` | `JWT_*` |
| `BotJwtService` | `issue, verify` | `BOT_JWT_*` |
| `CartJwtService` | `issue, verify, decodePayload` | `CART_JWT_*` |

### RBAC

| Servicio | Propósito |
|----------|-----------|
| `FeaturePermissionService` | `hasPermission($request, $feature, $action)`, `assertPermission()` (lanza 403). Bypass para `is_system=true`. Aplica overrides individuales. |
| `MenuPermissionService` | Helpers específicos de menú (canCreate/canUpdate/canDelete del response API). |
| `ReportsPermissionService` | Gate adicional para `/dashboard` y `/company/metrics` web (alineado con RBAC API). |

### Operaciones

| Servicio | Propósito | Config |
|----------|-----------|--------|
| `BusinessHoursService` | Resuelve apertura/cierre considerando excepciones; estado en tiempo real | `config/business-hours.php` |
| `CashRegisterService` | `openSession`, `closeSession`, `activeSession`, `requireActiveSession`, `liveSummary`, `computeExpectedCash`, `recordExpense`. Atomicidad con `lockForUpdate`. Calcula expected = opening + cash_gross + cash_tips − cash_refunds_originados_en_cash − expenses. Propinas por receipt (`payment_data.tip_amount`), no `orders.tip_amount` vía JOIN — el JOIN multiplicaba la propina por cada receipt cash (pagos divididos) inflando `expected_cash` (fix v1.30.3). Sesión por sede (#117) | `config/cash_register.php` |
| `CouponService` | Validar, aplicar, redimir cupón; generar códigos; `bestAutoApplyForCart` selecciona cupón happy hour (#125) | `config/coupons.php` |
| `DeliveryService` | Asignar, reasignar, completar; cálculo de duración; métricas | `config/delivery.php` |
| `DeliveryNotificationService` | Notificaciones WhatsApp al asignar/completar (no bloquea si falla) | — |
| `MenuSchedulerService` | Activar menú según día de la semana | — |
| `MetricsService` | KPIs, heatmaps, top items, abandono — con caché por dominio | `config/metrics.php` |
| `TaxCalculator` | `calculateLine($price, $qty, $rate, $included)` y `aggregate($lines)`. SSoT del desglose subtotal/tax/total. Espejo en `lib/tax.ts` para preview. | `config/taxes.php` |
| `OrderTotalCalculator` (`App\Support`) | `recalculateAndSave($order)`. Recalcula `subtotal/tax_amount/tax_rate/total/cost` desde filas `order_items` no-canceladas delegando en `TaxCalculator` + snapshot tributario de la orden (`order_items.tax_rate` por línea, fallback `snapshot_default_tax_rate`) y **reproyecta `orders.items` JSON** (fuente = filas; JSON = proyección de lectura) — consolidación caja/QR (#293). Prorratea descuento de cupón igual que `appendItems`. Guarda anti-colapso: órdenes legacy con JSON pero sin filas no se tocan. Caller debe tener `lockForUpdate`. | — |
| `CrmService` | CRM básico de clientes (#123). `listClients($nit, $filters, $page, $perPage)` con KPIs agregados en SQL + segmentación heurística en PHP. `profile($nit, $phone)` consolida historial órdenes/chats/notas/tags cross-sede. `normalizePhone($raw)` aplica formato `57XXXXXXXXXX` (idempotente). `forgetCache($nit, $phone?)` invalida listado base + perfil + tags disponibles. Cache `flexible([300, 1800])` para listado. | — |
| `LoyaltyService` (#122) | `earnForOrder($order)`, `redeem($account, $rewardKey, $orderTotal)`, `adjust($account, $points, $reason, $actor)`, `expireStale($company, $cutoffDate)`. Calcula `tier` desde `lifetime_earned` según `config/loyalty.php`. Earn corre dentro del cierre exitoso de la orden (no de la apertura). Canje crea un `Coupon` `locked_to_phone` con `source='loyalty_redeem'`. | `config/loyalty.php` |
| `InventoryService` (#111) | `recordMovement($type, $ingredient, $warehouse, $qty, $unitCost, $ref)`. Movements inmutables. Recalcula `current_avg_cost` con costo promedio ponderado. Aplica auto-consumo desde recetas en `OrderController::closeWithPayment` (no en draft). | `config/menu.php` para auto-consumo |
| `PurchaseService` (#118) | `createDraft`, `submit`, `receive` (mueve inventario), `pay` (registra `payment_method` + `payment_reference`), `cancel` (pre-recepción), `void` (post-recepción → nota crédito + reverso inventario). Atomic + lockForUpdate. Audita cada transición | `config/purchases.php` |
| `PurchaseAttachmentService` | Guarda adjuntos en `private/` con UUID, valida MIME + tamaño, soft-delete | — |
| `RecipeCostService` (#112) | `recalculateCost($recipe)` desde `current_avg_cost` de los insumos. Snapshot a `menu_item_cost_history` para evitar drift en reportes históricos. Idempotente. | — |
| `WarehouseStockHistoryService` (#120) | Genera `warehouse_stock_snapshots` diarios (comando `inventory:snapshot-daily`). Calcula valor total desde `IngredientStock.on_hand_qty * Ingredient.current_avg_cost`. | — |
| `MenuEngineeringService` (#114) | Construye matriz popularidad × margen. Popularidad = `units_sold / total_units` en el período; margen = `(price - cost) / price`. Cuatro cuadrantes Stars/Plowhorses/Puzzles/Dogs. | — |
| `FoodCostMetricsService` (#113) | Calcula `food_cost_pct = SUM(cost*qty) / SUM(price*qty) * 100` agrupando por período. Serie histórica diaria para chart. | — |
| `Alerts/AlertEngine` (#124) | Orquesta evaluadores. Resuelve reglas activas por empresa, ejecuta cada evaluador, persiste eventos. Idempotente: si ya existe un evento abierto del mismo `(rule, ref_type, ref_id)`, no duplica. | — |
| `Alerts/AlertSeedService` | Crea reglas con thresholds default al onboarding de la empresa | — |
| `Alerts/Evaluators/MarginBelowEvaluator` | Detecta platos con margen actual < threshold | — |
| `Alerts/Evaluators/CostIncreaseEvaluator` | Detecta insumos con `current_avg_cost` que subió >X% en N días | — |
| `Alerts/Evaluators/LowStockEvaluator` | Detecta insumos con `on_hand_qty < min_stock_qty` | — |
| `Alerts/Evaluators/ItemLowVolumeEvaluator` | Detecta platos activos con ventas < threshold en N días | — |

### Empresa, settings, auditoría

| Servicio | Propósito |
|----------|-----------|
| `CompanySettingsService` | Lectura/escritura de `company_settings` (key-value JSONB) con allowlist `ALLOWED_KEYS` |
| `AuditService` | `log($event, $user, $model, $metadata, $request)` → tabla `audit_logs` |
| `PeriodResolver` | Parsea `period` (`today/week/month/custom`) → `[Carbon $from, Carbon $to]` |

### Exportaciones

| Servicio | Propósito |
|----------|-----------|
| `PdfExportService` | Genera PDFs (orders, metrics, couriers, coupons, billing, loyalty, cash_drawer) usando DomPDF + plantillas blade. Tipografía FlexyFont (#109) embebida. |
| `CsvExportService` | CSV streaming con BOM UTF-8 (`exportOrders`, `exportInvoices`) — sin cap de filas |
| `InvoicePdfService` | PDF específico de facturas + URL firmada para descarga |
| `LogoIconRasterizer` | Convierte logo de la empresa (PNG/JPG/SVG) → set de iconos PWA (`192x192`, `512x512`, monocromos, maskable). Sirve `PwaManifestController` (#103). Cachea resultado en `public/icons/{nit}/` con purga por hash. |

### Impresión térmica (#116)

| Servicio | Propósito |
|----------|-----------|
| `Printing/ReceiptPrintingService` | Punto de entrada: dado un `Order` + `Printer` produce el ticket. |
| `Printing/CommandTicketService` | Comanda de cocina (sin precios, con tabla de mods/notas). Despacha `PrintCommandTicketJob`. |
| `Printing/EscposBuilder` / `EscposTicketBuilder` | Builders ESC/POS: header empresa + logo, líneas, totales, payment, propina, footer. |
| `Printing/Drivers/HttpAgentDriver` | Empuja el job al agente local en la sede vía HTTP polling. Usa `agent_uuid` como secreto compartido. |

### Analytics y telemetría

| Servicio | Propósito |
|----------|-----------|
| `Analytics/BotDetectionService` | Detecta bots/crawlers en `/menus/{nit}` para no inflar telemetría (`menu_scan_events`). |
| `Analytics/MenuScanPartitionService` | Crea/elimina particiones mensuales en `menu_scan_events_*`. Llamado por `EnsureMenuScanPartitions` y `DropOldMenuScanPartitionsJob`. |

### Facturación

| Servicio | Propósito | Comandos relacionados |
|----------|-----------|------------------------|
| `BillingService` | Generar facturas mensuales (incluye cargo por uso DIAN — `BILLING_DIAN_UNIT_PRICE`/documento emitido, sin descuento de promo), marcar vencidas, expirar descuentos, actualizar estado de empresa (`activa/mora/delinquent`), resumen de uso DIAN del período en curso (`getCurrentPeriodDianUsage`, consumido por `Api/BillingController::subscription`) | `GenerateMonthlyInvoicesCommand`, `MarkOverdueInvoicesCommand`, `ExpireDiscountsCommand` |

### WhatsApp (sub-servicio en `app/Services/Whatsapp/`)

| Servicio | Propósito |
|----------|-----------|
| `WhatsappAccountService` | Orquesta connect/swap/disconnect/NaaS request; persiste eventos auditables |
| `WhatsappInboundMessageHandler` | Convierte payload de webhook → `chats` + `chat_messages` (idempotente por `meta_message_id`) |
| `WhatsappOutboundMessageSender` | Envía texto/media via Meta Graph API |
| `WhatsappSignatureValidator` | Valida HMAC `X-Hub-Signature-256` con `META_APP_SECRET` |
| `WhatsappVerificationCodeService` | Emite OTP al owner (`code_hash`), verifica, gestiona rate limit y rejects |
| `MetaGraphApiClient` | Cliente HTTP fino: token exchange, subscribe webhook, delete phone |

---

## Jobs (6)

| Job | Trigger | Cola | Reintentos | Propósito |
|-----|---------|------|-----------|-----------|
| `GenerateReportPdf` | `OrderReportController@export` (POST `/api/v1/reports/export`) | default | 3 | Genera reporte HTML/PDF asíncrono, lo guarda en disk y deja un token en `Cache::put("report_download:{$token}", ...)` con TTL `reports.download_ttl` (30 min) |
| `DownloadWhatsappMediaJob` | `WhatsappInboundMessageHandler` cuando llega imagen/audio/doc | default | 3 | Descarga media desde Meta Graph API y la persiste en `chat_messages.media` |
| `MarkWhatsappMessageReadJob` | `ChatController::markRead` cuando hay nuevo mensaje cliente con `meta_message_id` | default | 3 | Llama Meta API para marcar leído (doble chulito azul). Throttle 5 min por chat (`chat:{id}:last_read_message_id`) |
| `AggregateMenuScansJob` | cron diario 00:05 | default | 3 | Agrega `menu_scan_events_*` (particiones) → `menu_scan_daily_rollup`. Idempotente |
| `DropOldMenuScanPartitionsJob` | cron mensual día 1 04:00 | default | 1 | Elimina particiones `menu_scan_events_YYYY_MM` > 6 meses |
| `PrintCommandTicketJob` | `OrderController::store` (sólo si la empresa tiene printer activa para cocina/bar) | default | 5 | Envía comanda al `HttpAgentDriver`. Falla silenciosa: la orden no se bloquea por impresora caída (#116) |

---

## Comandos Artisan (15)

Registrados automáticamente por Laravel 12 (no requieren `Kernel.php`).

| Comando | Cron | Hora UTC | Propósito |
|---------|------|----------|-----------|
| `billing:generate-monthly-invoices` | `0 3 1 * *` (#246 post-pago) | 3 AM día 1 | Factura el mes ANTERIOR para suscripciones activas. IVA desglose UBL AllowanceCharge + snapshot del plan + DB::afterCommit dispatch EmitDianInvoiceJob. onOneServer + withoutOverlapping(60). |
| `billing:mark-overdue-invoices` | `dailyAt('04:30')` | 4:30 AM diaria | Marca facturas pendientes con `due_date < today` como `overdue` y delega `BillingService::recalculateCompanyStatus()` para transicionar `active → past_due → suspended → active` por empresa. Facturas de $0 (descuento 100%) se auto-pagan al vencimiento (`status='paid'`, audit `invoice.auto_paid_zero_amount`) en vez de entrar en mora. Idempotente. |
| `companies:recalculate-statuses` (#193) | `everyFourHours()` | cada 4 horas | Itera empresas en `past_due`/`suspended` por chunks de 200 y vuelve a evaluar su estado. Permite que un comprobante aprobado a media tarde reactive la cuenta dentro de las próximas ~4h sin esperar al cron diario. Idempotente; `onOneServer()+withoutOverlapping(30)` para ser N-instance safe en el ASG (requiere cache store compartido). |
| `billing:expire-discounts` | `dailyAt('04:45')` (#246) | 4:45 AM diaria | Marca `company_promo_codes.status='active'` con `ends_at<hoy` como `expired`. Audita uno-a-uno con lockForUpdate. Idempotente. onOneServer + withoutOverlapping(15). |
| `billing:backfill-default-plan` (#246) | manual (SSM) | — | Asigna plan default + snapshot a empresas/subscriptions sin él. Idempotente, --dry-run + --force. Una sola ejecución post-deploy. |
| `billing:change-plan` (2026-07) | manual via GH Action `bistro-ops-company-plan.yml` | — | Cambia el plan de una empresa por NIT entre `default` (Plan Básico, $0) y `plus` (Plan Plus, $300.000/mes + $10/factura electrónica). Cancela la subscription activa y crea una nueva con snapshot completo. Idempotente (no-op si ya está en el destino), --dry-run, audit `subscription.plan_changed`. |
| `promo:create / promo:toggle / promo:apply / promo:cancel` (#246) | manual via GH Action `promo-codes-ops.yml` | — | Backoffice de promo codes: catálogo + aplicación/cancelación por NIT. Snapshot inmutable + audit. |
| `dian:dispatch-pending` | `everyFiveMinutes()` | cada 5min | Reintenta documentos DIAN `error` (backoff) y recupera `pending`/`sent` atascados > `DIAN_STUCK_RECOVERY_MINUTES` (reusa consecutivo vía `DianDispatchService::retry`, idempotente por CUFE/CUDE). No-op si `DIAN_EMISSION_ENABLED=false`. onOneServer + withoutOverlapping(10). |
| `dian:check-pending-acceptance` | `everyFifteenMinutes()` | cada 15min | Proceso de validación: audita en `audit_logs` (`dian.document.validation_stuck`, `dian.document.validation_retries_exhausted`) documentos que `dian:dispatch-pending` no pudo resolver o cuyos reintentos se agotaron (`retry_count>=6`, excluidos para siempre de la recuperación automática). No-op si `DIAN_EMISSION_ENABLED=false`. onOneServer + withoutOverlapping(20). |
| `chats:purge-old` | `dailyAt('03:00')` | 3 AM diaria | Borra `chats` inactivos > 60 días, preservando `contacts` y `orders` |
| `menus:sync-schedule` | `0 * * * *` | cada hora | Activa el menú correspondiente al día de la semana |
| `whatsapp:replay-events` | manual | — | Reprocesa `webhook_events` no procesados (idempotente) |
| `menu-scans:ensure-partitions` | mensual día 25 04:00 | — | Garantiza partición del próximo mes en `menu_scan_events_*` |
| `brand-fonts:install` | manual (post-deploy) | — | Copia FlexyFont (#109) a `storage/app/fonts/` y registra en DomPDF |
| `menu-items:snapshot-costs` | diario 02:00 (#113) | — | Snapshot diario de costo unitario por plato a `menu_item_cost_history` |
| `inventory:snapshot-daily` | diario 02:30 (#120) | — | Snapshot diario por bodega a `warehouse_stock_snapshots` |
| `loyalty:expire-stale` | diario 03:30 (#122) | — | Caduca puntos con `last_earn_at < now() - LOYALTY_EXPIRY_DAYS` (default 365). Inserta `loyalty_movements.type='expire'` con delta negativo |
| `alerts:evaluate-rules` | cada 30 min (#124) | — | Ejecuta `AlertEngine` para todas las empresas activas con reglas habilitadas |

Todos los `dispatched()` schedules viven en `routes/console.php`.

---

## Notificaciones (3)

| Clase | Trigger | Canal | Cola |
|-------|---------|-------|------|
| `InvoiceGeneratedNotification` | Tras generar facturas mensuales | mail | sí |
| `InvoiceOverdueNotification` | Tras marcar como vencida | mail | sí |
| `WhatsappActionVerificationCodeNotification` | Acción sensible WhatsApp (connect/swap/disconnect) | mail | sí |
| `CompanyRegistrationPendingNotification` | Tras `CompanyEnrollmentController@store` — registro exitoso + empresa pendiente de aprobación | mail | sí |

Plantillas: `resources/views/emails/whatsapp/verification-code.blade.php`, `resources/views/emails/enrollment/company-pending-approval.blade.php`.

---

## Policies (4)

| Policy | Modelo | Métodos | Propósito |
|--------|--------|---------|-----------|
| `CompanyRolePolicy` | `CompanyRole` | `update, delete` | Bloquea modificación/eliminación de roles `is_system=true` |
| `WhatsappAccountPolicy` | `CompanyWhatsappAccount` | `swapPhone, disconnect` | Verifica que el actor es owner por nombre de rol incluso cuando RBAC ya pasó |
| `LoyaltyAccountPolicy` (#122) | `LoyaltyAccount` | `view, adjust, redeem` | Compone `loyalty.read`/`loyalty.update` con membresía de empresa. `adjust` también revisa `LOYALTY_MAX_MANUAL_ADJUST` |
| `AlertEventPolicy` (#124) | `AlertEvent` | `view, dismiss, action` | Asegura que el actor sólo puede operar alertas de su empresa y sede activa (cuando `branch_id` no es null) |

---

## Migraciones (21 archivos físicos, consolidados en bloques)

A partir del refactor de mayo 2026, las migraciones se consolidaron por dominio en bloques `0001_01_01_NNN_create_<dominio>_block.php`. Las deltas posteriores viven en archivos con timestamp real (`2026_05_*`).

| Archivo | Dominio | Contenido principal |
|---------|---------|---------------------|
| `0001_01_01_000000_create_foundation_tables` | Foundation | `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `audit_logs`, `webhook_events`, `user_acceptances`, `user_active_tokens`, `personal_access_tokens` (la tabla `legal_documents` se introdujo aquí y se retiró en mayo 2026) |
| `0001_01_01_000100_create_companies_block` | Empresas | `companies` (con tax_regime, banking, logo/QR, status), `banks`, `company_settings` (JSONB), `company_invitations` |
| `0001_01_01_000200_create_permissions_block` | RBAC | `features` (con `is_owner_only`), `permission_templates`, `company_roles`, `company_users` (con `custom_permissions` JSONB), `company_role_permissions` |
| `0001_01_01_000300_create_branches_block` | Multi-sede (#117) | `branches`, `branch_users` |
| `0001_01_01_000400_create_billing_block` | Facturación | `billing_plans`, `subscriptions`, `subscription_discounts`, `invoices`, `invoice_lines`, `invoice_payments` |
| `0001_01_01_000500_create_business_hours_block` | Horarios | `business_hours`, `business_hour_exceptions` |
| `0001_01_01_000600_create_menu_block` | Menú | `restaurant_menus`, `menu_item_cost_history` (#113), `recipes` (#112) |
| `0001_01_01_000700_create_orders_block` | Pedidos | `orders` (con tax_*, tip_amount, items JSONB), `payment_receipts`, `cart_sessions`, `cart_items` |
| `0001_01_01_000800_create_cash_register_block` | Caja | `cash_register_sessions` (UNIQUE parcial `(company_nit, branch_id) WHERE status='open'`), `cash_register_expenses` |
| `0001_01_01_000900_create_coupons_deliveries_block` | Cupones + Domicilios | `coupons` (con `scope`, `valid_in_branches`, `locked_to_phone`, `source`), `coupon_redemptions`, `deliveries` |
| `0001_01_01_001000_create_inventory_block` | Inventario (#111, #118) | `ingredients`, `ingredient_stocks`, `ingredient_movements`, `suppliers`, `supplier_ingredients`, `purchase_orders`, `purchase_order_items`, `purchase_order_attachments`, `purchase_credit_notes` |
| `0001_01_01_001100_create_chat_block` | WhatsApp + Chats | `chats`, `chat_messages`, `contacts`, `meta_platform_credentials`, `company_whatsapp_accounts`, `company_whatsapp_account_events`, `whatsapp_verification_codes` |
| `0001_01_01_001200_create_metrics_block` | Métricas y telemetría | `menu_scan_events` (PARTITION BY RANGE), `menu_scan_daily_rollup`, `offline_sync_events`, `printers` |
| `2026_05_10_162911_create_crm_tables` | CRM (#123) | `client_notes` (soft-delete), `client_tags` (UNIQUE `(company_nit, client_phone, tag)`) — sin `branch_id` (cross-sede) |
| `2026_05_11_000000_create_warehouses_block` | Multi-bodega (#120) | `warehouses` (FK `branch_id`), `warehouse_stock_snapshots`. Migra `ingredient_stocks` y `ingredient_movements` para FK `warehouse_id` |
| `2026_05_11_083347_create_alert_tables` | Alertas (#124) | `alert_rules`, `alert_events` |
| `2026_05_11_095500_add_schedule_to_coupons` | Happy hour (#125) | `coupons.valid_days` JSONB, `valid_hours_from/to` time, `auto_apply` bool + CHECK constraint `coupons_valid_hours_pair_chk` + partial index `coupons_auto_apply_active_idx` |
| `2026_05_11_100000_add_gin_indexes_to_json_columns` | Performance | Índices GIN para `orders.items`, `restaurant_menus.structure`, `coupons.valid_days/valid_in_branches` |
| `2026_05_11_200000_create_loyalty_tables` | Fidelización (#122) | `loyalty_accounts`, `loyalty_movements`, `loyalty_redemptions` |
| `2026_05_11_210000_add_loyalty_columns_to_coupons` | Fidelización | `coupons.locked_to_phone`, `coupons.source` ENUM (`manual`, `loyalty_redeem`, ...) para canjes desde puntos |
| `2026_05_14_084116_cleanup_stale_legal_document_v1_placeholders` | Legales (#170) | Migración one-shot: borra filas `legal_documents` v1.0 cuyo contenido coincide byte-a-byte con el texto placeholder del seeder anterior. Habilita la transición a la fuente .md sin abortar el deploy por drift. `user_acceptances` no afectados (snapshot propio). |
| `2026_05_23_000000_drop_legal_documents_and_relax_user_acceptances` | Legales | Drop de `legal_documents` (TOS/privacidad pasaron al sitio institucional `flexyflow.co`, contrato a `contrato.md` en el repo) y `document_version` / `document_content` de `user_acceptances` quedan nullable. Los registros históricos se conservan para Habeas Data CO. |

### Índices de rendimiento (migración `2026_05_01_210000_dashboard_performance`)

| Tabla | Índices compuestos |
|-------|--------------------|
| `orders` | `(company_nit, status, ordered_at)`, `(company_nit, ordered_at)` |
| `deliveries` | `(company_nit, status, assigned_at)` + UNIQUE parcial `(order_id, company_nit) WHERE status='pending' AND deleted_at IS NULL` |
| `coupon_redemptions` | `(company_nit, coupon_id)`, `(client_phone, coupon_id)` |
| `cart_sessions` | `(company_nit, status, expires_at)` |
| `chats` | `(company_nit, last_message_at DESC)`, `(company_nit, source)` |

---

## Configuración (`config/`, 31 archivos)

| Archivo | Propósito | Variables `.env` clave |
|---------|-----------|-------------------------|
| `app.php` | Nombre, timezone (`America/Bogota` fijo), locale (`es-CO`), CSP nonce | `APP_NAME`, `APP_ENV` |
| `auth.php` | Guards (web, jwt), password resets, providers | — |
| `billing.php` | Moneda, gracia, días de generación/vencimiento | `BILLING_CURRENCY`, `BILLING_GRACE_MONTHS`, `BILLING_DUE_DAY`, `BILLING_GENERATE_DAY`, `BILLING_OVERDUE_DAY`, `BILLING_NOTIFY_*` |
| `bot.php` | JWT bot + cart JWT | `BOT_JWT_SECRET`, `BOT_JWT_TTL`, `CART_JWT_SECRET`, `CART_JWT_TTL`, `CART_BASE_URL` |
| `business-hours.php` | Plantilla de horario semanal default | — |
| `cache.php` | Driver (database/redis), prefix | `CACHE_STORE`, `CACHE_PREFIX` |
| `cash_register.php` | Topes mínimos, métodos válidos, expense max | `CASH_REGISTER_*` |
| `company_defaults.php` | Settings por defecto al crear empresa | — |
| `company_settings.php` | Allowlist de keys + tipos esperados + caché | `COMPANY_SETTINGS_CACHE_TTL`, `COMPANY_SETTINGS_CACHE_ENABLED` |
| `coupons.php` | Reglas de validación, máximos | `COUPON_CODE_MAX_LENGTH`, `COUPON_MAX_VALUE_PERCENTAGE` |
| `database.php` | Conexiones (pgsql, mysql, sqlite) | `DB_CONNECTION`, `DB_*` |
| `delivery.php` | Límites, notificaciones | `DELIVERY_MAX_ACTIVE_PER_COURIER`, `DELIVERY_NOTIFY_*` |
| `dompdf.php` | Tamaño papel, orientación, fuentes (FlexyFont) | `PDF_DRIVER` |
| `filesystems.php` | Discos (local, public, s3) | `FILESYSTEM_DISK`, `AWS_*` |
| `legal.php` | URLs fijas de TOS/privacidad (`flexyflow.co`) + `contract_path` (`/legal/contract`, resuelto contra `app.frontend_url` por ambiente). El frontend lee `useBootstrap().data.legalUrls`. | — |
| `logging.php` | Canales (single, daily, slack) | `LOG_CHANNEL` |
| `loyalty.php` (#122) | Tiers, ratio earn, expiración, rewards | `LOYALTY_ENABLED`, `LOYALTY_EARN_RATIO`, `LOYALTY_EXPIRY_DAYS`, `LOYALTY_MAX_MANUAL_ADJUST`, `LOYALTY_REWARDS` |
| `mail.php` | Driver, from, reply_to global; SES via IAM en qa/pdn ([`EMAIL_SES_SETUP.md`](EMAIL_SES_SETUP.md)) | `MAIL_MAILER`, `MAIL_FROM_*`, `MAIL_REPLY_TO_ADDRESS`, `SES_CONFIGURATION_SET`, `SES_WEBHOOK_SECRET` |
| `menu.php` | Disco de imágenes, tamaño máximo, auto_consume_recipes | `MENU_IMAGE_DISK`, `MENU_IMAGE_MAX_SIZE_KB`, `MENU_AUTO_CONSUME_RECIPES` |
| `metrics.php` | TTL de caché por dominio | `METRICS_CACHE_TTL`, `DASHBOARD_*_CACHE_TTL`, `DASHBOARD_CACHE_ENABLED` |
| `mobile.php` | Page sizes para cursor pagination | `MOBILE_API_*_PAGE_SIZE` |
| `orders.php` | Estados canónicos (revenue, operational, terminal_*) | `ORDER_TIMEOUT_MIN` |
| `pdf.php` | Cap de filas, fuente, footer | `PDF_MAX_ROWS` |
| `printing.php` (#116) | Impresoras, timeouts del agente, ancho de papel | `PRINTING_*` |
| `purchases.php` (#118) | Estados, métodos de pago, días de crédito default | `PURCHASES_*` |
| `queue.php` | Driver (database/redis/sync) | `QUEUE_CONNECTION` |
| `reports.php` | Rango máximo, TTL de descarga | `REPORT_MAX_DATE_RANGE_DAYS`, `REPORT_DOWNLOAD_TTL` |
| `roles.php` | Nombres de roles del sistema + DEFAULT_EMPLOYEE_PERMISSIONS | `ROLE_OWNER_NAME`, `ROLE_ADMIN_NAME`, `ROLE_EMPLOYEE_NAME` |
| `services.php` | Credenciales third-party (Google, Meta) | `GOOGLE_*`, `META_*` |
| `session.php` | Driver, cookie, lifetime | `SESSION_DRIVER`, `SESSION_*_COOKIE` |
| `taxes.php` | Regímenes default (simple, iva_19, inc_8), tax_included_in_price | `TAX_DEFAULT_REGIME`, `TAX_DEFAULT_RATE`, `TAX_DEFAULT_INCLUDED` |

---

## Variables de entorno relevantes

### Núcleo

| Variable | Default | Propósito |
|----------|---------|-----------|
| `APP_ENV` | local | local/staging/production |
| `APP_KEY` | — | Llave de cifrado Laravel |
| `APP_URL` | http://localhost | URL pública |
| `APP_TIMEZONE` | America/Bogota | Timezone PHP |

### JWT y autenticación

| Variable | Default | Descripción |
|----------|---------|-------------|
| `JWT_SECRET` | — | Clave HS256 |
| `JWT_PAYLOAD_ENCRYPTION_KEY` | — | Clave AES-256-CBC |
| `JWT_TTL` | 21600 | Vida del token (s, 6 h) |
| `JWT_MAX_LIFETIME` | 43200 | Tope absoluto de sesión (s, 12 h) |
| `JWT_REFRESH_TTL` | 20160 | TTL refresh (min) |
| `JWT_BLACKLIST_ENABLED` | true | Habilitar revocación |
| `JWT_COOKIE_NAME` | flexyflow_jwt | Nombre cookie HttpOnly |
| `BOT_JWT_SECRET` | — | Clave para JWT bots |
| `BOT_JWT_TTL` | 3600 | Vida JWT bot (s) |
| `CART_JWT_SECRET` | — | Clave JWT carrito |
| `CART_JWT_TTL` | 4200 | Vida JWT carrito (s, 70 min) |
| `CART_BASE_URL` | https://pedidos.flexyflow.co | URL pública del frontend de carrito |

### OAuth y third-party

| Variable | Descripción |
|----------|-------------|
| `GOOGLE_CLIENT_ID` | OAuth Google |
| `GOOGLE_CLIENT_SECRET` | OAuth Google |
| `GOOGLE_REDIRECT_URI` | Callback Google |
| `META_APP_ID` | App ID flexyflow en Meta (1265007232388204) |
| `META_APP_SECRET` | Secret app Meta |
| `META_BUSINESS_ID` | Business Manager flexyflow |
| `META_SYSTEM_USER_ID` | System User ID |
| `META_SYSTEM_USER_TOKEN` | Token never-expire del System User |
| `META_CONFIG_ID_QA` | Config ID Embedded Signup QA (941660645323511) |
| `META_CONFIG_ID_PDN` | Config ID Embedded Signup prod (2605276259869097) |
| `META_GRAPH_API_VERSION` | v25.0 |
| `META_WEBHOOK_VERIFY_TOKEN_QA` | Token handshake webhook QA |
| `META_WEBHOOK_VERIFY_TOKEN_PDN` | Token handshake webhook prod |

### Facturación

| Variable | Default | Descripción |
|----------|---------|-------------|
| `BILLING_CURRENCY` | COP | Moneda |
| `BILLING_GRACE_MONTHS` | 2 | Meses de gracia |
| `BILLING_DUE_DAY` | 15 | Día de vencimiento |
| `BILLING_GENERATE_DAY` | 20 | Día de generación |
| `BILLING_GENERATE_HOUR` | 3 | Hora de generación (UTC) |
| `BILLING_OVERDUE_DAY` | 16 | Día de marcado overdue |
| `BILLING_OVERDUE_HOUR` | 3 | Hora overdue (UTC) |
| `BILLING_NOTIFY_ON_GENERATE` | true | Email al generar |
| `BILLING_NOTIFY_ON_OVERDUE` | true | Email al marcar overdue |
| `BILLING_DOWNLOAD_TTL` | 3600 | TTL URL firmada PDF (s) |
| `BILLING_STORAGE_DISK` | private | Disco para PDFs de facturas |

### Cache y métricas

| Variable | Default | Descripción |
|----------|---------|-------------|
| `CACHE_STORE` | database | Driver |
| `METRICS_CACHE_TTL` | 60 | TTL base métricas (s) |
| `DASHBOARD_SUMMARY_CACHE_TTL` | 60 | Summary del dashboard (s) |
| `DASHBOARD_CHART_CACHE_TTL` | 300 | Charts (s) |
| `DASHBOARD_HEATMAP_CACHE_TTL` | 600 | Heatmap (s) |
| `DASHBOARD_CACHE_ENABLED` | true | Master toggle de caché del dashboard |
| `COMPANY_SETTINGS_CACHE_TTL` | 3600 | Settings (s) |
| `COMPANY_SETTINGS_CACHE_ENABLED` | true | Toggle |

### Reportes y exports

| Variable | Default | Descripción |
|----------|---------|-------------|
| `REPORT_MAX_DATE_RANGE_DAYS` | 90 | Rango máximo permitido |
| `REPORT_DOWNLOAD_TTL` | 30 | TTL token descarga (min) |
| `PDF_MAX_ROWS` | 500 | Cap de filas PDF |
| `PDF_DRIVER` | dompdf | Motor PDF |

### Domicilios y menú

| Variable | Default | Descripción |
|----------|---------|-------------|
| `DELIVERY_MAX_ACTIVE_PER_COURIER` | 3 | Concurrentes por repartidor |
| `DELIVERY_NOTIFY_ON_ASSIGNMENT` | true | Notif al asignar |
| `DELIVERY_NOTIFY_ON_COMPLETION` | true | Notif al completar |
| `MENU_IMAGE_DISK` | local | Disco de imágenes |
| `MENU_IMAGE_MAX_SIZE_KB` | 2048 | Tamaño máx imagen plato (KB) |

### Cupones

| Variable | Default | Descripción |
|----------|---------|-------------|
| `COUPON_CODE_MAX_LENGTH` | 20 | Longitud máx código |
| `COUPON_MAX_VALUE_PERCENTAGE` | 80 | % máximo de descuento |

### Sesión y seguridad

| Variable | Default | Descripción |
|----------|---------|-------------|
| `SESSION_DRIVER` | database | — |
| `SESSION_LIFETIME` | 120 | min |
| `SESSION_SECURE_COOKIE` | true | HTTPS; obligatorio `true` si `SESSION_SAME_SITE=none` |
| `SESSION_SAME_SITE` | none | `none` en deploy cross-origin (SPA y API en hosts distintos); `JwtService::buildCookie` degrada a `lax` en local sin TLS |
| `SECURITY_HEADERS_ENABLED` | true | Toggle headers |
| `CSP_ENABLED` | true | Content-Security-Policy con nonce |

---

## Auditoría — eventos registrados

`AuditService::log($event, $actor, $model, $metadata, $request)` → tabla `audit_logs`.

| Categoría | Eventos |
|-----------|---------|
| **Auth** | `auth.login`, `auth.logout`, `auth.company.selected`, `auth.company.switched` |
| **Empresa** | `company.created`, `company.updated`, `company.unauthorized_access` |
| **Usuarios** | `user.created`, `user.enrolled`, `user.deleted`, `user.role_changed`, `user.permissions_updated`, `user.status_changed` |
| **Invitaciones** | `invitation.created`, `invitation.accepted`, `invitation.expired` |
| **Roles** | `role.created`, `role.updated`, `role.deleted` |
| **Menú** | `menu.created`, `menu.activated`, `menu.duplicated`, `menu.deleted`, `menu.category_created/updated/deleted`, `menu.item_created/updated/deleted`, `menu.item_availability_changed`, `menu.item_image_uploaded`, `menu.scheduled` |
| **Pedidos** | `order.created`, `order.status_changed` |
| **Domicilios** | `delivery.assigned`, `delivery.completed`, `delivery.reassigned`, `delivery.cancelled` |
| **Cupones** | `coupon.created`, `coupon.updated`, `coupon.status_changed`, `coupon.deleted`, `coupon.redeemed` |
| **Horarios** | `hours.updated`, `hours.exception_created/updated/deleted` |
| **Reportes** | `report.exported` |
| **Facturación** | `invoice.generated`, `invoice.overdue`, `discount.expired` |
| **WhatsApp** | `whatsapp.connected`, `whatsapp.disconnected`, `whatsapp.swap_phone`, `whatsapp.verification_code_sent`, `whatsapp.verification_code_verified`, `whatsapp.verification_rejected` |
| **CRM (#123)** | `client.note_created`, `client.note_deleted` (incluye excerpt 200 chars), `client.tag_added`, `client.tag_removed` |

---

## Caché — claves y TTLs

| Patrón | TTL | Uso |
|--------|-----|-----|
| `company_settings:{nit}` | `COMPANY_SETTINGS_CACHE_TTL` (3600s) | Settings de empresa cacheados |
| `metrics:summary:{nit}:{period}` | `DASHBOARD_SUMMARY_CACHE_TTL` (60s) | KPIs del dashboard |
| `metrics:active_orders:{nit}` | 30s | Pedidos activos del kanban |
| `metrics:heatmap:{nit}:{period}` | `DASHBOARD_HEATMAP_CACHE_TTL` (600s) | Heatmap horario/semanal |
| `metrics:top_items:{nit}:{period}` | `DASHBOARD_CHART_CACHE_TTL` (300s) | Top dishes |
| `metrics:cart_abandonment:{nit}:{period}` | 300s | Tasa de abandono |
| `jwt_blacklist:{signature}` | restante del token | Lista negra de JWTs revocados |
| `report_download:{token}` | `REPORT_DOWNLOAD_TTL` (1800s) | Token de descarga de reporte |
| `chat:{id}:last_read_message_id` | 300s | Throttle de mark-read en chats |
| `crm:list:base:{nit}` | flexible 300s / 1800s | Listado base de clientes (filtros aplicados en memoria) |
| `crm:profile:{nit}:{phone}` | flexible 60s / 300s | Perfil consolidado de un cliente |
| `crm:tags:available:{nit}` | flexible 300s / 1800s | Tags únicos para filtro del CRM |

---

## Endpoints públicos (sin autenticación)

| Método | URL | Descripción |
|--------|-----|-------------|
| GET | `/api/v1/public/menu/{companyNit}` | Menú activo de cualquier empresa por NIT |
| GET | `/api/v1/webhooks/whatsapp` | Handshake (verify_token) Meta |
| POST | `/api/v1/webhooks/whatsapp` | Eventos Meta (validados HMAC) |
| GET | `/api/v1/whatsapp/verification/reject?token=...` | Botón "no fui yo" en correo OTP |
| POST | `/api/v1/csp-report` | Reporte CSP del navegador |

Para `/api/v1/cart/{jwt}` y `migrate-jwt/{jwt}`: técnicamente sin `permission:` ni `company.access`, pero el JWT mismo es la credencial.

---

## Multi-tenancy — patrón de aislamiento

```php
// 1. Middleware EnsureCompanyAccess inyecta active_company_nit
$companyNit = $request->attributes->get('active_company_nit');

// 2. Controllers filtran siempre por NIT
$orders = Order::forCompany($companyNit)
    ->where('status', 'pending')
    ->get();

// 3. forCompany() es un local scope estándar
public function scopeForCompany(Builder $q, string $nit): Builder
{
    return $q->where('company_nit', $nit);
}
```

**No hay scope global automático.** Olvidarse de filtrar por `company_nit` es un bug de seguridad que rompe el aislamiento. Recomendación: siempre usar `forCompany()` antes de cualquier `find/where`.

### Excepciones (por diseño)

- `GET /api/v1/public/menu/{companyNit}` — pasa NIT por URL (uso público).
- `/api/v1/cart/...` — empresa viene del Cart JWT.
- `/api/external/...` — empresa viene del Bot JWT.
- `/api/v1/webhooks/whatsapp` — empresa se resuelve via `phone_number_id → company_whatsapp_accounts.company_nit`.

---

## Aliases de back-compat (302 con `?token=` preservado)

| URL antigua | Redirige a |
|-------------|------------|
| `/caja` | `/orders/cashier` |
| `/deliveries` | `/orders/deliveries` |
| `/roles` | `/identities/roles` |
| `/kanban-board` | (eliminada — pasó a `/orders/board` sin alias) |

---

## Endpoints con OTP (Whatsapp)

Header: `X-Whatsapp-Verification-Code: 123456`.

| Endpoint | Acción | Política adicional |
|----------|--------|---------------------|
| `POST /api/v1/whatsapp/embedded-signup-callback` | connect | `whatsapp.connect` |
| `POST /api/v1/whatsapp/naas-request` | naas request | `whatsapp.connect` |
| `DELETE /api/v1/whatsapp/phone` | swap phone | `whatsapp.swap_phone` + Policy owner |
| `DELETE /api/v1/whatsapp` | disconnect | `whatsapp.disconnect` + Policy owner |

Reglas OTP: 6 dígitos, TTL 10 min, 3 intentos, rate limit 3 códigos/30 min por empresa.

---

## AWS Lambdas auxiliares (infra de costo / auto-shutdown)

Funciones serverless desplegadas vía CloudFormation. **No** son parte del backend Laravel — son tareas operacionales de control de costo en QA. PDN tiene ambas con `Enable*Shutdown=false` por parámetros, por lo que los recursos no se crean (Conditions vacías).

Ambas Lambdas viven en el **mismo stack** `07-shutdown.yaml` (merge de los antiguos 05-auto-shutdown y 07-alb-auto-shutdown) compartiendo un único `IAM Role` con permisos disjuntos.

| Lambda | Stack IaC | Trigger | Frecuencia (qa) | Propósito |
|---|---|---|---|---|
| `flexyflow-panel-{env}-shutdown-ec2` | `aws/iac/cloudformation/stacks/07-shutdown.yaml` | `AWS::Events::Rule` (EventBridge) `rate(${Ec2CheckIntervalMinutes} minutes)` | **15 min** | Si una EC2 del ASG `flexyflow-panel-{env}-asg` lleva > `Ec2ShutdownAfterMinutes` (60) activa, escala `DesiredCapacity=0` y `MinSize=0`. |
| `flexyflow-panel-{env}-shutdown-alb` | `aws/iac/cloudformation/stacks/07-shutdown.yaml` | `AWS::Events::Rule` (EventBridge) `rate(${AlbCheckIntervalMinutes} minutes)` | **15 min** | Lee `/{project}/{env}/alb/started-at` (SSM); si edad > `AlbShutdownAfterMinutes` (60), borra el stack `*-alb` (ALB + Listener + TG). |

### Reglas operacionales

- Frecuencia mínima permitida: **5 min** (ambos `MinValue: 5` en CFN). Por costo no bajar de 15 sin justificación documentada.
- El stack incluye dos `AWS::CloudWatch::Alarm` `*-shutdown-{ec2,alb}-overinvocation` que disparan si `Invocations > 10/h` (umbral fijo). Sirven de canario contra cambios accidentales en el cron.
- Log groups: `/aws/lambda/flexyflow-panel-{env}-shutdown-{ec2,alb}` con retención **7 días**.
- Para volver a encender después de un auto-shutdown:
  - ASG: `aws autoscaling update-auto-scaling-group --auto-scaling-group-name flexyflow-panel-{env}-asg --min-size 1 --desired-capacity 1`.
  - ALB: `aws/iac/scripts/alb-toggle.sh up` o workflow `alb-toggle.yml`.
- Cambiar la frecuencia se hace en `aws/iac/cloudformation/parameters/{env}.json` (`Ec2CheckIntervalMinutes`, `AlbCheckIntervalMinutes`) y reaplicando el stack `shutdown` — **no** editar la regla EventBridge a mano.

### Justificación de la frecuencia 15 min

Con `ShutdownAfterMinutes=60`, un cron de 15 min da resolución 4× dentro del umbral (suficiente para apagar a tiempo) y reduce invocaciones:
- Auto-shutdown ASG: ~144/día → ~96/día (-33%).
- ALB shutdown: ~288/día → ~96/día (-66%).

---

## Backup pre-migration / pre-seeder de la DB (solo pdn)

Antes de cualquier `php artisan migrate --force` o `php artisan db:seed` **en pdn**, se ejecuta un `pg_dump` completo y se sube al bucket privado de backups. **Si el backup falla, la migración no corre.** Es la red de seguridad contra migrations destructivas o seeders mal apuntados.

**En qa NO se hace backup** — el entorno es desechable, hacer pg_dump por cada deploy es overhead sin valor (la BD se puede recrear con seeders). Si necesitás respaldar qa puntualmente, invocar `db-backup.sh --env qa --reason manual` a mano.

### Script
| Archivo | Propósito |
|---------|-----------|
| `aws/ec2/scripts/db-backup.sh` | `pg_dump --format=plain --clean --if-exists --no-owner --no-acl`, gzip, sube a `s3://flexyflow-panel-{env}-backups/db-dumps/{reason}/{ISO}/dump.sql.gz` + `manifest.json`. Reason ∈ `pre-migration | pre-seed | pre-fresh | manual | other`. Reads creds del `.env` de la app. **Aborta con exit != 0 si pg_dump, upload, o bucket access fallan.** El script no se autorestringe a pdn — la guarda vive en los callers. |
| `aws/iac/scripts/backup-signed-url.sh` | Genera presigned URL temporal (default 15 min, max 24h) para descargar un dump del bucket privado sin dar IAM directo al usuario. |

### Puntos de invocación
Cada caller chequea env=pdn antes de invocar. En qa el step se omite.

| Caller | Gate | Antes de | Aborta si backup falla |
|--------|------|----------|------------------------|
| `aws/ec2/scripts/deploy.sh` (manual o si alguien quita `SKIP_MIGRATIONS=1`) | `[ "$APP_ENV" = "pdn" ]` (lee del `.env`) | `php artisan migrate --force` | Sí (`exit 1`) |
| `aws/iac/cloudformation/stacks/06-asg.yaml` UserData (primer boot de la EC2) | `[ "${Environment}" = "pdn" ]` | `php artisan migrate --force` | Sí (UserData tiene `set -euxo`) |
| `.github/workflows/bistro-app-deploy.yml` step "Run migrations (single instance)" | `if [ "$TARGET_ENV" = "pdn" ]` (compone MIGRATE_CMD distinto) | `php artisan migrate --force` via SSM | Sí (SSM command tiene `set -e`) |

### Bucket de destino
- **`flexyflow-panel-{env}-backups`** — separado del bucket `*-documents` (DIAN, 10 años, datos de cliente). **NUNCA mezclar dumps operacionales con info contable del cliente.**
- Privado total: `BlockPublicAcls + BlockPublicPolicy + IgnorePublicAcls + RestrictPublicBuckets`.
- BucketPolicy explícito `DenyInsecureTransport` (sólo HTTPS) y `DenyUnencryptedPut` (sólo AES256). Aplica también a `*-assets` y `*-documents` (defense-in-depth en `03-storage.yaml` y `04-backups.yaml`).
- Acceso únicamente via:
  1. IAM role de la EC2 (`flexyflow-panel-{env}-app-role` + `ManagedPolicy app-backups-access`).
  2. Presigned URL generada por `backup-signed-url.sh` (TTL corto, single-use).

### Path en S3
```
s3://flexyflow-panel-{env}-backups/
├── db-dumps/
│   ├── pre-migration/
│   │   └── 20260512T140000Z/
│   │       ├── dump.sql.gz       # pg_dump comprimido
│   │       └── manifest.json     # env, reason, host, app_version, size
│   ├── pre-seed/...
│   └── _latest/
│       ├── qa.json               # manifest del último backup de qa
│       └── pdn.json              # idem pdn
```

### Restauración manual
```bash
# 1. Descargar el dump via presigned URL (15 min de TTL)
URL=$(./aws/iac/scripts/backup-signed-url.sh \
  --env pdn \
  --key db-dumps/pre-migration/20260512T140000Z/dump.sql.gz)
curl -o dump.sql.gz "$URL"

# 2. Restaurar contra la DB destino (preferir staging clone, NUNCA pdn directo sin aprobación)
gunzip -c dump.sql.gz | psql "postgresql://user:pass@host:5432/dbname"
```

### Dependencias en la EC2
- `postgresql-client` (provee `pg_dump`) instalado por `aws/ec2/install.sh`. Versión 16 en Ubuntu 24.04 — forward-compatible con Supabase Postgres 15.
- `aws` CLI v2 instalado por el bootstrap (UserData en `06-asg.yaml`).
- IAM role `app-role` con la `ManagedPolicy app-backups-access` (declarada en `04-backups.yaml`, se attachea al role del stack `02-security`).

### Lifecycle de los dumps
- Bucket `*-backups` tiene `LifecycleConfiguration.ExpirationInDays = RetentionDays` (qa=30, pdn=180).
- Los dumps viejos se borran automáticamente. No hay transitions a Glacier (los dumps son chicos < 100MB y la retención es corta).

---

## Impresión de comandas (issue #116)

### Tabla
| Tabla | Campos relevantes | Notas |
|-------|-------------------|-------|
| `printers` | `id`, `company_nit` (FK companies.nit cascade), `name`, `type` (kitchen\|bar\|cashier\|customer_receipt), `connection` (usb\|bluetooth\|lan), `address`, `paper_width`, `categories` (JSON), `is_active`, `last_test_at` | Tenancy estricto por `company_nit`; índice (company_nit, type) |

### Configuración
| Archivo | Propósito |
|---------|-----------|
| `config/printing.php` | Fuente única de verdad: tipos, conexiones, anchos, eventos auditables, parámetros del job |

### Modelo / Servicios / Job
| Archivo | Propósito |
|---------|-----------|
| `app/Models/Printer.php` | Eloquent con scopes `forCompany`/`active` y `matchesCategory()` |
| `app/Services/Printing/CommandTicketService.php` | Particiona items por categoría → impresora; despacha jobs; registra ítems huérfanos como warning |
| `app/Services/Printing/EscposTicketBuilder.php` | Genera buffer ESC/POS textual (init/cut/feed sin libs externas) |
| `app/Services/Printing/Drivers/HttpAgentDriver.php` | POST binario al agente local PrintNode-style (timeout configurable) |
| `app/Jobs/PrintCommandTicketJob.php` | `ShouldQueue`, `tries=3`, backoff progresivo; soporta modo test (sin orden real); audita éxito/fallo |

### Endpoints API
| Método | Ruta | Permiso |
|--------|------|---------|
| GET | `/api/v1/company/printers` | `company.update,read` |
| POST | `/api/v1/company/printers` | `company.update,update` |
| PUT | `/api/v1/company/printers/{id}` | `company.update,update` |
| DELETE | `/api/v1/company/printers/{id}` | `company.update,update` |
| POST | `/api/v1/company/printers/{id}/test` | `company.update,update` |

### Eventos auditables
- `printer.created`, `printer.updated`, `printer.deleted`, `printer.tested`
- `order.command_printed`, `order.command_reprinted`, `order.command_print_failed`

### Pendientes (no en este PR)
- Disparador automático en transición de orden a `in_kitchen` (observer/listener).
- Botón "Re-imprimir comanda" en el detalle de orden.
- Cliente WebUSB/WebBluetooth (v2) — actualmente sólo agente HTTP local.

---

## Tipografía de marca FlexyFont en PDFs (issue #109)

`config/dompdf.php` apunta `font_dir`/`font_cache` a `storage_path('fonts')`. dompdf NO puede procesar `@font-face` con paths Windows (`D:\…` lo interpreta como protocolo) — la fuente se pre-instala con un comando artisan que genera las métricas Adobe (`.ufm`) y registra la familia en `installed-fonts.json`.

**Setup (idempotente, ejecutar tras `composer install` en cualquier entorno):**
```
php artisan fonts:install-brand
```
- Comando: `app/Console/Commands/InstallBrandFonts.php`.
- Lee `storage/fonts/FlexyFont.otf` (debe existir, idéntico a `public/fonts/FlexyFont.otf`).
- Genera `flexyfont_{normal,bold,italic,bold_italic}.{otf,ufm}` en `storage/fonts/`.
- Inserta/actualiza la entrada `flexyfont` en `storage/fonts/installed-fonts.json`.

**En las plantillas PDF**:
- Cada `resources/views/pdf/*.blade.php` incluye `@include('pdf.partials._fonts')` dentro del `<head>`. El partial declara únicamente `.font-brand { font-family: 'flexyfont', Arial, sans-serif }` (sin `@font-face` inline — dompdf resuelve por nombre de familia desde `installed-fonts.json`).
- La fuente se aplica solo a: header (nombre comercial), títulos de documento (`Factura`, `Cierre de Caja`, etc.), labels de TOTAL/NETO y footer institucional. Tablas, items, fechas, NITs y montos siguen en Arial/Courier New para máxima legibilidad.

**Verificación**: un PDF generado debe contener `/BaseFont /flexyfont_normal` (y `flexyfont_bold` si hay totales en negrita) embebidos — chequeable con `grep -a BaseFont archivo.pdf`. El binario crece ~3 KB respecto a un PDF en Helvetica puro.

**Troubleshooting**: si los PDFs vuelven a Helvetica, re-ejecutar `php artisan fonts:install-brand`. La causa típica es que `storage/fonts/` se re-creó vacía en deploy y faltan los `.ufm`/`installed-fonts.json`.

---

## Inventario de insumos (issue #111)

Módulo append-only para gestión de stock y costo de insumos. Habilita food cost (#3), compras (#10), recetas (#2) y multibodega (#12).

**Tablas**

| Tabla | Notas |
|-------|-------|
| `ingredients` | id, company_nit (FK companies.nit), name, category nullable, unit (kg\|g\|l\|ml\|un, CHECK), current_stock decimal(12,3), min_stock decimal(12,3), current_cost decimal(12,2), archived_at, timestamps. Único `(company_nit, name)`. CHECKs: `min_stock>=0`, `current_cost>=0`. |
| `ingredient_movements` | Append-only, sin `updated_at`. id, company_nit, ingredient_id, type (entry\|adjustment\|sale_consumption\|waste\|transfer, CHECK), quantity decimal(12,3) **firmada** (+ ingresa, − sale), unit_cost decimal(12,2) nullable (solo `entry`), reference, actor_id (FK users), created_at. Índice `(company_nit, ingredient_id, created_at)`. |

**Modelos**

- `App\Models\Ingredient` — scopes `forCompany`, `active`, `archived`, `lowStock` (excluye `min_stock=0` y archivados); casts decimal:3 / decimal:2.
- `App\Models\IngredientMovement` — `belongsTo(Ingredient,User)`, scopes `forCompany`/`forIngredient`. **Boot guard**: `static::updating` y `static::deleting` lanzan `RuntimeException` — la bitácora es inmutable en runtime. Para corregir, registrar un `adjustment` opuesto.

**Servicio: `App\Services\InventoryService`**

- `recordMovement(Ingredient, type, signedQuantity, ?unitCost, ?reference, ?actor): IngredientMovement`
  - Valida tipo válido y signo coincidente con tipo (`entry` siempre +, `waste`/`sale_consumption` siempre −, `adjustment` ±).
  - `DB::transaction` + `Ingredient::lockForUpdate()` previene carreras al recalcular.
  - `current_stock = current_stock + quantity` (bcadd, 3 decimales).
  - Falla si el resultado dejaría stock negativo o si el insumo está archivado.
  - Solo en `entry`: recalcula `current_cost` por **promedio ponderado** `(stock·cost + qty·unitCost)/(stock+qty)` (bcdiv, 2 decimales). Si stock previo ≤ 0, `new_cost = unit_cost`.
  - Emite `AuditService::log('inventory.movement.recorded', actor, movement, [previous/new stock+cost, type, qty, unit_cost, reference])`.
- `currentStock(Ingredient): string` — recalcula desde `SUM(quantity)` en SQL (verificación de invariante).
- `valuation(companyNit): array` — `SUM(current_stock × current_cost)` por insumo activo + total. SQL puro, nunca iteración.
- Constantes públicas: `TYPE_*`, `VALID_TYPES`, `VALID_UNITS`.

**Controllers**

- `App\Http\Controllers\Api\IngredientController` — `index/store/show/update/destroy/restore/valuation`. `destroy` es soft-archive (`archived_at = now()`). `update` toca SOLO metadatos (nombre, categoría, unidad, mín). Stock/costo NO se mutan aquí.
- `App\Http\Controllers\Api\IngredientMovementController` — `index/entry/waste/adjustment`. Sin update/delete por diseño. `waste` invierte el signo (`'-'.qty`), `adjustment` espera cantidad ya firmada del cliente.

**Form Requests** (`App\Http\Requests\Inventory\*`)

- `StoreIngredientRequest` — `name` único por empresa, `unit` ∈ VALID_UNITS, `initial_stock`/`initial_cost` opcional (si se pasan, se registra entrada inicial).
- `UpdateIngredientRequest` — `sometimes` para todos los campos; `name` único excluyendo el ID actual.
- `RecordEntryRequest` — `quantity>0`, `unit_cost>0`, reference opcional.
- `RecordWasteRequest` — `quantity>0`, `reference` obligatoria (motivo, min:3).
- `RecordAdjustmentRequest` — `quantity != 0` firmada, `reference` obligatoria.

**Endpoints (todos `/api/v1/`, JWT + `company.access`)**

| Método | Ruta | Permiso |
|--------|------|---------|
| GET | `inventory/ingredients` | `inventory.read,read` |
| POST | `inventory/ingredients` | `inventory.create,create` |
| GET | `inventory/ingredients/{id}` | `inventory.read,read` |
| PATCH | `inventory/ingredients/{id}` | `inventory.update,update` |
| DELETE | `inventory/ingredients/{id}` | `inventory.delete,delete` |
| POST | `inventory/ingredients/{id}/restore` | `inventory.update,update` |
| GET | `inventory/ingredients/{id}/movements` | `inventory.read,read` |
| POST | `inventory/ingredients/{id}/movements/entry` | `inventory.create,create` |
| POST | `inventory/ingredients/{id}/movements/waste` | `inventory.create,create` |
| POST | `inventory/ingredients/{id}/movements/adjustment` | `inventory.update,update` |
| GET | `inventory/valuation` | `inventory.read,read` |

**Permisos** (registrados en `FeatureSeeder`, grupo `Inventario`)
`inventory.read`, `inventory.create`, `inventory.update`, `inventory.delete` — los roles `is_system=true` los tienen automáticamente. Otros roles deben recibirlos explícitamente.

**Dashboard banner**
`DashboardController::buildLowStockInventory()` carga top-5 insumos bajo mínimo + conteo total como prop `lowStockInventory` (Inertia::defer). Solo se evalúa si el usuario tiene `inventory.read`; sin permiso retorna `null` y el banner se oculta.

**Eventos auditables**
`inventory.ingredient.created`, `inventory.ingredient.updated`, `inventory.ingredient.archived`, `inventory.ingredient.restored`, `inventory.movement.recorded`.

**Reglas contables aplicadas (CLAUDE.md)**
- Cantidades `decimal(12,3)`, costos `decimal(12,2)` — nunca `float` en cálculos (se usa `bcadd/bcmul/bcdiv`).
- Bitácora append-only respaldada por boot guard + ausencia de endpoints PUT/DELETE.
- Toda mutación bajo `DB::transaction` + `lockForUpdate`.
- Multi-tenant scoping vía `forCompany($nit)`; los endpoints siempre validan que `ingredient.company_nit === active_company_nit` (vía `findOrFail` después del scope).
- AuditLog completo con before/after.

**Verificación manual (en lugar de tests automatizados)**
```bash
php artisan tinker --execute '
  $svc = app(App\Services\InventoryService::class);
  $ing = App\Models\Ingredient::create([...]);
  $svc->recordMovement($ing, "entry", "10", "1000", "ref", $user);
  // verifica stock=10, cost=1000
  // verifica que $movement->update([...]) lance RuntimeException
'
```

---

## Compras a proveedores (issue #118)

Módulo de órdenes de compra con flujo `draft → pending → received → paid` (más
`cancelled` y `voided`). La recepción mueve inventario vía `InventoryService`,
y la anulación post-recepción genera una nota crédito + reverso de inventario.

### Migraciones

| Archivo | Tabla | Notas clave |
|---------|-------|-------------|
| `2026_05_09_180000_create_suppliers_table.php` | `suppliers` | Unique parcial `(company_nit, document_number)` cuando exista; CHECK en `document_type` y `payment_terms_days >= 0`. Soft-archive (`archived_at`). |
| `2026_05_09_180001_create_supplier_ingredients_table.php` | `supplier_ingredients` | Pivote con `last_unit_cost` (NETO) y `last_purchased_at`. Unique `(supplier_id, ingredient_id)`. |
| `2026_05_09_180002_create_purchase_orders_table.php` | `purchase_orders` | Estados `draft|pending|received|paid|cancelled|voided`. Columnas de pago (`payment_method`, `payment_reference`, `paid_date`) + bandera `pending_supplier_refund` para PO `paid` que se anulan. CHECKs en estado, método de pago y montos no-negativos. Unique `(company_nit, code)`. |
| `2026_05_09_180003_create_purchase_order_items_table.php` | `purchase_order_items` | `unit_cost` NETO, `tax_rate`/`tax_amount` desglosados, `line_total` = qty * unit_cost + tax. Snapshot del nombre del insumo en `description`. |
| `2026_05_09_180004_create_purchase_credit_notes_table.php` | `purchase_credit_notes` | Append-only. Guarda `items_snapshot` (JSON) con `reversal_unit_cost` por línea = `current_cost` del insumo al momento de la NC. Numeración `NC-NNNNNN`. |
| `2026_05_09_180005_create_purchase_order_attachments_table.php` | `purchase_order_attachments` | Soft-delete (DIAN 5/10 años). `type ∈ invoice|delivery_note|payment_proof|other`. Storage local `storage/app/purchases/{po_id}/`. |

### Config canónica

`config/purchases.php` — fuente única de verdad. NUNCA hardcodear listas en código.

```php
'statuses'         => ['draft','pending','received','paid','cancelled','voided'],
'transitions'      => [
    'draft'     => ['pending','cancelled'],
    'pending'   => ['received','cancelled'],
    'received'  => ['paid','voided'],
    'paid'      => ['voided'],
    'cancelled' => [],
    'voided'    => [],
],
'payment_methods'      => ['cash','card','transfer'],
'attachment_types'     => ['invoice','delivery_note','payment_proof','other'],
'attachment_mimes'     => ['application/pdf','image/jpeg','image/png'],
'attachment_max_bytes' => 10 * 1024 * 1024,
'code_prefix'          => 'PO-',
'credit_note_prefix'   => 'NC-',
```

### Modelos

| Modelo | Append-only / Boot guard | Casts críticos |
|--------|--------------------------|---------------|
| `Supplier` | scopes `forCompany`, `active`, `archived`. Relaciones `purchaseOrders`, `ingredients` (BelongsToMany). | `archived_at` datetime |
| `SupplierIngredient` | — | `last_unit_cost` decimal:2 |
| `PurchaseOrder` | helpers `isEditable()`, `isTerminal()`. | `subtotal/tax/total` decimal:2; `pending_supplier_refund` bool |
| `PurchaseOrderItem` | **Sí** — `static::updating/deleting` lanzan `RuntimeException` cuando `purchaseOrder.status` ≠ `draft`. Para corregir post-recepción → `PurchaseService::voidWithCreditNote`. | `quantity` decimal:3, costos decimal:2 |
| `PurchaseCreditNote` | **Sí** — append-only puro (no UPDATE/DELETE). | `items_snapshot` array |
| `PurchaseOrderAttachment` | usa `SoftDeletes` — el archivo físico se elimina pero la metadata persiste. | — |

### `App\Services\PurchaseService`

Toda mutación corre en `DB::transaction` + `lockForUpdate` sobre la PO.
Transiciones validadas contra `config('purchases.transitions')`.

| Método | Transición | Notas |
|--------|------------|-------|
| `nextCode($nit)` | — | Genera `PO-NNNNNN` consecutivo por empresa. |
| `nextCreditNoteCode($nit)` | — | Genera `NC-NNNNNN` consecutivo. |
| `createDraft($nit, $supplier, $items, $meta, $actor)` | — → `draft` | Calcula subtotal/tax/total con bcmath. |
| `updateDraft($po, $items, $meta, $actor)` | `draft` → `draft` | Solo permitido en draft. |
| `submit($po, $actor)` | `draft → pending` | Exige al menos una línea. |
| `receive($po, $actor)` | `pending → received` | Por línea: `InventoryService::recordMovement(ENTRY, qty, gross_unit_cost, ref="PO-...", actor)`. `gross_unit_cost = line_total / quantity` (incluye impuesto). Actualiza `supplier_ingredients.last_unit_cost` con el NETO. |
| `markPaid($po, $method, $ref, $actor)` | `received → paid` | `payment_reference` obligatorio para `card|transfer`. |
| `cancel($po, $reason, $actor)` | `draft|pending → cancelled` | Sin impacto en inventario. |
| `voidWithCreditNote($po, $reason, $actor)` | `received|paid → voided` | Crea NC, reversa inventario con `adjustment` negativo al `current_cost` ACTUAL (no al original). Si stock < qty → `ValidationException` (operador debe ajustar primero). Si la PO estaba `paid`, levanta `pending_supplier_refund`. |
| `settleSupplierRefund($po, $ref, $actor)` | — | Limpia `pending_supplier_refund` cuando el proveedor reintegró el dinero. |

### `App\Services\PurchaseAttachmentService`

`store($po, $file, $type, $actor)` valida MIME/tamaño contra config, persiste
en `storage/app/purchases/{po_id}/` y registra `purchases.attachment.uploaded`.
`destroy($attachment, $actor)` borra el archivo físico y soft-deletea la fila.

### Endpoints (todos bajo `/api/v1`)

```
# Proveedores
GET    /suppliers                              suppliers.read
POST   /suppliers                              suppliers.create
GET    /suppliers/{id}                         suppliers.read
PATCH  /suppliers/{id}                         suppliers.update
DELETE /suppliers/{id}                         suppliers.delete (soft-archive)
POST   /suppliers/{id}/restore                 suppliers.update

# Compras
GET    /purchases                              purchases.read
POST   /purchases                              purchases.create
GET    /purchases/{id}                         purchases.read
PATCH  /purchases/{id}                         purchases.update
POST   /purchases/{id}/submit                  purchases.update
POST   /purchases/{id}/receive                 purchases.receive
POST   /purchases/{id}/pay                     purchases.pay
POST   /purchases/{id}/cancel                  purchases.update
POST   /purchases/{id}/void                    purchases.delete
POST   /purchases/{id}/settle-refund           purchases.pay

# Adjuntos
GET    /purchases/{id}/attachments             purchases.read
POST   /purchases/{id}/attachments             purchases.update  (multipart)
GET    /purchases/{id}/attachments/{aid}/download
DELETE /purchases/{id}/attachments/{aid}       purchases.update
```

**Permisos** (registrados en `FeatureSeeder`, grupo `Compras`):

`suppliers.{read,create,update,delete}` + `purchases.{read,create,update,receive,pay,delete}`.

### Política contable y verificación

- Todas las mutaciones financieras envueltas en `DB::transaction` + `lockForUpdate`.
- Auditoría: `purchases.po.{created,updated,submitted,received,paid,cancelled,voided,refund_settled}` + `purchases.supplier.*` + `purchases.attachment.*`.
- `PurchaseOrderItem` y `PurchaseCreditNote` son append-only por boot guard.
- Adjuntos con soft-delete (DIAN exige conservación).
- Nota crédito reversa al **costo corriente del insumo** (no el unit_cost original): el WAC pudo evolucionar entre la recepción y la anulación.
- Si el stock no alcanza para reversar, el sistema **bloquea** la anulación y exige ajuste manual previo.
- Si la PO anulada estaba `paid`, la bandera `pending_supplier_refund` queda hasta que se llame `settle-refund`.

Smoke test (tinker):

```bash
php artisan tinker --execute '
  $sup = \App\Models\Supplier::create(["company_nit" => "...", "name" => "Carnes XYZ"]);
  $ing = \App\Models\Ingredient::first();
  $svc = app(\App\Services\PurchaseService::class);
  $po  = $svc->createDraft($sup->company_nit, $sup, [
      ["ingredient_id" => $ing->id, "quantity" => 5, "unit_cost" => 1000, "tax_rate" => 19],
  ], [], null);
  $po = $svc->submit($po, null);
  $po = $svc->receive($po, null);  // ingredients.current_stock += 5, current_cost recalculado
  $po = $svc->markPaid($po, "transfer", "REF-001", null);
  $po = $svc->voidWithCreditNote($po, "Producto en mal estado", null);
  // verifica: PurchaseCreditNote creada, ingredient.current_stock -=5, pending_supplier_refund=true
'
```

---

## Recetas (BOM) + descuento automático de inventario (issue #112)

Permite asociar a cada `menu_item` una receta — lista de `(ingredient_id, quantity, unit)` — para:

1. Calcular el costo unitario del plato como `SUM(qty_normalizada × ingredient.current_cost)` (reemplaza al campo manual `cost`).
2. Descontar inventario automáticamente al pasar la orden a **`in_kitchen`** vía `InventoryService::recordMovement(TYPE_SALE_CONSUMPTION)`.

### Migraciones

| Archivo | Tabla / Cambio | Notas clave |
|---------|----------------|-------------|
| `2026_05_09_203933_create_recipes_table.php` | `recipes` | `quantity decimal(12,3)`, `unit ∈ kg|g|l|ml|un` (CHECK). FKs a `companies.nit`, `restaurant_menus.id`, `ingredients.id`. **Único parcial** `(company_nit, menu_item_id, ingredient_id) WHERE archived_at IS NULL`. Soft-archive (no DELETE). |
| `2026_05_09_203934_add_inventory_consumed_at_to_orders.php` | `orders.inventory_consumed_at` | Timestamp nullable. Idempotencia del descuento al `in_kitchen`. |

### Config

`config/menu.php` — bloque `recipe`:

```php
'recipe' => [
    'units' => ['kg', 'g', 'l', 'ml', 'un'],            // misma lista que ingredients.unit
    'low_margin_threshold' => 0.20,                      // umbral UI para badge "⚠ bajo"
],
```

`config/orders.php` — bloque `kanban_rank` (regla forward-only del tablero):

```php
'kanban_rank' => [
    'pending'    => 1,
    'in_kitchen' => 2,
    'ready'      => 3,
    'in_transit' => 4,
    'completed'  => 5,
],
```

Una orden solo puede moverse a un estado con `rank ≥ rank_actual`. Volver atrás está prohibido — para corregir, cancelar y crear nueva (trazabilidad DIAN). Estados terminales (`terminal_failure`) bloquean cualquier transición.

### Modelos / Servicios / Helpers

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Models/Recipe.php` | Eloquent + scopes `forCompany`, `active`, `forMenuItem`. `resolveMenuItem()` navega el JSON `RestaurantMenu.structure`. |
| `app/Support/UnitConverter.php` | Conversiones g↔kg, ml↔l, un=un. Lanza `ValidationException` si dimensiones distintas. Bcmath para precisión. |
| `app/Services/RecipeCostService.php` | `compute($companyNit, $menuItemId)` → `{total_cost, breakdown[]}`. Suma con bcmath en 2 dec. |
| `app/Services/InventoryService.php` *(modificado)* | Nuevo parámetro `bool $allowNegativeStock = false` en `recordMovement`. Solo `true` desde `OrderController` para `sale_consumption` — entradas/ajustes manuales mantienen validación dura. |

### Controladores

| Archivo | Cambio |
|---------|--------|
| `app/Http/Controllers/Api/RecipeController.php` *(nuevo)* | `show`, `cost`, `upsert`. Tenant via `active_company_nit`. Pre-valida ingredientes y compatibilidad de unidades antes de tocar DB. Reemplaza set completo en transacción (soft-archive previas + insert nuevas). |
| `app/Http/Controllers/Menu/MenuController.php` | Inyecta `cost = computed (recetas) ?? legacy_manual` en `formatMenuWithImages()` (visible en `show()`). Adiciona campos `cost_source` (`recipe|manual`) y `has_recipe`. |
| `app/Http/Controllers/Api/OrderController.php` | `updateStatus` ahora aplica regla forward-only via `assertForwardOnlyTransition()` y descuenta inventario una vez al pasar a `in_kitchen` (idempotencia con `inventory_consumed_at`). `appendItems` posterior al consumo descuenta solo el delta. Helper privado `consumeInventoryForItems()` agrega cantidades por `menu_item_id`, recorre recetas activas, convierte unidades y registra `sale_consumption` con `allowNegativeStock=true`. **`completed` sin cobro permitido**: el gate que exigía `payment_receipt` antes de `completed` fue removido (abc270d3) — completed = entrega operativa; el cobro es independiente vía `closeWithPayment()`. **Gate `in_transit`**: `updateStatus()` rechaza `in_transit` para órdenes `table`/`pickup` (422) — solo domicilios transitan; antes el gate vivía solo en el drag del frontend y el modal de detalle podía dejar una mesa "en tránsito" (v1.30.3). |

### Endpoints

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| GET | `/api/v1/menus/{menu}/items/{itemId}/recipe` | `menu.read` | Set actual + costo + margen |
| GET | `/api/v1/menus/{menu}/items/{itemId}/cost` | `menu.read` | Solo costo + breakdown |
| PUT | `/api/v1/menus/{menu}/items/{itemId}/recipe` | `menu.update` | Reemplaza set completo |

Sin nuevos permisos (piggyback en `menu.*`). Tenant via `active_company_nit`.

### Política contable / operativa

- **Item sin receta**: NO bloquea la venta. Se registra `inventory.recipe.missing` en AuditLog.
- **Stock negativo en `sale_consumption`**: permitido y auditado (`inventory.sale_consumption.negative_stock`). El operario reconcilia con un ajuste manual posterior.
- **Cancelar/refund** después de `in_kitchen`: NO revierte inventario (el insumo ya fue preparado).
- **Soft-archive vs DELETE**: las filas previas se archivan, nunca se borran; órdenes históricas pueden referenciar la versión vigente al momento de su preparación.
- **Conversión de unidades**: solo entre dimensiones compatibles. Receta puede definirse en `g` aunque el insumo esté en `kg` — `UnitConverter` normaliza en cada movimiento.

### Smoke test

```bash
php artisan tinker --execute '
$company = \App\Models\Company::first();
$menu = \App\Models\RestaurantMenu::forCompany($company->nit)->first();
$itemId = $menu->structure["categories"][0]["items"][0]["id"];
$ing = \App\Models\Ingredient::firstWhere(["company_nit" => $company->nit]);
\App\Models\Recipe::create([
    "company_nit" => $company->nit, "menu_id" => $menu->id,
    "menu_item_id" => $itemId, "ingredient_id" => $ing->id,
    "quantity" => 100, "unit" => "g",
]);
$cost = app(\App\Services\RecipeCostService::class)->compute($company->nit, $itemId);
echo "Costo: {$cost["total_cost"]}\n";
'
```

---

## Food cost automático en tiempo real (issue #113)

Calcula y muestra el **costo de alimentos** del período (gross_cost, cost_ratio, margin_pct) a partir del snapshot inmutable `orders.items[].cost`, persiste un **histórico diario** por ítem y permite alertar cuando el margen cae bajo un umbral configurable por empresa.

### Migración

| Archivo | Tabla / Cambio | Notas clave |
|---------|----------------|-------------|
| `2026_05_09_210950_create_menu_item_cost_history_table.php` | `menu_item_cost_history` | Append-only. Columnas auto-contenidas (`menu_item_name`, `menu_item_category` snapshot eados). FK a `companies.nit` y `restaurant_menus.id`. **Único** `(company_nit, menu_item_id, snapshot_date)` para upsert idempotente. CHECKs: `source ∈ recipe|manual`, `computed_cost ≥ 0`, `char_length(menu_item_id) BETWEEN 1 AND 64` (acepta UUIDs y slugs). |

### Config

`config/company_defaults.php` — nueva clave:

```php
'food_cost_alert_threshold' => [
    'value' => '0.30',  // 30%
    'type' => 'string',  // string para preservar precisión decimal
],
```

Validación: regex `^(0(\.\d{1,2})?|1(\.0{1,2})?)$` (decimal entre 0 y 1, máx 2 decimales).

### Servicios

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Services/FoodCostMetricsService.php` *(nuevo)* | `summary($nit, $period, $from, $to, $limit)` agrega `orders.items` JSON en SQL. **Excluye items con `cost IS NULL` o `cost = 0`** del cost_ratio agregado y los reporta vía `coverage_pct`. `itemHistory($nit, $itemId, $period, $from, $to)` lee snapshots. `ensureTodaySnapshot($nit)` lazy backfill protegido por `Cache::lock` + throttle 6h (resuelve "scheduler no corre"). `generateSnapshotsForCompany($nit, $date)` upsert reusable por cron y backfill. |

### Controlador / Requests / Rutas

| Archivo | Notas |
|---------|-------|
| `app/Http/Controllers/Api/FoodCostController.php` *(nuevo)* | `summary()` dispara `ensureTodaySnapshot()` antes de servir (idempotente). `itemHistory(menuItemId)` para sparkline. Mismo patrón de cache que `MetricsController` (vía service). |
| `app/Http/Requests/Metrics/GetFoodCostSummaryRequest.php` *(nuevo)* | period today\|week\|month\|custom + limit 1..200. |
| `app/Http/Requests/Metrics/GetFoodCostHistoryRequest.php` *(nuevo)* | period (default `month`) + custom dates. |
| `routes/api.php` | `GET /api/v1/metrics/foodcost/summary`, `GET /api/v1/metrics/foodcost/items/{menuItemId}/history` (ambos `permission:reports.read,read`). |
| `routes/web.php` (`company.metrics`) | Resuelve `food_cost_alert_threshold` server-side y lo pasa como prop Inertia para evitar fetch extra desde el cliente. |

### Comando + cron

| Archivo | Notas |
|---------|-------|
| `app/Console/Commands/SnapshotMenuItemCostsCommand.php` *(nuevo)* | `php artisan foodcost:snapshot-daily [--company=NIT] [--date=Y-m-d]`. Itera empresas y delega a `FoodCostMetricsService::generateSnapshotsForCompany`. |
| `routes/console.php` | `Schedule::command('foodcost:snapshot-daily')->dailyAt('04:00')->onOneServer();`. **Si el scheduler no corre**, `ensureTodaySnapshot()` ejecuta el mismo cómputo en demand al primer hit del día (lazy backfill, dedupe por `Cache::lock`). |

### Política contable

- **Fuente del KPI agregado:** snapshot histórico fiel de `orders.items[].cost`. NO se recalcula con receta vigente (eso destruiría la trazabilidad cuando suben precios de proveedor). Si necesitas comparar histórico vs actual, agrega un endpoint adicional sin tocar este.
- **Items sin costo:** items vendidos cuyo snapshot tiene `cost IS NULL` o `cost = 0` se cuentan en `units_sold` pero NO en `gross_cost`/`cost_ratio_pct`. La cobertura se reporta vía `coverage_pct` (`units_with_cost / units_sold`). Esto evita inflar artificialmente el margen.
- **Histórico:** la tabla `menu_item_cost_history` es append-only con upsert; nunca eliminar filas (DIAN: 5–10 años de retención). Para regenerar un día, usar el upsert del comando con `--date=YYYY-MM-DD --company=NIT`.
- **Items eliminados del menú:** el histórico sobrevive porque `menu_item_name` y `menu_item_category` se snapshot ean en cada fila. La UI los marca como `archived: true`.
- **Threshold:** `food_cost_alert_threshold` se almacena como string (`'0.30'`) en `company_settings`, no float, para evitar drift de precisión en la BD.

### Smoke test

```bash
# 1) Crear snapshot manual del día de hoy
php artisan foodcost:snapshot-daily --company=1

# 2) Inspeccionar histórico
php artisan tinker --execute 'echo json_encode(DB::select("SELECT menu_item_name, computed_cost, source FROM menu_item_cost_history WHERE company_nit=? ORDER BY computed_cost DESC LIMIT 5", ["1"]), JSON_PRETTY_PRINT);'

# 3) Probar endpoint summary (requiere JWT + reports.read)
curl -H "Authorization: Bearer <jwt>" "http://localhost/api/v1/metrics/foodcost/summary?period=week"

# 4) Verificar lazy backfill: borrar snapshot de hoy, hit endpoint, verificar
#    que se regeneró sin que corriera el cron
```

---

## Menu engineering: matriz popularidad × margen (issue #114)

Clasifica platos en 4 cuadrantes (estrellas / vacas / puzzles / perros) cruzando popularidad relativa contra margen unitario absoluto en COP. Reusa el snapshot histórico fiel `orders.items[].cost` (mismo origen que food cost #113) — un cambio de receta hoy no altera el matrix de ayer.

| Archivo | Rol |
|---------|-----|
| `app/Services/MenuEngineeringService.php` *(nuevo)* | `matrix($companyNit, $period, $dateFrom, $dateTo, $limit)` agrega `orders.items` por SQL, calcula `popularity_pct` (units / total_units) y `contribution_margin` (avg_price − avg_cost), clasifica en `star\|cow\|puzzle\|dog` usando **mediana** de cada eje como umbral. Items sin costo conocido (`avg_cost = 0\|null`) se excluyen del matrix y se reportan en `summary.unknown` (no entran al cálculo de medianas). Cache `flexible` 5min (mismo TTL que MetricsController). |
| `app/Http/Controllers/Api/MenuEngineeringController.php` *(nuevo)* | `matrix()` requiere `reports.read`. Devuelve `period`, `thresholds`, `summary`, `dishes[]`. |
| `app/Http/Requests/Metrics/GetMenuEngineeringRequest.php` *(nuevo)* | period today\|week\|month\|custom (default `month`) + limit 1..200. |
| `routes/api.php` | `GET /api/v1/metrics/menu-engineering` (`permission:reports.read,read`). |

### Política contable
- **Sin cálculo on-the-fly de receta**: el eje Y usa `avg(item->>'cost')` del JSON de órdenes (snapshot fiel del momento de la venta). Esto preserva la regla CLAUDE.md "una vez cerrado un período, recálculos posteriores deben dar el mismo número".
- **Mediana como umbral**: simple, robusta a outliers, no requiere setup por empresa. Se documenta en el docblock del service. Si en el futuro se quiere override por empresa, agregar columna en `company_settings` (no implementado en esta iteración).
- **Items sin costo no inflan el resultado**: las medianas se calculan solo sobre `classifiable`. La UI muestra cobertura para que el dueño sepa cuántos platos no se están midiendo.

### Smoke test
```bash
# 1) Verificar ruta
php artisan route:list --path=menu-engineering

# 2) Probar service
php artisan tinker --execute 'echo json_encode(app(App\Services\MenuEngineeringService::class)->matrix("1", "month", null, null, 200), JSON_PRETTY_PRINT);'

# 3) Probar endpoint
curl -H "Authorization: Bearer <jwt>" "http://localhost/api/v1/metrics/menu-engineering?period=month"
```

---

## Documentación complementaria

| Recurso | Ubicación | Propósito |
|---------|-----------|-----------|
| Wiki técnico | `../docs/wiki/` (raíz del repo) | Markdown con: Autenticación, Empresas, Usuarios-Roles-Permisos, Menú, Pedidos, Domicilios, Cupones, Horarios, Dashboard-Métricas, WhatsApp-Bot, Facturación, Variables-de-Entorno, Errores-API, Guía-de-Contribución |
| Frontend wiki | `../docs/wiki/Frontend.md` | Convenciones, patrones (Inertia v2, formularios, polling, permisos) |
| AWS provisioning | `../aws/ec2/install.sh`, `../aws/ec2/scripts/{deploy,healthcheck}.sh` | Bootstrap idempotente EC2 + deploy |
| Guías visuales | `FRONTEND_UI_GUIDELINES.md` | Paleta, espaciados, ejemplos UI |
| Skills locales | `**/skills/**` | Domain-specific Claude skills (laravel-best-practices, pest-testing, inertia-react-development, ...) |

Cada PR debe actualizar la página correspondiente del wiki cuando modifique endpoints, permisos, variables `.env` o errores de aplicación.

---

## PWA — Web App Manifest dinámico (issue #103 — Fase 1)

### Endpoints

| Método | Ruta | Controlador | Auth | Propósito |
|---|---|---|---|---|
| `GET` | `/manifest.webmanifest` (`pwa.manifest`) | `App\Http\Controllers\PwaManifestController@show` | Pública (lee JWT cookie si está) | Sirve el Web App Manifest. Si hay JWT válido con `active_company_nit`, el manifest usa `commercial_name` y `menu_primary_color` de esa empresa; si no, devuelve branding flexyflow por defecto. |
| `GET` | `/sw.js` (`pwa.sw`) | `App\Http\Controllers\PwaManifestController@serviceWorker` | Pública | Sirve el Service Worker generado por Workbox (`public/build/sw.js`) desde la raíz para que el `scope` por defecto sea `/`. Reescribe URLs internas a `/build/...`. |

### Comportamiento

- Resolución del JWT: `JwtService::extractTokenFromRequest()` (cookie HttpOnly > Authorization > session > query).
- Si `verify()` lanza `RuntimeException`, el controlador cae al manifest por defecto sin propagar el error.
- Branding por empresa: `CompanySettingsService::get($nit, 'menu_primary_color', '#FF6B35')`.
- Headers manifest: `Content-Type: application/manifest+json; charset=UTF-8`, `Cache-Control: private, max-age=300`.
- Headers SW: `Content-Type: application/javascript; charset=UTF-8`, `Service-Worker-Allowed: /`, `Cache-Control: public, max-age=0, must-revalidate`.
- Fallback estático sin lógica: `public/manifest.webmanifest` (flexyflow brand). Sirve como red de seguridad si la ruta dinámica falla.

### Iconos servidos

`public/icons/icon-{192,512}.png`, `icon-{192,512}-maskable.png`, `apple-touch-icon-180.png`, `public/favicon.{ico,svg}`. **Fuente única: `bistro/branding/`** — el backend hereda las copias corriendo `node branding/sync.mjs` (ver `bistro/branding/README.md`); no editar los assets en `public/` a mano. El default es la "b" de la FlexyFont sobre `#1E232E`. Las páginas de error (`resources/views/errors/layout.blade.php`) referencian `/favicon.svg` para que un endpoint API alcanzado por accidente muestre la marca, no el default de Laravel. Los iconos por-empresa se siguen rasterizando en runtime vía `LogoIconRasterizer`.

### Notas

- La Fase 2 (offline + sync) está implementada en el bloque siguiente.

---

## PWA — Fase 2: Modo offline real (issue #140)

### Endpoints

| Método | Ruta | Controlador | Auth | Propósito |
|---|---|---|---|---|
| `POST` | `/api/v1/orders/sync-batch` (`api.orders.syncBatch`) | `App\Http\Controllers\Api\OrderSyncController@syncBatch` | JWT + `orders.create` | Sincroniza un batch de órdenes/cobros offline idempotentemente por `client_uuid`. Multitenant estricto (rechaza si `company_nit` ≠ JWT activo). |
| `GET` | `/api/v1/metrics/offline/operation` (`api.metrics.offline.operation`) | `App\Http\Controllers\Api\MetricsController@offlineOperation` | JWT + `reports.read` | Agrega `offline_sync_events` por período. Devuelve totales (orders_synced, receipts_synced, amount_synced, failed) + serie diaria. |
| `GET` | `/apple-touch-icon.png` (`pwa.apple-touch-icon`) | `App\Http\Controllers\PwaManifestController@appleTouchIcon` | Pública | Sirve el apple-touch-icon de la empresa activa (rasterizado desde su logo) o el flexyflow black-font por defecto. |

### Política contable

- **Receipts inmutables (CLAUDE.md)**: la idempotencia por `client_uuid` evita doble cobro sin necesidad de `UPDATE`. Si llega un duplicado, el server hace `lockForUpdate` sobre el receipt existente y devuelve su `server_id` sin generar refund.
- Cada inserción real (no idempotencia hits) registra `order.synced_offline` en `audit_logs` con: `client_uuid`, `warnings`, `receipt_created`, `total`.
- `payment_receipts.payment_data.synced_offline = true` marca los cobros que entraron por sync (auditable).
- `paid_at` viene del cliente offline; el server lo confía pero flagea `sync_warning.clock_skew` si difiere por >24h del server.
- Conflictos (item ahora `unavailable`, etc.) NO rechazan: la orden se persiste con `sync_warnings` y queda flagged para revisión.

### Cierre de caja con pendientes

- `CashRegisterService::closeSession($pendingSyncCount)` lanza `ValidationException` si `pendingSyncCount > 0`. Política: bloqueo duro sin escotilla.
- `CashRegisterController::close` valida `pending_sync_count` (int) del payload y lo pasa al service.

### Servicios y modelos

- `App\Services\LogoIconRasterizer` — rasteriza logo de empresa a 5 PNGs (192/512/maskable/apple-touch-180). Driver: GD via `Intervention\Image\ImageManager`.
- `App\Models\OfflineSyncEvent` — bitácora append-only de sync. Inmutable. Casts: `count:int`, `total_amount:decimal:2`, `metadata:array`, `occurred_at:datetime`.
- `App\Console\Commands\Pwa\RasterizeCompanyLogos` — `php artisan pwa:rasterize-logos [--nit=...]` regenera iconos en bulk.

### Migraciones

- `2026_05_09_222014_add_client_uuid_to_orders_table` — `orders.client_uuid uuid nullable` + partial unique index `(company_nit, client_uuid) WHERE client_uuid IS NOT NULL` (PostgreSQL DDL crudo).
- `2026_05_09_222015_add_client_uuid_to_payment_receipts_table` — análogo en `payment_receipts`, unique parcial.
- `2026_05_09_222016_add_sync_warnings_to_orders_table` — `orders.sync_warnings jsonb nullable`.
- `2026_05_09_222016_create_offline_sync_events_table` — bitácora con FK a `companies`, `users` (nullOnDelete) e índices `(company_nit, occurred_at)` y `(company_nit, event_type, occurred_at)`.

### Dependencias agregadas

- `intervention/image ^3.11` (Composer) — registrado como singleton `ImageManager` con driver GD en `App\Providers\AppServiceProvider::register`.

## Escalado horizontal multi-EC2 (issue #43)

### Decisiones de arquitectura

- **DB compartida:** Supabase managed PostgreSQL. Local: Postgres en Docker.
- **Session/cache/queue:** todos `database` driver → tablas en Supabase compartidas entre nodos.
- **Coordinación de schedulers:** `cache_locks` (vía `->onOneServer()`).
- **Storage:** S3 (`flexyflow-panel-{env}-assets` público, `*-documents` privado). Local: MinIO en `docker/`.
- **Auth cross-node:** JWT cifrado AES-256 + HMAC (cookie `flexyflow_jwt`) — sin sticky sessions.
- **APP_KEY:** GitHub Secret propagado a todas las EC2 al deploy.

### Discos de filesystem

`config/filesystems.php`:

| Disk | Visibility | Uso | Env |
|------|-----------|-----|-----|
| `local` | private | dev sin docker (`storage/app/private`) | default |
| `public` | public | dev sin docker (`storage/app/public`) | legacy |
| `s3` | public | assets en prod / MinIO local | `AWS_BUCKET` |
| `s3_documents` | private | factura, reportes, adjuntos compras (DIAN 10 años) | `AWS_BUCKET_DOCUMENTS` |

Selección por dominio (todas tienen fallback a `local`/`public` para dev sin docker):

- `MENU_IMAGE_DISK` → `config('menu.image_disk')` → `MenuController` via `RestaurantMenu::getConfiguredDisk()`
- `INVOICE_STORAGE_DISK` → `config('billing.storage_disk')` → `BillingController`, `Api/BillingController`, `InvoicePdfService`
- `REPORT_STORAGE_DISK` → `config('reports.storage_disk')` → `OrderReportController`, `GenerateReportPdf`
- `PURCHASE_ATTACHMENT_DISK` → `config('purchases.attachment_disk')` → `PurchaseAttachmentService`
- `FILESYSTEM_DISK` (default) → `config('filesystems.default')` → `CompanyController` (logo, QR), `ChatMessageResource`, `PwaManifestController`, `LogoIconRasterizer`, `DownloadWhatsappMediaJob`, `PurgeOldChats`

### Health checks

- `GET /health/live` → 200 siempre (liveness simple, no I/O).
- `GET /health/ready` → 200 si DB + S3 OK, 503 si alguno falla. Es el endpoint que consulta el ALB (`HealthCheckPath=/health/ready` en `qa.json`/`pdn.json`).
- `GET /health` (Nginx estático) sigue funcionando como fallback si PHP-FPM cae.
- `GET /up` (Laravel built-in) declarado en `bootstrap/app.php:25`.

Controller: `App\Http\Controllers\HealthController`.

### Migraciones aplicadas

- `aws/ec2/scripts/deploy.sh` ya NO corre `php artisan migrate --force` (warning si SKIP_MIGRATIONS no está set).
- Workflow `.github/workflows/bistro-app-deploy.yml` corre el migrate en un step previo (`Run migrations (single instance)`) contra una sola EC2 InService, vía SSM, con timeout 600s. Si falla, aborta antes de tocar el resto de nodos.
- `SKIP_MIGRATIONS=1` se setea automáticamente en el comando de deploy general.

### Scheduler y guard anti-duplicación

Todos los `Schedule::*` usan `->onOneServer()`. Bloqueante anti-regresión:
- `tests/Feature/Architecture/SchedulerOnOneServerTest.php` falla en CI si algún schedule no tiene el flag.

Canary: `php artisan healthcheck:heartbeat` cada minuto loguea `host + timestamp` a CloudWatch Logs. Con `N>=2` nodos debe aparecer 1 entrada/minuto.

### Guard anti-fuga DIAN

- `tests/Feature/Architecture/PrivateStorageUrlTest.php` falla en CI si `BillingController`, `Api/BillingController`, `InvoicePdfService`, `PurchaseAttachmentService`, `OrderReportController` o `GenerateReportPdf` usan `Storage::url()` o `$disk->url()` (URL pública permanente).
- `PurgeOldChats::handle()` aborta si `FILESYSTEM_DISK=s3_documents` (no debe tocar el bucket de facturas).
- `03-storage.yaml` mantiene `DocumentsRetentionDays: 0` en pdn (nunca borrar).

### Mantenimiento DB (pg_cron en Supabase)

Tareas a ejecutar manualmente en Supabase SQL Editor (post-merge de #43):

```sql
SELECT cron.schedule('flexyflow_purge_sessions', '0 * * * *',
  $$DELETE FROM sessions WHERE last_activity < extract(epoch FROM now() - interval '2 hours')$$);
SELECT cron.schedule('flexyflow_purge_cache', '0 * * * *',
  $$DELETE FROM cache WHERE expiration < extract(epoch FROM now())$$);
SELECT cron.schedule('flexyflow_purge_cache_locks', '*/15 * * * *',
  $$DELETE FROM cache_locks WHERE expiration < extract(epoch FROM now())$$);
```

Las tablas `sessions` y `cache` quedan **UNLOGGED** (migración `2026_05_11_174023_set_unlogged_sessions_and_cache`) para reducir WAL I/O — son regenerables.

### Dev local (Docker)

`docker/docker-compose.yml`:
- `db` (postgres:15-alpine) — equivalente a Supabase en prod.
- `minio` (S3-compatible) — equivalente a S3 buckets en prod.
- `minio-bootstrap` (minio/mc) — crea buckets `flexyflow-panel-local-{assets,documents}`, marca el de assets como público, sube objetos `.health`.

Detalles y env vars: `docker/README.md`.

---

## Enrolamiento — Verificación de propiedad (#154)

### Flujo de captura

`CompanyEnrollmentController::store` (POST `/api/v1/enrollment/company`) recibe el
documento de propiedad (`proof_document`) como **campo obligatorio** del
`CompanyEnrollmentRequest`. Formatos aceptados:

- PDF (`application/pdf`)
- Word: `.doc` (`application/msword`), `.docx` (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
- Imagen: `.jpg`, `.jpeg`, `.png`

La regla de validación usa `mimetypes:` (no `mimes:`) para verificar el MIME
real del contenido del archivo, no la extensión. Tamaño máximo: **10 MB**.

`EnrollmentProofService::store` se invoca dentro de la misma `DB::transaction`
del controller — un fallo persistiendo `enrollment_proofs` revierte la
creación de la empresa, sus roles y la sede default.

### Persistencia

| Tabla | Columnas relevantes | Notas |
|-------|---------------------|-------|
| `enrollment_proofs` | `id`, `company_nit` (UNIQUE), `disk`, `s3_key`, `mime_type`, `file_size`, `original_filename`, `uploaded_by_user_id`, `uploaded_at`, `created_at/updated_at/deleted_at` | 1:1 con `companies`. Soft-deletes obligatorios (DIAN 5-10 años). El archivo vive en S3 — esta fila sólo localiza y audita. |

Archivo en S3 con disk `s3_documents` (privado, `AWS_BUCKET_DOCUMENTS`), key
`enrollment-proofs/{company_nit}/{timestamp}-{slug}.{ext}`. Cifrado SSE-S3 por
defecto del bucket.

### Estados canónicos de empresa (`config/companies.php`)

- `pending_activation` — default tras enrolamiento. Estado existente reutilizado.
- `verified` — única que habilita uso pleno de la plataforma.
- `rejected` — verificación externa marcó la empresa como inválida.
- `active`, `inactive`, `mora`, `delinquent`, `suspended` — preservados por
  compatibilidad con empresas creadas antes de #154.

Claves expuestas:
- `companies.verified` = `['verified', 'active']` → estados que habilitan operación.
- `companies.allowed_transitions` → matriz que el workflow operativo respeta.
- `companies.labels` → traducciones para UI.

### Gate del backend

El middleware `EnsureCompanyVerified` (alias `company.verified`) aplica en
`routes/api.php` como segundo gate después de `company.access`, sobre todas
las rutas de mutación. **Excepciones explícitas** (siguen accesibles a una
empresa no verificada):

- `GET /api/v1/companies/active` — lectura del estado activo para que el
  frontend muestre la pantalla "Cuenta en revisión".
- `GET /api/v1/enrollment/proof/preview` — el dueño puede revisar el
  documento que adjuntó.

### Endpoint de preview

`GET /api/v1/enrollment/proof/preview` (`EnrollmentProofController@preview`)
devuelve una URL firmada de S3 con TTL 15 min al uploader o a un miembro con
rol `is_system=true`. 403 a cualquier otro miembro.

### Ops — cambio de estado por GitHub Action

**Workflow:** `.github/workflows/company-ops.yml` (action `change_status`) +
SQL parametrizado en `.github/sql/company-status.sql`.

**Tipo:** `workflow_dispatch`. Sólo dispara manualmente.

**Inputs:**

| Input | Tipo | Notas |
|-------|------|-------|
| `environment` | choice | `qa` \| `pdn`. Hace binding al GitHub Environment homónimo. |
| `nit` | string | Sanitizado: regex `^[0-9-]{6,20}$`. |
| `status` | choice | `verified` \| `rejected` \| `pending_activation`. |
| `reason` | string | Obligatorio. Longitud 5-500. Va al `data.reason` del audit. |
| `actor` | string | Quién autoriza. Si vacío, usa `github-actions:${github.actor}`. |

**Conexión:** psql directo a Supabase, TLS obligatorio (`sslmode=require`).
Credenciales: `vars.DB_HOST`, `vars.DB_PORT`, `vars.DB_DATABASE`,
`vars.DB_USERNAME`, `secrets.DB_PASSWORD` del environment seleccionado.

**Transacción atómica** (ver `.github/sql/company-status.sql`):

1. `SELECT id, status FROM companies WHERE nit = :nit FOR UPDATE`.
2. Valida `status` destino en whitelist y transición permitida
   (`pending_activation → verified|rejected`, `rejected → pending_activation`).
3. Si `from == to`: emite `OUTCOME=no_op`, no toca BD, no audita.
4. Si transición válida: `UPDATE companies SET status, updated_at` + `INSERT
   audit_logs (user_id=NULL, action='company.status_changed_external',
   data=json_build_object('from', 'to', 'source'='github_action',
   'workflow_run_id', 'workflow_run_url', 'actor_label', 'reason',
   'github_actor', 'company_nit'), ...)`.
5. `COMMIT`. Rollback automático ante cualquier `RAISE EXCEPTION`.

**Concurrency:** `company-status-${environment}-${nit}` evita carreras sobre
la misma empresa.

**Seguridad recomendada:** activar **Required reviewers** en el GitHub
Environment `pdn` (Settings → Environments → pdn) para forzar aprobación
humana antes de ejecutar contra producción.

**Auditoría manual de un cambio:**

```sql
SELECT created_at, data->>'from' AS from, data->>'to' AS to,
       data->>'actor_label' AS actor, data->>'reason' AS reason,
       data->>'workflow_run_url' AS run_url
  FROM audit_logs
 WHERE action = 'company.status_changed_external'
   AND data->>'company_nit' = '<NIT>'
 ORDER BY created_at DESC;
```

---

## Colaboradores y planificador de turnos (#182)

### Tablas

- `employee_positions` — catálogo de cargos. `is_system=true` + `company_nit=null`
  para los 7 cargos canónicos (waiter, cook, cashier, bar, manager, host,
  cleaning). Custom por empresa con `is_system=false`. UNIQUE
  `(company_nit, slug)`.
- `employees` — perfil HHRR. UUID PK, `user_id` nullable (bigInt FK a
  `users`). UNIQUE `(company_nit, doc_number)` y `(company_nit, email)`.
  Columnas monetarias `decimal(12,2)`. `vinculation_status` enum cerrado.
  Soft-archive vía `archived_at`.
- `employees_branches` — pivote para sedes auxiliares. La sede principal
  vive en `employees.primary_branch_id`.
- `employee_shifts` — turno asignado. `starts_at`/`ends_at` son timestamps
  para soportar turnos partidos + cruce de medianoche. Soft-cancel mantiene
  fila. CHECK `ends_at > starts_at` en Postgres.
- `company_workforce_settings` — 1:1 con `companies`. `max_weekly_hours`
  (48 default), `min_days_off_per_week` (1 default),
  `hours_warning_mode` (warn|block|off).

### Modelos Eloquent

- `App\Models\Employee` — casts decimal:2 en `pay_rate`/`base_salary`;
  relaciones `position`, `primaryBranch`, `extraBranches`, `shifts`, `user`.
- `App\Models\EmployeeShift` — casts datetime en `starts_at`/`ends_at`/
  `cancelled_at`. Scopes `scheduled()`, `between($from, $to)`.
- `App\Models\EmployeePosition` — relación `employees`. `is_system` se
  protege en `EmployeePositionController::destroy`.
- `App\Models\CompanyWorkforceSetting` — primary key string (NIT),
  `incrementing=false`.

### Servicios

- `App\Services\ShiftActiveGuardService` — valida turno activo para
  apertura/cierre de caja. **Propietario** y **Administrador** bypasean por
  responsabilidad supervisoria (cobertura, emergencias, auditoría in-situ).
  El rol Empleado y los roles custom (Cocina, Domiciliario, etc.) sí
  requieren turno activo. Filtra por nombre del rol además de `is_system`,
  porque los 3 roles canónicos son `is_system=true`. Lanza
  `AuthorizationException` con mensaje `"No tienes turno activo en esta sede
  a esta hora."`.
- `App\Services\Shifts\ShiftSuggestionService` — algoritmo greedy de
  asignación equitativa. Invariante: minimiza desviación estándar de horas
  por empleado en la semana. Restricciones duras: empleado activo, sede
  principal, sin solapamiento. Restricciones suaves: máx semanal, mín días
  libres (devueltas como `warnings`).

### Policy

- `App\Policies\EmployeeVinculationPolicy::denialReason($actor, $target,
  $newStatus)` retorna `REASON_SELF` / `REASON_TARGET_IS_OWNER` /
  `REASON_ADMIN_CANNOT_DEMOTE_OWNER` o `null` si permitido. El controller
  audita `employee.vinculation_change_denied` con el motivo.

### Endpoints (v1)

```
GET    /api/v1/employees                          listar (filtros y paginación)
POST   /api/v1/employees                          crear (intenta match user por email)
GET    /api/v1/employees/{id}                     detalle
PUT    /api/v1/employees/{id}                     editar (audita diff)
POST   /api/v1/employees/{id}/archive             soft-archive + cancela turnos futuros
POST   /api/v1/employees/{id}/vinculation-state   cambia estado + cascada turnos
GET    /api/v1/employees/{id}/salary              revela pay_rate (audita)

GET    /api/v1/employee-positions                 catálogo combinado (sistema + empresa)
POST   /api/v1/employee-positions                 custom (is_system=false)
DELETE /api/v1/employee-positions/{id}            solo no-system

GET    /api/v1/shifts                             ?from&to&branch_id&employee_id
POST   /api/v1/shifts                             crea con lockForUpdate sobre employee
PUT    /api/v1/shifts/{id}
POST   /api/v1/shifts/{id}/cancel
POST   /api/v1/shifts/suggest                     borrador equitativo

GET    /api/v1/me/shifts                          agenda del colaborador
GET    /api/v1/me/profile                         perfil + salario enmascarado
GET    /api/v1/me/salary                          destapa salario propio (audita)

GET    /api/v1/workforce-settings
PUT    /api/v1/workforce-settings

GET    /api/v1/reports/workforce                  JSON
GET    /api/v1/reports/workforce.csv              CSV con BOM UTF-8
GET    /api/v1/reports/workforce.pdf              PDF (blade pdf.workforce-report)
```

### Seeders

- `FeatureSeeder` — registra las 10 features nuevas en grupos
  Colaboradores / Planificador / Reportes.
- `PermissionTemplateSeeder` — `shifts.read` se otorga al template `employee`
  para que aparezca en el permissions del JWT y `/me/agenda` quede habilitado.
- `EmployeePositionSeeder` — siembra los 7 cargos del sistema (idempotente).
- `EmployeesFeatureBackfillSeeder` — proyecta los permisos sobre los roles
  del sistema (`is_system=true`) de empresas existentes. Idempotente con
  `firstOrCreate` por `(company_role_id, feature_id)`.
- `WorkforceSettingsBackfillSeeder` — crea filas default en
  `company_workforce_settings` para empresas existentes (idempotente).
- Empresas nuevas reciben workforce_settings en `CompanyEnrollmentController`.

### Auditoría — acciones nuevas

- `employee.created`, `employee.updated`, `employee.archived`
- `employee.vinculation_changed`, `employee.vinculation_change_denied`
- `employee.salary_viewed`, `employee.salary_viewed_self`
- `employee.linked_to_user` (cuando el match user-email enlaza tras enrollment)
- `employee_position.created`, `employee_position.deleted`
- `shift.created`, `shift.updated`, `shift.cancelled`
- `shift.bulk_cancelled_by_state`, `shift.suggested`
- `workforce.settings_updated`

### Integración con módulo de Caja

`CashRegisterController::open()` y `close()` invocan
`$this->shiftGuard->assertActiveShift($user, $companyNit, $branchId)`. Owners
bypasean; el resto necesita un `employee_shift` `scheduled` en la sede
actual cuya ventana contenga `NOW()`. Si falla → 403 vía
`AuthorizationException`.

### Enrollment

- `UserEnrollmentController::linkExistingEmployees($user)` — al completar
  enrollment, busca employees no enlazados con el mismo email en empresas
  donde el user es miembro, y enlaza `user_id`.
- `CompanyEnrollmentController` — crea fila default en
  `company_workforce_settings` dentro de la misma transacción.

---

## HU #200 — Sanitización transversal de inputs

> Capa centralizada para que persistir texto sucio sea imposible sin saltarse a propósito. Política completa en `docs/wiki/SECURITY_INPUT_HANDLING.md`.

### Reglas custom (`app/Rules/`)

- `NoControlCharacters` — bloquea U+0000–U+001F (control), U+007F (DEL),
  U+202A–U+202E (bidi overrides). Acepta `\t`/`\n` con
  `allowWhitespace: true`.
- `SafePlainText` — compone `NoControlCharacters` + cap por **bytes** +
  helper `static sanitize($value, $allowWhitespace)` que aplica
  `strip_tags` + NFC + trim.

### Trait `App\Http\Requests\Concerns\SanitizesInput`

Hook por default en `prepareForValidation()` que aplica saneamiento
según el mapa declarado en la FormRequest:

```php
protected array $sanitize = [
    'body' => 'plain_text_long',
    'name' => 'plain_text_short',
];
```

Categorías: `plain_text_short`, `plain_text_long`, `markdown_trusted`,
`identifier`, `json_payload`. FormRequests con su propio
`prepareForValidation` invocan `$this->sanitizeMappedFields()`.

### Middleware `App\Http\Middleware\NormalizeStrings`

Normalización Unicode NFC sobre todos los strings del payload.
Registrado en `bootstrap/app.php` como prepend de `web` y `api`.
Whitelist: `api/v1/webhooks/whatsapp/*`, `api/v1/csp-report`,
`csp-report` (firmas byte-exact).

### Hardening aplicado

10 FormRequests críticos (Chat, Clients, Deliveries, Menu*, Branch,
Company, Coupons, Profile) + 17 controllers con `validate()` inline
migrados a `SafePlainText` inline (sin crear FormRequests nuevas, para
mantener PR contenido). Cobertura: ~40 campos de texto libre del
proyecto.

### Migración one-off

`2026_05_18_161716_sanitize_existing_freetext` — saneamiento de data
histórica de 7 columnas críticas (chat_messages.body, order_items.notes,
order_notes.body, client_notes.note, cart_items.notes,
delivery_status_logs.reason, branches.address). Idempotente, batched
500, registra cada fila tocada en `audit_logs` con
`action='sanitize.migrated'` (hash SHA-256 before/after).

### Render seguro

- `EscposTicketBuilder` — método privado `sanitizePrintable()` filtra
  bytes ESC/POS (`\x1B`/`\x1D` y demás control chars) de texto del
  cliente (`item.name`, `item.notes`, `order.table_number`). Sin esto
  un payload podía abrir cajón monedero o cortar papel.
- `routes/api.php` `/api/v1/csp-report` — además del log textual,
  persiste cada violación en `audit_logs` con `action='csp.violation'`
  para dashboards de seguridad.

### Config CSP

`config/app.php` mantiene los defaults `security_headers_enabled=false`
y `csp_enabled=false`. `.env.example` documenta el rollout gradual:
QA primero via GH Environment vars + 7 días de monitoreo, luego flip
en PDN. No se modifica QA en este PR.

## HU #149 — Web Push notifications (PWA)

**Catálogo canónico de tipos / payloads / browser matrix**:
[`bistro/backend/constants/NOTIFICATIONS.md`](../../bistro/backend/constants/NOTIFICATIONS.md)
y guía operativa en
[`docs/wiki/PWA-Push-Notifications.md`](./PWA-Push-Notifications.md).

### Tablas

- `push_subscriptions` (migración `2026_05_19_173435_create_push_subscriptions_table.php`):
  - `user_id` (FK users), `company_nit`, `branch_id` (nullable — cross-branch),
    `endpoint` (text), `p256dh`, `auth`, `user_agent`, `last_seen_at`, `revoked_at`.
  - Unique partial PostgreSQL `(user_id, MD5(endpoint)) WHERE revoked_at IS NULL`.

### Modelos

- `App\Models\PushSubscription` — sin `BranchScope` (sub es por user, no por sede).
  Scopes `active()` / `revoked()`. Helpers `isActive()`.

### Eventos / Listeners

- `App\Events\OrderItemSubmittedForApproval(OrderItem $item)` — disparado
  en `TableOrderService::addItem` después de persistir el item con
  `status='pending_approval'`.
- `App\Listeners\NotifyPendingApprovalListener` (`ShouldQueue`) → encola
  `SendPendingApprovalPushJob`. Registrado explícitamente en
  `AppServiceProvider::boot`.

### Jobs (queue `notifications`)

- `App\Jobs\SendPendingApprovalPushJob(int $orderItemId)` — push inicial.
- `App\Jobs\SendPendingApprovalReminderPushJob(int $orderItemId)` — push
  con minutos transcurridos; reusa el `tag` para colapsar.
- `App\Jobs\SendInventoryDigestPushJob(int $userId, string $companyNit)`
  — digest del día con count de `alert_events` activos.

### Service

- `App\Services\WebPushDispatcher` — wrapper de `minishlink/web-push`.
  - `send(PushSubscription, array $payload)`: cifra y manda. 410/404
    → soft-revoke automático.
  - `userCanReceiveOrderUpdate(User, $nit, $branchId)` /
    `userCanReceiveInventoryDigest(User, $nit)`: gating de destinatarios.
  - Helpers `pendingApprovalTag(orderId)`, `inventoryDigestTag(isoDate)`.

### Endpoints (v1)

- `POST /api/v1/push/subscriptions` (auth + `permission:notifications,create`,
  `throttle:20,1`): upsert por `(user_id, endpoint)`.
- `DELETE /api/v1/push/subscriptions` (auth + `permission:notifications,delete`,
  `throttle:20,1`): soft-revoke por endpoint. Idempotente (siempre 204).
- `GET /api/v1/push/subscriptions/me` (auth + `permission:notifications,read`):
  lista subs activas del user actual.

Controller: `App\Http\Controllers\Api\PushSubscriptionController`.
FormRequests: `App\Http\Requests\Push\{Store,Destroy}PushSubscriptionRequest`
(con `SanitizesInput` + `SafePlainText`).

### Cron

`routes/console.php` — `notifications:remind-pending-approvals`
(`everyMinute()->onOneServer()->withoutOverlapping(5)`). Triple defensa
con `Cache::lock("push.reminder.order_item.{id}", throttle*60)` per-item.

### Comando Artisan

- `php artisan push:generate-vapid-keys` — genera par P-256 base64url
  (con fallback OpenSSL CLI para Windows). NO rotar sin comunicar — el
  SW re-suscribe automáticamente via `pushsubscriptionchange`.

### Config

- `config/notifications.php`: VAPID keys + tuning (cooldown,
  throttle, kill-switch del digest).
- `.env.example` documenta `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` /
  `VAPID_SUBJECT` / `PUSH_INVENTORY_DIGEST_ENABLED`.
- `HandleInertiaRequests::share()` agrega `vapidPublicKey` a las shared
  props.

### RBAC

Feature `notifications` con 4 slugs CRUD canónicos
(`notifications.{read,create,update,delete}`). `PermissionTemplateSeeder`
les da `[true,true,true,true]` a TODOS los `role_type` por short-circuit
(self-service universal). El gating real de destinatarios usa permisos
operativos existentes (`orders.update`, `reports.read`/`inventory.read`).

### Auditoría

`AuditService::log` con acciones:
- `notifications.subscribed` (al POST exitoso).
- `notifications.revoked` (al DELETE o al recibir 410 Gone).
- `notifications.pushed` (1 por sub enviada exitosamente).

Ver [`AUDIT_EVENTS.md`](../../bistro/backend/constants/AUDIT_EVENTS.md).

### N-instance safety (CLAUDE.md §12)

Stack canónico (sin Redis/SQS/DynamoDB):
- `QUEUE_CONNECTION=database` — tabla `jobs` en postgres. Workers EC2
  coordinan vía `SELECT ... FOR UPDATE SKIP LOCKED` del driver `database`;
  un solo worker toma cada job.
- `CACHE_STORE=database` — tablas `cache` y `cache_locks` en postgres. El
  cron y `AuthController::selectCompany` usan `Cache::lock` y `Cache::add`
  cross-instance; postgres provee atomicidad.


### Facturación electrónica DIAN — consulta de documentos (2026-07)

`GET /api/v1/dian/documents` (`ElectronicDocumentController::index`, permiso `dian.documents.read`) — listado paginado de `electronic_documents` con:

| Parámetro | Efecto |
|-----------|--------|
| `resolution_id` | Filtra por `dian_resolution_id` (la resolución a la que quedó ligado el documento y a cuyo conteo sumó). |
| `branch` | `all` = toda la empresa · `<uuid>` = esa sede (validada contra las sedes de `active_company_nit` vía subquery — un uuid ajeno no devuelve filas) · ausente = sede activa del JWT. |
| `q` | Búsqueda server-side `ILIKE` (con escape de `%`/`_`) sobre `full_number`, `unique_code` (CUFE/CUDE) y `provider_track_id`. |
| `sort` / `dir` | Whitelist `SORTABLE_COLUMNS` (`issued_at`, `full_number`, `consecutive`, `status`, `document_type`, `created_at`); default `issued_at desc`. Desempate `consecutive desc`. |
| `status`, `document_type`, `from`, `to`, `order_id`, `per_page`, `page` | Filtros previos sin cambios. |

`ElectronicDocumentResource` expone `dian_resolution_id`. Sin permiso nuevo: el escape cross-sede (`branch=all`) ya existía para `dian.documents.read`. Consumidor principal: tab "Facturas" de `/company/dian` (ver FRONTEND_FILES.md).

### Planes SaaS — modelo dos planes (2026-07)

Catálogo `billing_plans`: **Plan Básico** (slug `default`, `is_default`, $0 COP/mes — plataforma completa sin costo) y **Plan Plus** (slug `plus`, $300.000 COP/mes IVA 19% incluido + $10 COP por factura electrónica generada; incluye módulo DIAN — el cobro por factura se implementa junto con el módulo). Fuente de verdad: `BillingPlanSeeder`; entrega a pdn vía migración `2026_07_08_120000_split_default_plan_into_basico_and_plus` + backfill de snapshots `2026_07_08_120100_backfill_subscriptions_to_plan_basico` (audita `subscription.reprice`).

Guardas asociadas en `BillingService`: `generateMonthlyInvoices` no emite invoices con precio $0; `markOverdueInvoices` auto-paga al vencimiento las invoices pending de $0 (audit `invoice.auto_paid_zero_amount`). Cambio de plan por NIT: `billing:change-plan` (workflow `bistro-ops-company-plan.yml`).
