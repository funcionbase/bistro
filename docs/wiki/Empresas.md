# Empresas

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Multi-tenancy

flexyflow es multi-empresa. Reglas clave:

- La PK de `companies` es el **NIT** (string), no un autoincremental.
- Toda tabla operativa (orders, deliveries, coupons, etc.) lleva FK `company_nit`.
- Un usuario puede pertenecer a varias empresas con roles distintos vía `company_users`.
- El JWT siempre fija una `active_company_nit`; toda lectura/escritura está confinada a esa empresa.
- El middleware `company.access` rechaza el request si el usuario no es miembro **activo** de la empresa.

---

## Modelo

| Tabla | Campos clave |
|-------|--------------|
| `companies` | `nit` (PK), `commercial_name`, `legal_name`, `bank_id`, `account_number`, `account_type` (`ahorros`/`corriente`), `breb_key`, `qr_code_path`, `logo_path`, `status` (`active`/`inactive`/`pending_activation`/`mora`/`delinquent`/`suspended`), `plan` (`free`/`pro`/`enterprise`) |
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

- El NIT debe ser único en todo el sistema.
- Las invitaciones expiran en 7 días (configurable en `config/roles.php`).
- Un usuario invitado con membresía existente en la misma empresa **no puede** ser re-invitado.

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

Códigos de error específicos:
- `404` — Empresa no encontrada en la lista de membresías del usuario.
- `422` — `La empresa está desactivada.`
- `403` — `USER_INACTIVE_IN_COMPANY` — el admin desactivó la membresía.

---

## Estados de empresa

| Estado | Significado | Acciones permitidas |
|--------|-------------|---------------------|
| `active` | Operativa | Todas |
| `pending_activation` | Recién registrada, aún sin pago | Limitado: solo settings y onboarding |
| `mora` | Factura vencida pero dentro de meses de gracia | Operativo, banner de advertencia |
| `delinquent` | Más de `BILLING_GRACE_MONTHS` en mora | Bloqueo progresivo |
| `suspended` | Cancelada por incumplimiento o solicitud | Solo lectura, sin operaciones |
| `inactive` | Inhabilitada manualmente | Sin acceso |

---

## Notas de seguridad

- Solo `owner` y `admin` pueden actualizar datos administrativos de la empresa (RBAC vía `permission:company.update,update`).
- La actualización de `commercial_name` reemite el JWT (`active_company_name` cambia) para que el sidebar refleje el nuevo nombre sin recargar manualmente.
- El logo y el QR se almacenan en `storage/public/companies/{logos|qr-codes}` y se sirven a través del disco público.
