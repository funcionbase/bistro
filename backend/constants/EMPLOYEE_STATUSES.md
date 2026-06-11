# EMPLOYEE_STATUSES — Fuente única de verdad

> **Antes de modificar el enum `employees.vinculation_status`, lee este
> archivo.**
> **Después de modificar, actualiza este archivo + la migración + el modelo
> `Employee` + `config/employees.php` + los tipos TS + el helper
> `lib/employee-status.ts` en el mismo PR.**

---

## Fuente de verdad ejecutable

- `application/database/migrations/0001_01_01_001300_create_employees_block.php:83`
  — enum BD `('active', 'inactive', 'vacation', 'sick_leave', 'compensatory')`.
- `application/config/employees.php` (#204) — catálogo canónico (`vinculation_statuses`,
  `labels`, `badges`). Lo consumen `Rule::in(...)` en backend y la prop
  compartida `employeeStatuses` en Inertia.
- `application/app/Http/Middleware/HandleInertiaRequests.php` — expone
  `props.employeeStatuses` a todas las páginas Inertia.

> El `.md` es **referencia humana**. Si difiere del código, el código gana y
> este archivo se corrige en el mismo PR.

---

## Estados de `employees.vinculation_status`

Estado del **vínculo laboral** del colaborador con la empresa. Una sola fila
por empleado, mutable por admin/owner vía `PATCH
/api/v1/employees/{id}/vinculation` (`EmployeeController::changeVinculationState`).

| status | Categoría | Label UI (es-CO) | Badge | Terminal | Notas |
|---|---|---|---|---|---|
| `active` | operativo | Activo | `variant="safe"` | no | Único estado que permite operar (asignaciones, turnos, salario). |
| `inactive` | suspendido | Inactivo | `variant="critical"` | no | Vínculo finalizado o suspendido manualmente. No participa en turnos ni reportes vigentes. |
| `vacation` | ausencia | Vacaciones | `variant="warning"` | no | Ausencia planificada con `valid_from`/`valid_until`. Cascada cancela `EmployeeShift` programados dentro del rango. |
| `sick_leave` | ausencia | Incapacidad | `variant="warning"` | no | Incapacidad médica. Igual que vacaciones, requiere rango de fechas. |
| `compensatory` | ausencia | Compensatorio | `variant="warning"` | no | Día compensado. Rango obligatorio. |

Fuente: migración `0001_01_01_001300_create_employees_block.php:83`.

### Reglas de transición

1. **Cualquiera → `active`**: limpia `vinculation_valid_from` /
   `vinculation_valid_until` (opcional vía payload).
2. **`active` → `inactive`**: requiere permiso `employees.delete` (vía
   `archive()`) o `employees.update` con `changeVinculationState`. Pasa
   también `archived_at = now()` cuando viene por `archive()`.
3. **`active` → `vacation` | `sick_leave` | `compensatory`**: requiere
   `valid_from` y `valid_until` (validación dura, 422 si faltan). Cascada
   cancela `EmployeeShift` con `status='scheduled'` dentro del rango y
   `cancellation_reason='vinculation_state'`.
4. **Cualquier transición**: pasa por `EmployeeVinculationPolicy::denialReason`
   antes de persistir — bloquea auto-desactivación, desactivación de owner,
   y admin tratando de degradar a owner.
5. Cuando `employee.user_id` está enlazado: la mutación sincroniza
   `users.status = 'active'` si nuevo estado es `active`, sino `'inactive'`.
   Ver [`USER_STATUSES.md`](./USER_STATUSES.md).

### Distinción con `users.status`

- `employees.vinculation_status` — estado del **vínculo laboral**. 5 valores.
- `users.status` — estado del **usuario humano**. 3 valores
  (`pending_enrollment` / `active` / `inactive`). Ver
  [`USER_STATUSES.md`](./USER_STATUSES.md).
- Un colaborador en `vacation` sigue siendo `users.status='active'` (puede
  entrar a la app a ver su perfil); solo `inactive` desactiva el `User`.

---

## Impacto RBAC

- **Permiso de lectura**: `employees.read`. Owner bypass siempre.
- **Permiso de mutación**: `employees.update`. `employees.delete` para
  `archive()`. Owner bypass siempre.
