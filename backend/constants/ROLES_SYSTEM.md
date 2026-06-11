# Roles del sistema (`is_system=true`)

> **Fuente de verdad ejecutable**: `bistro/backend/config/roles.php`
> (`system_roles`, `role_names`, `role_colors`) + `database/migrations` que
> crea `company_roles.is_system` + `database/seeders/PermissionTemplateSeeder.php`
> (defaults por `role_type`).
> Este archivo es **espejo de referencia** — el código gana en caso de drift.

---

## Catálogo

| `role_type` | Nombre localizado | `is_system` | Color (config) | Semántica |
|---|---|---|---|---|
| `owner` | Propietario | `true` | `#0F172A` | Creador de la empresa. Acceso total. Al menos uno por empresa siempre — el último owner activo no puede eliminarse ni degradarse. |
| `admin` | Administrador | `true` | `#7C3AED` | Gerente operativo. Recibe casi todos los permisos por default, excepto los `is_owner_only` (`whatsapp.swap_phone`, `whatsapp.disconnect`) y los sensibles de sede (`chats.reassign_branch`, `cash_register.bypass_switch_lock`, `inventory.transfer_cross_branch`). |
| `employee` | Empleado | `true` | `#94A3B8` | Staff operativo. Solo lectura de slugs en `default_employee_permissions` (`orders.read`, `chats.read`, `clients.read`) + `shifts.read` para ver su propia agenda en `/me/agenda`. |

> El set canónico de `system_roles` se lee con
> `config('roles.system_roles')` y por default es `['owner','admin','employee']`
> (overrideable vía env `SYSTEM_ROLES`). Cualquier otro `role_type` que aparezca
> en `PermissionTemplateSeeder` (`waiter`, `cook`, `cashier`) NO es `system` y
> se crea opcionalmente vía `roles:sync-templates`. Ver
> [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md).

## Reglas duras

1. **No se eliminan ni se renombran** desde la UI ni la API. La tabla `company_roles`
   ignora `DELETE` cuando `is_system=true`.
2. **No se modifican sus permisos** desde el editor de roles del owner — los
   defaults vienen del template y son la fuente de verdad de la matriz por
   default.
3. **Bypass automático del RBAC** en `EnsureFeaturePermission::handle` y
   `FeaturePermissionService::userCan*`: si `role.is_system=true`, la
   verificación retorna `true` sin consultar la matriz.
4. **Último owner inviolable**: `RoleController::authorizeManagerRole` y la
   lógica de cambio de rol bloquean operaciones que dejarían a la empresa sin
   ningún `owner` activo.

## Cómo se siembran

- Producción: cuando se onboarda una empresa nueva, el flujo de
  `CompanyController::store` crea las 3 `CompanyRole` con `is_system=true`
  usando los nombres y colores de `config/roles.php`. El owner que creó la
  empresa queda con `role_type=owner`.
- Demo / QA (`RestauranteFlexySeeder`): `seedRoles()` llama
  `seedSystemRoleFromTemplate($companyNit, $roleType, config('roles.role_colors.X'))`
  para cada uno de los 3. Las plantillas de permisos vienen de
  `PermissionTemplate::where('role_type', $roleType)`.

## Bypass `is_system` — dónde vive la regla

| Capa | Archivo | Detalle |
|---|---|---|
| Backend service | `app/Services/FeaturePermissionService.php:77` (y `:127`) | Retorna `true` automático cuando el rol es system. |
| Backend middleware | `app/Http/Middleware/EnsureFeaturePermission.php` | Early return si el rol es system. |
| Backend controller | `app/Http/Controllers/RoleController.php::authorizeManagerRole` | Owner/admin pasa sin chequear permiso. |
| Frontend hook | `resources/js/hooks/use-permissions.ts` | `isSystem` retorna `true` para system roles → habilita acciones en UI. |
| Frontend badge | `resources/js/components/role-badge.tsx` | Render visual diferenciado para system roles. |

> **Mantener sincronizado**: si cambia la semántica del bypass (ej. introducir
> un cuarto rol system, o quitar bypass a `admin`), TODOS los archivos arriba
> deben tocarse en el mismo PR.

## Pares espejo que deben mantenerse sincronizados

- `bistro/backend/config/roles.php` ↔ `bistro/backend/database/seeders/RestauranteFlexySeeder.php` (colores leídos del config).
- `bistro/backend/app/Services/FeaturePermissionService.php` ↔ `bistro/backend/app/Http/Middleware/EnsureFeaturePermission.php` ↔ `bistro/frontend/src/hooks/use-permissions.ts` (regla bypass).
- `bistro/backend/config/roles.php::role_names` ↔ etiquetas en UI (`pages/identities/roles.tsx`, `role-badge.tsx`).

> Última revisión: 2026-05-18 (#201)
