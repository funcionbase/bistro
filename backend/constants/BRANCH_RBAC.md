# RBAC por sede (multi-tenant + multi-sede)

> **Fuente de verdad ejecutable**:
> `bistro/backend/app/Models/Concerns/BelongsToBranch.php` (scope global) +
> `bistro/backend/app/Http/Middleware/EnsureBranchAccess.php` +
> `bistro/backend/app/Http/Middleware/AllowConsolidatedBranches.php` +
> `bistro/backend/app/Services/FeaturePermissionService.php::userCanViewConsolidated` +
> `bistro/backend/app/Services/AuditService.php` (auto-tag `branch_id`).
> Doc operativa extendida: `docs/wiki/Multi-tenancy.md`.
> Este archivo es **espejo de referencia** RBAC — el código gana en caso de drift.

---

## Modelo dual

| Eje | Identificador interno | Identificador externo / FK | Propósito |
|---|---|---|---|
| Empresa | `companies.id` (UUID v7, PK) | `companies.nit` (DIAN, UNIQUE, inmutable) — usado en FKs `company_nit`, JWT, URLs públicas y comprobantes | Identidad tributaria, marca, legales, billing. Plan en `subscriptions` (#257). |
| Sede | `branches.id` (UUID, PK + FK) | mismo | Caja, mesas, menú, inventario, KDS, reportes. `branches.archived_at` para soft-delete. |

- Tablas **globales** (`companies`, `users`, `company_roles`, `features`,
  `permission_templates`, `subscriptions`, etc.) NO tienen `branch_id`. Son
  compartidas dentro de la empresa.
- Tablas **operativas** (29+) tienen `branch_id uuid NOT NULL`, FK a
  `branches.id` con `onDelete restrict`. La sede NO se borra; se archiva.

## Acceso a sede operativa (`branch_users` pivot)

- Para que un usuario non-owner pueda operar en una sede, debe estar en el
  pivot `branch_users (user_id, branch_id)`.
- El owner (rol `is_system=true`) tiene **bypass automático**: ve TODAS las
  sedes activas sin estar en el pivot.
- El `JwtService::issue` resuelve `active_branch_id` así:
  1. Si el caller pasa uno explícito y el usuario lo tiene en `branch_users`
     (o es owner), se usa.
  2. Si no, la sede `is_default=true` no archivada (chequeada contra el pivot
     para non-owner).
  3. Si no, la primera por `created_at` que cumpla acceso.

## Middlewares (pipeline canónico)

```php
Route::middleware([
    'jwt',                        // ValidateJwt
    'company.access',             // EnsureCompanyAccess → inyecta active_company_nit
    'company.verified',
    'company.not_blocked',        // bloquea suspended (#193)
    'branch.access',              // EnsureBranchAccess → inyecta active_branch_id + valida pivot
    'branch.consolidate',         // AllowConsolidatedBranches (opcional, ver §abajo)
    'permission:<feature>,<action>',
])->group(/* ... */);
```

| Middleware | Función |
|---|---|
| `EnsureCompanyAccess` | Verifica acceso a la empresa del JWT. Inyecta `active_company_nit` en request attributes. |
| `EnsureBranchAccess` | Verifica acceso a la sede del JWT (owner bypass). Inyecta `active_branch_id`. |
| `AllowConsolidatedBranches` | Permite `?branch=all` para vistas consolidadas (requiere `userCanViewConsolidated` true). Sobrescribe `active_branch_id` a `null` en runtime. |
| `EnsureFeaturePermission` | Aplica RBAC (`permission:<slug>,<action>`). Bypass si `role.is_system=true`. |

> **Nunca** leer `branch_id` directo del JWT: `branch.consolidate` puede
> sobrescribirlo. Leer siempre de `request()->attributes->get('active_branch_id')`.

## Vista consolidada (`metrics.view_all_branches`)

`FeaturePermissionService::userCanViewConsolidated(User, Company)` retorna
`true` por **3 rutas independientes**:

1. **Owner**: `membership->role->is_system === true` → bypass.
2. **Permiso explícito**: el rol tiene `metrics.view_all_branches` con
   `can_read=true` (asignable manualmente vía editor).
3. **Cobertura total**: el usuario tiene acceso (via `branch_users`) a
   **todas las sedes activas** (no archivadas) de la empresa. Evaluado en
   runtime — si el owner crea una sede nueva y no agrega al usuario, este
   pierde el privilegio hasta que se le otorgue acceso.

> El frontend consulta esto en cada carga del filtro de sedes
> (`components/reports/branch-filter-tabs.tsx`) para decidir si ofrecer la
> tab "Todas las sedes".

## Permisos sensibles owner-only por default (#192)

Tres permisos quedan en `[false,false,false,false]` para `admin` por
`PermissionTemplateSeeder`. NO llevan `is_owner_only=true` (sí aparecen en
`UserPermissionsEditor`), pero el owner debe asignarlos manualmente si
quiere delegarlos:

| Slug | Acción que protege |
|---|---|
| `chats.reassign_branch` | Mover un chat de una sede a otra (`POST /api/v1/chats/{id}/reassign-branch`). |
| `cash_register.bypass_switch_lock` | Cambiar de sede activa cuando hay caja abierta (default: bloqueado). |
| `inventory.transfer_cross_branch` | Crear transferencias de ingredientes entre sedes (`POST /api/v1/inventory/transfers`). |

> Si en el futuro se introduce otro permiso *cross-branch* con riesgo
> operativo, seguir esta misma convención.

## Auditoría automática por sede

`AuditService::log($action, $metadata)` agrega automáticamente:

- `branch_id`: la sede operativa donde sucedió la acción
  (`request->attributes->get('active_branch_id')`).
- `actor_active_branch_id`: si el actor estaba viendo otra sede via
  `?branch=` (vista consolidada), queda registrado.
- `company_nit`: ya viene del JWT.

> No hace falta pasarlos en el `$metadata` — el service los inyecta. Pasar
> en `$metadata` solo datos accionables específicos de la acción (montos,
> referencias, motivo, target IDs).

## Pares espejo que deben mantenerse sincronizados

- Lista de permisos sensibles cross-branch en este `.md` ↔
  `PermissionTemplateSeeder::handle()` (las 3 entradas que retornan
  `[false,false,false,false]` para admin).
- Comportamiento de `metrics.view_all_branches` ↔
  `FeaturePermissionService::userCanViewConsolidated` ↔
  `components/reports/branch-filter-tabs.tsx` (UI).

> Última revisión: 2026-05-18 (#201) — alineado con `docs/wiki/Multi-tenancy.md`.