- **Reglas adicionales (vía `EmployeeVinculationPolicy`)**:
  - Auto-desactivación bloqueada (`REASON_SELF`).
  - Owner no puede ser desactivado por nadie (`REASON_TARGET_IS_OWNER`).
  - Admin no puede degradar a owner (`REASON_ADMIN_CANNOT_DEMOTE_OWNER`).
- **Auditoría** (`AuditService::log`):
  - `employee.vinculation_changed` con `from`, `to`, `valid_from`, `valid_until`, `cascade_cancelled_shifts`.
  - `employee.vinculation_change_denied` con `attempted_status`, `reason` cuando la policy rechaza.
  - `employee.archived` cuando entra por `archive()`.

---

## Frontend — cómo se consume

A partir de #204 las vistas consumen el catálogo vía shared prop
`employeeStatuses` (expuesto por `HandleInertiaRequests::share`):

```ts
// resources/js/hooks/use-employee-statuses.ts
const { statuses, labels, badges } = useEmployeeStatuses();
// employeeStatusLabel('vacation') === 'Vacaciones'
// employeeStatusBadge('vacation') === 'warning'
```

Páginas que consumen el hook (no más mapas hardcoded):

- `resources/js/pages/employees/index.tsx` — tabla + filtros.
- `resources/js/pages/employees/show.tsx` — detalle + selector de transición.
- `resources/js/pages/me/index.tsx` — mi vínculo activo.
- `resources/js/pages/me/perfil.tsx` — badge en mi perfil.

Fallback embebido en el hook (`EMPLOYEE_STATUSES_FALLBACK`) duplica
exactamente lo que está en `config/employees.php`. Si el render inicial llega
sin JWT (raro), el fallback sostiene las labels.

---

## Cómo añadir un estado nuevo

1. Migración nueva que altere el enum (PostgreSQL: drop CHECK constraint
   auto-named via `pg_constraint`, recrear con la lista completa). Ver
   patrón en `2026_05_18_202926_unify_user_acceptances_document_type_terms.php`.
2. Agregar el slug a `config/employees.php` (`vinculation_statuses`,
   `labels`, `badges`).
3. Actualizar `EMPLOYEE_STATUSES_FALLBACK` en
   `resources/js/hooks/use-employee-statuses.ts`.
4. Extender `EmployeeStatus` union en `resources/js/types/index.ts`.
5. Si el nuevo estado es una **ausencia** (requiere rango de fechas), añadirlo
   al `in_array` de `EmployeeController::changeVinculationState` líneas 219 y
   253.
6. Si tiene reglas de policy (p.ej. no se puede aplicar a owner), extender
   `EmployeeVinculationPolicy`.
7. Actualizar este `.md` (tabla + reglas + impacto RBAC).
8. PR descripción: "Nuevo estado `<x>`. Categoría: <…>. Requiere rango: sí/no.
   Cascada de turnos: sí/no. Auditoría emitida: <action>."

---

## Pares espejo que deben mantenerse sincronizados

- `database/migrations/0001_01_01_001300_create_employees_block.php:83` ↔ `config/employees.vinculation_statuses` ↔ tabla de este `.md`.
- `app/Http/Controllers/Api/Employees/EmployeeController.php:214` ↔ `config('employees.vinculation_statuses')`.
- `app/Services/EmployeeVinculationPolicy.php` ↔ reglas de transición de esta sección.
- `resources/js/hooks/use-employee-statuses.ts` (`EMPLOYEE_STATUSES_FALLBACK`) ↔ `config/employees.php`.
- `resources/js/types/index.ts` (`EmployeeStatus`, `EmployeeStatusesConfig`) ↔ `config/employees.php`.
- `app/Http/Middleware/HandleInertiaRequests.php` (`employeeStatuses` shared prop) ↔ `config('employees.*')`.

---

## Referencias cruzadas

- [`USER_STATUSES.md`](./USER_STATUSES.md) — sincronización `users.status` ↔ `employees.vinculation_status`.
- [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) — slugs `employees.read`, `employees.update`, `employees.delete`.
- [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) — acciones `employee.vinculation_changed`, `employee.archived`, `employee.vinculation_change_denied`.
- [`ROLES_SYSTEM.md`](./ROLES_SYSTEM.md) — bypass owner.

> Última revisión: 2026-05-18 (#204)
