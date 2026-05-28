# Sucursales (Sedes)

> Estado: Estable (multi-sede HU #117 + verticalización por sede #237)
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Una empresa (`companies.nit`) puede tener **N sedes** (`branches`). Toda entidad operativa (órdenes, KDS tickets, comandas, sesiones de mesa, receipts, cajas) lleva FK `branch_id` y está aislada por el middleware `EnsureBranchAccess`. El JWT acarrea siempre un `active_branch_id` que define el contexto de lectura/escritura.

Las sedes se gestionan desde `/company/branches`. El "borrado" es **soft archive** (`archived_at`) — las FK son `onDelete restrict` para no romper historial DIAN. Cada sede declara su vertical (`business_type_id`, e.g. `restaurant`/`bar`/`cafeteria`) y siembra automáticamente las `prep_areas` y `kds_stations` canónicas en el alta.

La asignación de usuarios a sedes se controla con el pivot `branch_users`. Quien no tenga fila ahí no puede ingresar a la sede, incluso si es miembro de la empresa. Excepción: permiso `metrics.view_all_branches` habilita vista consolidada (`?branch=all`) sin requerir pertenencia explícita.

---

## Modelo de datos

### `branches`

```text
id                   uuid (PK)
company_nit          string (FK → companies.nit)
name                 string
slug                 string (único por empresa)
address              string (nullable)
city                 string (nullable)
business_type_id     string (FK → business_types.slug) — vertical
capabilities_override jsonb (nullable) — overrides puntuales sobre default_capabilities del vertical
is_default           boolean — informativo, sede predeterminada para nuevos enrollments
archived_at          timestamp (nullable) — soft archive
created_at/updated_at
```

Eloquent: `App\Models\Branch` con `HasUuids`. Scope `active()` filtra `whereNull('archived_at')`. Helper `isArchived()`.

### `branch_users` (pivot)

Modelo `App\Models\BranchUser` (no Pivot puro) para auditar quién otorgó el acceso.

```text
id                   uuid (PK)
branch_id            uuid (FK → branches.id)
user_id              int  (FK → users.id)
granted_by_user_id   int  (nullable) — auditoría
granted_at           timestamp
created_at/updated_at
```

Composición clave: el pivot no copia `company_nit` — ese se valida cruzando `branches.company_nit` con la membresía `company_users` del actor en `attachUser` / `bulkAssign`.

### `employees_branches`

Pivot de cobertura adicional de un colaborador (la sede principal vive en `employees.primary_branch_id`).

### `prep_areas`

Áreas de preparación sembradas desde `business_types.prep_area_defaults` en el alta de la sede. Cada vertical define su catálogo (slug, label, color, icon, orden).

### `kds_stations`

Cada sede recién creada (en `CompanyEnrollmentController` o `BranchController::store`) recibe las 4 estaciones canónicas (`caliente`, `fria`, `barra`, `fritos`) vía `KdsStation::seedDefaultsForBranch($nit, $branchId)`. Ver wiki **Cocina**.

---

## Permisos RBAC

| Slug | owner | admin | empleado | Notas |
|---|---|---|---|---|
| `branches.manage` | RCUD | (asignable, sensible) | — | Crear/editar/archivar. Owner-only por default. |
| `branches.assign_users` | RCUD | (asignable) | — | Adjuntar/desadjuntar usuarios al pivot. |
| `branches.copy_menu` | --U- | (asignable) | — | Duplicar menú de una sede como draft en otra. |
| `metrics.view_all_branches` | ✅ | (asignable) | — | Vista consolidada `?branch=all` en métricas y reportes. |
| `branches.read` (implícito) | ✅ | ✅ | ✅ | Listar sedes propias (sin permiso explícito — basta con `branch_users` o `metrics.view_all_branches`). |

Owner es `role.is_system=true` y bypasea todos los `branches.*`. Admin no recibe `branches.*` por default — el owner se los puede asignar manualmente desde `UserPermissionsEditor`.

> **Regla clave**: crear/archivar sedes y mover usuarios entre sedes son acciones owner-only por seeder. Esto evita que un admin re-distribuya accesos sin supervisión del propietario.

---

## Endpoints

Todas las rutas viven bajo `api/v1` con middleware `jwt` + `company.access`. No requieren `branch.access` — son operaciones a nivel de empresa.

### Sedes

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/company/branches?include_archived=0` | — (solo miembro de empresa) |
| POST | `/api/v1/company/branches` | `branches.manage,create` |
| PATCH | `/api/v1/company/branches/{branch}` | `branches.manage,update` |
| DELETE | `/api/v1/company/branches/{branch}` | `branches.manage,delete` |
| POST | `/api/v1/company/branches/{branch}/change-business-type` | `branches.manage,update` |
| POST | `/api/v1/company/branches/{branch}/menu/copy` | `branches.copy_menu,update` |

### Asignación de usuarios

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/company/branches/{branch}/users` | `branches.assign_users,read` |
| POST | `/api/v1/company/branches/{branch}/users` | `branches.assign_users,update` |
| DELETE | `/api/v1/company/branches/{branch}/users/{userId}` | `branches.assign_users,update` |
| POST | `/api/v1/company/branches/bulk-assign` | `branches.assign_users,update` |

### Selector de sede activa (consumido por el frontend al cambiar contexto)

| Método | Ruta | Notas |
|---|---|---|
| GET | `/api/v1/auth/branches-available` | Lista sedes a las que el JWT del actor tiene acceso (cruza `branch_users` + `metrics.view_all_branches` para vista consolidada). |

---

## Flujos funcionales

### Alta de sede (`POST /api/v1/company/branches`)

Corre dentro de `DB::transaction`:

1. Si llega `is_default=true`, **desmarca cualquier otra default activa** de la empresa (única default por NIT).
2. Resuelve `business_type_id` (default `restaurant` si no llega). `abort(422)` si el vertical no existe.
3. Crea la fila en `branches`.
4. Siembra `prep_areas` desde `business_type.prep_area_defaults` (slug, label, color, icon, display_order).
5. **Auto-asigna al creador** + a **todos los owners de la empresa** vía `BranchUser::updateOrCreate`. Razón: sin esto, el creador no podría ingresar a la sede que acaba de crear hasta otorgarse acceso explícito.
6. Siembra las 4 `kds_stations` canónicas vía `KdsStation::seedDefaultsForBranch`.

Audita `branch.created` con `branch_id`, `slug`, `is_default`.

### Edición (`PATCH /api/v1/company/branches/{branch}`)

- Si `is_default=true`, desmarca otras default de la empresa (en la misma transacción).
- Edición de `name`, `slug`, `address`, `city`, `is_default`. Cambio de vertical va por endpoint dedicado.
- Audita `branch.updated` con before/after.

### Archivar (`DELETE`)

- **No** borra físicamente — set `archived_at = now()` y `is_default = false`.
- Rechaza con 422 `LAST_ACTIVE_BRANCH` si es la **única sede activa** restante (la empresa siempre debe tener ≥1 sede operativa).
- Rechaza con 422 si la sede ya está archivada.
- Audita `branch.archived`.

### Cambio de vertical (`POST .../change-business-type`)

Permite recategorizar una sede operativa (e.g. de `restaurant` a `bar`) sin recrearla. NO modifica órdenes históricas, menús ni receipts — solo:

- Actualiza `branches.business_type_id`.
- Siembra `prep_areas` faltantes del nuevo vertical (las existentes se preservan para no perder mapeos de KDS / menú).

Rechaza con 422 `BUSINESS_TYPE_UNCHANGED` si el vertical destino es el actual. Audita `branch.business_type_changed`.

### Copia de menú (`POST .../menu/copy`)

Duplica el menú `status='active'` de una sede origen como `status='draft'` en la sede destino. Tras la copia los menús son independientes (sin vínculo persistido). Si la sede origen no tiene menú activo → 422 `SOURCE_MENU_NOT_FOUND`. Audita `branch.menu_copied` con `items_count`.

### Asignación de usuarios

**Singular** (`POST .../{branch}/users`): valida que el `user_id` sea miembro activo de la empresa (cruza `company_users`). Si no → 422 `USER_NOT_COMPANY_MEMBER`. Idempotente vía `updateOrCreate`.

**Bulk** (`POST .../bulk-assign`):

```json
{ "branch_id": "<uuid>", "user_ids": ["<uuid>", "..."], "action": "attach" | "detach" }
```

- `min:1`, `max:500` user_ids.
- Filtra silenciosamente los user_ids que **no** son miembros de la empresa (responde `applied` y `skipped`).
- Corre dentro de `DB::transaction`.
- Audita `branch.users_bulk_attach` o `branch.users_bulk_detach` con `requested_count` + `applied_count`.

### Middleware `EnsureBranchAccess`

Lee `jwt_payload.active_branch_id`. Verifica:

- Branch existe (`Branch::find`).
- `archived_at` es null → si no, 422 `BRANCH_ARCHIVED`.
- `company_nit` coincide con `active_company_nit` del JWT → si no, 422 `BRANCH_COMPANY_MISMATCH` (defensa contra tampering).
- El actor tiene fila en `branch_users` → si no, 403 `BRANCH_FORBIDDEN`.

Si pasa, inyecta `request.attributes.active_branch_id` y `active_branch` (modelo). Si no hay `active_branch_id` en el JWT → 422 `NO_ACTIVE_BRANCH` (el frontend redirige a `/select-branch`).

### Middleware `AllowConsolidatedBranches`

Permite `?branch=all` en endpoints de métricas/reportes para usuarios con `metrics.view_all_branches`. Sin ese permiso, el query string es rechazado y se fuerza la sede activa del JWT.

---

## Componentes frontend

| Componente | Ruta / Archivo | Propósito |
|---|---|---|
| `pages/company/branches/index.tsx` | `/company/branches` | Listado de sedes activas + archivadas (toggle). CRUD vía `Dialog`. Modal de asignación de usuarios. |
| `components/business-type-selector.tsx` | — | Selector de vertical en el formulario de sede (alta + cambio). |
| `components/ui/dashboard-panel.tsx` | — | Card contenedora de cada sede en el listado. |
| `components/ui/confirm-dialog.tsx` | — | Confirmación de archivado / cambio de vertical. |
| `components/ui/list-card-skeleton.tsx` | — | Skeleton mientras carga el listado. |
| `hooks/use-business-types.ts` | — | Hook que consume `/api/v1/business-types` para poblar el selector. |
| Selector de sede activa (sidebar) | `components/active-branch-switcher.tsx` (componente del shell) | Switcher en el sidebar que invoca `/auth/branches-available` y emite token nuevo al cambiar. |

---

## Eventos de auditoría

Todos vía `AuditService::log` (que agrega `branch_id` y `actor_active_branch_id` automáticamente).

| Action | Disparador | Metadata |
|---|---|---|
| `branch.created` | `BranchController::store` | `branch_id`, `slug`, `is_default` |
| `branch.updated` | `BranchController::update` | `before`, `after` (name/slug/address/city/is_default) |
| `branch.archived` | `BranchController::destroy` | `branch_id`, `slug` |
| `branch.business_type_changed` | `BranchController::changeBusinessType` | `before`, `after` (slug del vertical) |
| `branch.menu_copied` | `BranchController::copyMenu` | `from_branch_id`, `to_branch_id`, `source_menu_id`, `copied_menu_id`, `items_count` |
| `branch.user_attached` | `BranchController::attachUser` | `branch_id`, `user_id` |
| `branch.user_detached` | `BranchController::detachUser` | `branch_id`, `user_id` |
| `branch.users_bulk_attach` | `BranchController::bulkAssign` | `branch_id`, `user_ids`, `requested_count`, `applied_count` |
| `branch.users_bulk_detach` | `BranchController::bulkAssign` | `branch_id`, `user_ids`, `requested_count`, `applied_count` |

---

## Edge cases y empty states

- **Última sede activa**: el archivado se rechaza con 422 `LAST_ACTIVE_BRANCH`. La empresa siempre debe tener ≥1 sede operativa para que el JWT pueda emitir `active_branch_id`.
- **Sede archivada después de emisión del JWT**: `EnsureBranchAccess` responde 422 `BRANCH_ARCHIVED`. Frontend captura y fuerza re-selección.
- **Branch tampering** (token con `active_branch_id` de otra empresa): rechazado con 422 `BRANCH_COMPANY_MISMATCH`.
- **`is_default` doble**: garantizado único por la transacción de `store` y `update` — `where('company_nit', $nit)->where('is_default', true)->update(['is_default' => false])` antes de marcar la nueva.
- **Slug duplicado por empresa**: validado en `StoreBranchRequest` / `UpdateBranchRequest`.
- **Asignar usuario que no es miembro de la empresa**: 422 `USER_NOT_COMPANY_MEMBER` (singular). En bulk, se ignora silenciosamente y se reporta en `skipped`.
- **`bulk-assign` con `user_ids=[]`**: rechazado por validación (`min:1`).
- **Copia de menú a la misma sede**: rechazado por regla `different:{branch}` del FormRequest.
- **Cambio de vertical al mismo**: 422 `BUSINESS_TYPE_UNCHANGED`.
- **Empty state listado vacío**: no aplica habitualmente — siempre existe ≥1 sede creada por `CompanyEnrollmentController`. Si el usuario filtra `include_archived=false` y todas están archivadas, ver edge case anterior (no debería ocurrir por la regla LAST_ACTIVE_BRANCH).
- **Acceso consolidado** (`?branch=all`): solo válido con `metrics.view_all_branches`. Sin el permiso, `AllowConsolidatedBranches` fuerza la sede activa.

---

## Convenciones y notas

- **Mutaciones financieras**: las sedes no manejan dinero directamente, pero su ID viaja en cada `payment_receipt`, `order` y `audit_log` para trazabilidad DIAN.
- **`capabilities_override`**: campo JSON nullable para sobreescribir puntualmente las `default_capabilities` del vertical (e.g. desactivar delivery en una sede sin cambiar el vertical entero). Convención: solo las claves que se sobrescriben.
- **Tenancy estricto**: `BranchController::resolveBranch` siempre filtra por `active_company_nit` antes de `firstOrFail`. Un actor jamás puede tocar sedes de otra empresa, ni siquiera con un UUID conocido.
- **Soft archive obligatorio**: nunca usar `truncate`/`forceDelete` en PDN. La conservación DIAN obliga a preservar el historial 5/10 años.
