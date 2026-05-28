# Empresas

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Multi-tenancy + multi-sede

flexyflow es multi-empresa **y** multi-sede (#117). Reglas clave:

- La PK física de `companies` es `id` (UUID v7). El identificador tributario
  **NIT** (UNIQUE, inmutable post-creación #193) es lo que se usa en FKs
  (`company_nit`), JWT, URLs públicas y comprobantes.
- Toda tabla operativa lleva FK `company_nit` **y** `branch_id` (UUID a
  `branches.id`). Ver `docs/wiki/Multi-tenancy.md` para el aislamiento por sede
  (trait `BelongsToBranch`, `BranchScope` global).
- Un usuario puede pertenecer a varias empresas con roles distintos vía
  `company_users`, y a varias sedes vía el pivot `branch_users` (owner bypass).
- El JWT fija `active_company_nit` **y** `active_branch_id`; toda
  lectura/escritura operativa está confinada a esa empresa+sede.
- Stack canónico de gates: `jwt` → `company.access` → `company.verified` →
  `company.not_blocked` → `branch.access` → (`branch.consolidate`) →
  `permission:<slug>,<action>`.

---

## Modelo

| Tabla | Campos clave |
|-------|--------------|
| `companies` | `id` (UUID v7, PK), `nit` (UNIQUE, inmutable), `commercial_name`, `legal_name`, `bank_id`, `account_number`, `account_type` (`ahorros`/`corriente`), `breb_key`, `qr_code_path`, `logo_path`, `status` (`pending_activation`/`active`/`past_due`/`suspended`/`rejected`/`inactive`), `plan` (`free`/`pro`/`enterprise`), `tax_regime`, `default_tax_rate`, `default_tax_label`, `tax_included_in_price`, `past_due_started_at`, `expected_block_at`, `payment_blocked_at`, perfil fiscal DIAN (`dv`, `legal_representative_*`, `economic_activity_code`, `fiscal_responsibilities`, `tax_obligations`, `municipality_dane_code`, `billing_email`, `billing_phone`, `physical_address`, `country_code`) |
| `branches` | `id` (UUID, PK), `company_nit`, `name`, `is_default`, `archived_at`, `business_type`, `address`, `phone`, `timestamps` — sede operativa (#117). FK desde toda tabla operativa. |
| `branch_users` | `user_id`, `branch_id` — pivot de acceso a sede operativa (owner bypass). |
| `company_users` | `user_id`, `company_nit`, `company_role_id`, `status` |
| `company_roles` | `id`, `company_nit`, `name`, `description`, `color`, `is_system` |
| `company_role_permissions` | `company_role_id`, `feature_id`, `can_read/create/update/delete` |
| `company_invitations` | `company_nit`, `company_role_id`, `email`, `token`, `status`, `expires_at` |
| `company_settings` | `company_nit`, `key`, `value` (JSON) — almacén KV |

---

## Endpoints

### Empresa activa

| Método | Ruta | Auth | Permiso | Descripción |
|--------|------|------|---------|-------------|
| `GET` | `/api/v1/companies/active` | `jwt` + `company.access` | — | Datos completos de la empresa activa |
| `GET` | `/api/v1/company` | `jwt` + `company.access` + `permission:company.update,read` | `company.update,read` | Datos administrativos (banco, QR, logo) |
| `PUT` \| `POST` | `/api/v1/company` | `jwt` + `company.access` + `permission:company.update,update` | `company.update,update` | Actualiza datos; **reemite JWT** con nuevo `active_company_name` |

### Configuración (KV)

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| `GET` | `/api/v1/companies/settings` | `company.update,read` | Lista todas las settings |
| `GET` | `/api/v1/companies/settings/{key}` | `company.update,read` | Lee una setting puntual |
| `PATCH` | `/api/v1/companies/settings` | `company.update,update` | Actualiza el bloque `settings: {...}` |

Claves válidas y validaciones se definen en `config/company_settings.php`. Ejemplo: `menu_primary_color` (HEX `#RRGGBB`).

---

## Onboarding

### Flujo

1. **Onboarding de usuario** (`POST /api/v1/enrollment/user`) — datos personales + aceptación legal (TOS + privacidad), registra `user_acceptances` con versión y timestamp.
2. **Onboarding de empresa** (`POST /api/v1/enrollment/company`) — aceptación de contrato + NIT, nombres, banco, QR. Crea automáticamente:
   - 3 roles de sistema (`owner`, `admin`, `employee`) clonando `permission_templates`.
   - Membresía del fundador con rol `owner`.
3. **Onboarding por invitación** (`POST /api/v1/enrollment/invited`) — valida token de `company_invitations`, lo marca `accepted` y vincula al usuario con el rol pre-asignado.

### Reglas

- El NIT debe ser único en todo el sistema y es **inmutable** post-creación (#193).
- Las invitaciones expiran en 7 días (`now()->addDays(7)` hardcoded en
  `InvitationController::store`).
- Un usuario invitado con membresía existente en la misma empresa **no puede** ser re-invitado.
- Al onboardear se crea también la sede `is_default=true` inicial (#117). Las
  sedes adicionales se gestionan vía `/api/v1/company/branches`.

---

## Cambio de empresa activa

```http
POST /api/v1/auth/select-company HTTP/1.1
Authorization: Bearer <JWT actual sin empresa o con otra>
Content-Type: application/json

{ "nit": "900123456-7" }
```

```http
HTTP/1.1 200 OK
{
  "token": "eyJhbGciOi..."
}
```

Existe también `POST /api/v1/auth/switch-company` para cambiar la empresa
activa preservando la sesión existente.

Códigos de error específicos:
- `404` — Empresa no encontrada en la lista de membresías del usuario.
- `422` — `La empresa está desactivada.`
- `403` — `USER_INACTIVE_IN_COMPANY` — el admin desactivó la membresía.

---

## Cambio de sede activa (multi-sede #117)

```http
POST /api/v1/auth/switch-branch HTTP/1.1
Authorization: Bearer <JWT actual>
Content-Type: application/json

{ "branch_id": "0190f1b8-..." }
```

Reglas:
- Valida acceso vía pivot `branch_users` (owner bypass).
- **Bloquea el switch si hay caja abierta** en la sede actual (#192 Fase 3.1)
  salvo permiso `cash_register.bypass_switch_lock`.
- Reemite JWT con la nueva sede activa.
- Audita con `auth.branch.switched` (incluye `from_branch_id`, `to_branch_id`,
  `was_owner_bypass`).

Listado de sedes accesibles: `GET /api/v1/auth/branches-available` (alimenta el
selector de sede del SPA).

---

## Estados de empresa

Catálogo canónico en `application/backend/config/companies.php` +
`application/backend/constants/COMPANY_STATUSES.md`. Enum BD definido en la
migración foundation `0001_01_01_000100_create_companies_block.php`.

| Estado | Bucket | Significado | Acciones permitidas |
|--------|--------|-------------|---------------------|
| `pending_activation` | `pending` | Default al crear empresa. JWT se emite pero `EnsureCompanyVerified` bloquea operativo. Espera workflow ops manual de verificación. | Selector + pantalla "Cuenta en revisión" |
| `active` | `verified` | Operativa. Único estado completamente operativo. | Todas |
| `past_due` | `verified` | ≥1 factura vencida, atraso ≤ 3 meses. Sigue operando, se muestra banner (#175). | Todas + banner |
| `suspended` | `verified` + `fully_blocked` | Atraso > 3 meses (#175/#193). `EnsureCompanyNotBlocked` bloquea salvo `/billing`, `/dashboard`, settings personales y comprobante. | Solo billing y dashboard |
| `rejected` | `blocked` | Workflow de verificación marcó la empresa como inválida. Owner puede reintentar enrollment. | Re-onboarding (`rejected → pending_activation`) |
| `inactive` | `blocked` | Baja administrativa/voluntaria. No se usa por past_due. | Sin acceso |

> Estados retirados: `verified` (ahora bucket semántico) y `delinquent`
> (reemplazado por `past_due`) — ver `COMPANY_STATUSES.md`.

---

## Notas de seguridad

- Solo `owner` y `admin` pueden actualizar datos administrativos de la empresa (RBAC vía `permission:company.update,update`).
- La actualización de `commercial_name` reemite el JWT (`active_company_name` cambia) para que el sidebar refleje el nuevo nombre sin recargar manualmente.
- El logo y el QR se almacenan en `companies/logos` y `companies/qr-codes`
  dentro del disco público (S3 en QA/PDN, local en dev). Los assets de S3 se
  sirven firmados vía `GET /storage-proxy/{path}` con TTL de 60 min (#172).
