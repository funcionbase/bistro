# Planificador de turnos

> Estado: Estable (HU #182 — gestión de colaboradores y planificador semanal/mensual)
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

El módulo combina la gestión HHRR de **colaboradores** (`employees`) con un **planificador de turnos** (`employee_shifts`) por sede. Los colaboradores existen como entidad independiente de `users` — un cocinero o personal de aseo puede vivir en el sistema sin cuenta de acceso. Cuando el colaborador tiene cuenta, `employee.user_id` queda poblado y se sincronizan estados (`active`/`inactive`).

Las vistas de planificador permiten ver y asignar turnos en una semana (matriz colaborador × día) o en un mes calendario, con KPI agregados de horas planificadas vs canceladas. La validación de turno activo se reutiliza en `CashRegisterController` para impedir aperturas/cierres de caja fuera de horario (con bypass para `owner` y `admin`).

Toda mutación financiera (`pay_rate`, `base_salary`, cambio de estado de vinculación, cancelación de turnos) corre dentro de `DB::transaction` + `lockForUpdate` y audita vía `AuditService::log`. El soft-archive (`employees.archived_at`, `employee_shifts.status = 'cancelled'`) es obligatorio para conservación DIAN (5 años personas naturales / 10 años jurídicas).

---

## Modelo de datos

### `employees`

Perfil HHRR. Soft-archive con `archived_at`.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid (PK) | — |
| `company_nit` | string | FK `companies.nit`. Aislamiento estricto. |
| `user_id` | int (nullable) | FK a `users`. Si está vinculado, comparte ciclo de vida con la cuenta. |
| `primary_branch_id` | uuid | Sede principal. Se usa para `ShiftSuggestionService`. |
| `position_id` | uuid (nullable) | FK `employee_positions` (cargo: cocinero, mesero, etc.). |
| `doc_type`, `doc_number` | string | Identificación CO (CC, CE, NIT, PA). |
| `first_name`, `last_name`, `email`, `phone` | string | Contacto. |
| `birth_date`, `blood_type`, `address`, `city` | varios | Identidad básica. |
| `eps`, `arl`, `pension_fund`, `severance_fund` | string | Seguridad social CO. |
| `bank`, `account_type`, `account_number` | string | Cuenta para pago de nómina. |
| `emergency_contact_name`, `emergency_contact_phone` | string | Contacto de emergencia. |
| `uniform_size` | string | Útil para operaciones. |
| `contract_type` | string | `indefinido` / `fijo` / `obra_labor` / `prestacion_servicios`. |
| `base_salary` | decimal(12,2) | Salario fijo mensual (informativo). |
| `pay_type` | string | `hourly` / `monthly` / `per_shift`. |
| `pay_rate` | decimal(12,2) | Tarifa según `pay_type`. Sensible — requiere `employees.view_salary` para mostrar. |
| `hire_date` | date | Fecha de ingreso. |
| `vinculation_status` | string | `active` / `inactive` / `vacation` / `sick_leave` / `compensatory`. |
| `vinculation_valid_from`, `vinculation_valid_until` | date | Ventana del estado de vinculación (e.g. fechas de vacaciones). |
| `min_days_off_override` | int | Sobreescribe el mínimo de la empresa en `ShiftSuggestionService`. |
| `archived_at` | timestamp | Soft-archive. Conservación DIAN. |

### `employee_positions`

Cargo asignable a un colaborador.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid (PK) | — |
| `company_nit` | string | FK `companies.nit`. |
| `slug` | string | Único por empresa. |
| `label` | string | Etiqueta legible. |
| `color` | string | Hex `#RRGGBB`. Pintado en el planificador como chip de posición. |

### `employee_shifts`

Turno asignado. `starts_at`/`ends_at` son `timestamp` (no solo time): soportan turnos partidos en el mismo día y cruce de medianoche.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid (PK) | — |
| `employee_id` | uuid | FK `employees.id`. |
| `branch_id` | uuid | FK `branches.id` (sede física donde se cumple). |
| `starts_at`, `ends_at` | timestamp | Soportan cruce de medianoche. |
| `status` | string | `scheduled` / `cancelled`. Soft-cancel preserva fila para trazabilidad. |
| `cancellation_reason` | string (nullable) | `sick` / `personal` / `emergency` / `vinculation_state` / `other`. |
| `cancellation_note` | string(500) (nullable) | Nota libre. |
| `cancelled_by_user_id`, `cancelled_at` | — | Trazabilidad. |
| `created_by_user_id` | int | Actor que creó el turno. |

Índice por `(employee_id, starts_at, ends_at, status)` para overlap-detection eficiente.

### `employees_branches`

Pivot opcional para colaboradores con cobertura en varias sedes (la sede principal vive en `employees.primary_branch_id`). El planificador agrega ambas fuentes al filtrar por sede.

---

## Permisos RBAC

| Slug | owner | admin | empleado | Notas |
|---|---|---|---|---|
| `employees.read` | ✅ | ✅ | ❌ | Lectura del listado/detalle. |
| `employees.create` | ✅ | ✅ | ❌ | Alta de colaborador. |
| `employees.update` | ✅ | ✅ | ❌ | Edición incluyendo cambio de estado de vinculación. |
| `employees.delete` | ✅ | ✅ | ❌ | Archivar colaborador. |
| `employees.view_salary` | ✅ | ✅ | ❌ | Revelar `pay_rate` y `base_salary`. Audita `employee.salary_viewed`. |
| `shifts.read` | ✅ | ✅ | ✅ (solo `/me`) | El empleado ve solo sus turnos vía `MeShiftController`. |
| `shifts.manage` | ✅ | ✅ | ❌ | Crear/editar/cancelar turnos. |
| `shifts.suggest` | ✅ | ✅ | ❌ | Disparar `ShiftSuggestionService`. |
| `workforce.reports` | ✅ | ✅ | ❌ | Reportes agregados por colaborador. |
| `workforce.settings` | ✅ | ✅ | ❌ | Jornada máxima y mínimo de días libres por empresa. |

Las features se siembran en `FeatureSeeder` (grupo Colaboradores / Planificador / Reportes). `EmployeesFeatureBackfillSeeder` (idempotente, dentro de `ProductionSeeder`) proyecta los permisos sobre los roles de sistema de empresas existentes. Empresas nuevas las reciben en `CompanyEnrollmentController`.

### Reglas de desactivación

`EmployeeVinculationPolicy::denialReason()` centraliza:

1. **REASON_SELF**: auto-desactivación prohibida.
2. **REASON_TARGET_IS_OWNER**: owner indesactivable mientras conserve rol `Propietario`.
3. **REASON_ADMIN_CANNOT_DEMOTE_OWNER**: admin no puede desactivar a un owner.
4. Owners pueden con cualquiera salvo a sí mismos y a otros owners.

Cada bloqueo audita `employee.vinculation_change_denied` con motivo + actor.

### Caja con turno activo

`ShiftActiveGuardService::assertActiveShift($user, $companyNit, $branchId)` se invoca en `CashRegisterController::open()` y `close()`. Verifica un `employee_shift` `scheduled` en la sede con `NOW()` dentro de la ventana. **Owner** y **admin** bypassan (responsabilidad supervisoria). Empleado y roles custom requieren turno activo.

---

## Endpoints

### Colaboradores

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/employees` | `employees.read,read` |
| POST | `/api/v1/employees` | `employees.create,create` |
| GET | `/api/v1/employees/{id}` | `employees.read,read` |
| PUT | `/api/v1/employees/{id}` | `employees.update,update` |
| POST | `/api/v1/employees/{id}/archive` | `employees.delete,delete` |
| POST | `/api/v1/employees/{id}/vinculation-state` | `employees.update,update` |
| GET | `/api/v1/employees/{id}/salary` | `employees.view_salary,read` |

### Cargos

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/employee-positions` | `employees.read,read` |
| POST | `/api/v1/employee-positions` | `employees.create,create` |
| DELETE | `/api/v1/employee-positions/{id}` | `employees.delete,delete` |

### Turnos

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/api/v1/shifts?from&to&branch_id&employee_id` | `shifts.read,read` | Ventana inclusiva (overlap-aware). |
| POST | `/api/v1/shifts` | `shifts.manage,create` | Valida overlap (422 si solapa). |
| PUT | `/api/v1/shifts/{id}` | `shifts.manage,update` | Rechaza editar `cancelled` (422). |
| POST | `/api/v1/shifts/{id}/cancel` | `shifts.manage,delete` | Soft-cancel con reason + note. |
| POST | `/api/v1/shifts/suggest` | `shifts.suggest,create` | Genera borrador para `week_start` + `branch_id` + slots de demanda. |

### Vista del colaborador (`/me`)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/me/shifts?from&to` | `shifts.read,read` |
| GET | `/api/v1/me/profile` | — (solo necesita perfil `employees` vinculado) |
| GET | `/api/v1/me/salary` | — (audita `employee.salary_viewed_self`) |

`MeShiftController` resuelve el `Employee` por `user_id` del JWT. Si el actor no tiene perfil HHRR vinculado, retorna 404.

---

## Flujos funcionales

### Alta de colaborador

1. Owner/admin abre `/employees/create`, completa identidad + contacto + seguridad social + contrato + pago.
2. `POST /api/v1/employees` valida con `StoreEmployeeRequest` (sanitización por `SanitizesInput`).
3. Si el `email` coincide con un user activo de la empresa, `user_id` se enlaza automáticamente.
4. Se audita `employee.created` con metadata (sede principal, cargo, contract_type).

### Asignación de turno (vista semana)

1. Usuario abre `/planner/week`, selecciona sede y rango (PeriodNavigator).
2. Click en celda (colaborador × día) abre `Dialog` con `starts_at`/`ends_at`.
3. `POST /api/v1/shifts` corre dentro de `DB::transaction` con `lockForUpdate` sobre el `Employee`. Valida overlap → 422 si colisiona.
4. Audita `shift.created`.

### Cancelación de turno

1. Click en chip del turno → `Dialog` con razón obligatoria (`sick`/`personal`/`emergency`/`other`) + nota opcional.
2. `POST /api/v1/shifts/{id}/cancel` corre con `lockForUpdate`. Rechaza si ya estaba `cancelled` (422).
3. Audita `shift.cancelled` con razón + nota.

### Cambio de estado de vinculación

1. Owner/admin en detalle del colaborador (`/employees/{id}`) cambia `vinculation_status` (e.g. a `vacation` con ventana).
2. `EmployeeVinculationPolicy::denialReason()` evalúa las 4 reglas; si bloquea, audita `employee.vinculation_change_denied` y responde 422.
3. Al aprobar, el sistema cancela en cascada los turnos `scheduled` cuya ventana cae dentro de `vinculation_valid_from`/`vinculation_valid_until` con `cancellation_reason='vinculation_state'`.

### Sugerencias automáticas

`POST /api/v1/shifts/suggest` recibe `week_start` + `branch_id` + `demand[]` (slots con `starts_at`/`ends_at`/`position_slug`). `ShiftSuggestionService::suggestForWeek` genera un borrador round-robin sobre horas acumuladas, respetando jornada máxima y mínimo de días libres por empresa (con override por empleado). Solo opera dentro de la sede principal del colaborador. La respuesta es un **draft** — el usuario lo revisa y aplica turno a turno con `POST /api/v1/shifts`.

---

## Componentes frontend

| Componente | Ruta | Propósito |
|---|---|---|
| `pages/planner/week.tsx` | `/planner/week` | Vista semanal: matriz colaboradores × días. KPIs de horas planificadas vs canceladas. `PeriodNavigator` para semana. `Dialog` de asignación con `Select` de empleado + inputs de hora. |
| `pages/planner/month.tsx` | `/planner/month` | Calendario mensual con `MonthCalendarGrid`. Cada día muestra horas totales asignadas. Click navega a vista semana de esa semana. |
| `pages/employees/index.tsx` | `/employees` | Listado con filtros (sede, cargo, estado), búsqueda por nombre/documento/email, paginación. |
| `pages/employees/create.tsx` | `/employees/create` | Formulario completo HHRR con secciones colapsables (Identidad, Contacto, Seguridad social, Contrato, Pago). |
| `pages/employees/show.tsx` | `/employees/{id}` | Detalle + edición + cambio de estado + revelar salario (icono ojo, audita acceso). |
| `pages/employees/reports.tsx` | `/employees/reports` | Tabla agregada por colaborador (horas asignadas/ejecutadas/canceladas + costo estimado). Export CSV/PDF. |
| `components/planner/planner-view-tabs.tsx` | — | Switcher semana ↔ mes. |
| `components/ui/period-navigator.tsx` | — | Navegación de período (anterior/siguiente/hoy). |
| `components/ui/month-calendar-grid.tsx` | — | Grid calendario mensual reutilizable. |
| `components/ui/stat-tile.tsx` | — | KPI compacto (horas planificadas / canceladas / netas). |
| `components/ui/editorial-empty.tsx` | — | Empty state cuando no hay turnos en la semana. |
| `components/ui/desktop-only-hint.tsx` | — | Hint en móvil — la vista de planner es desktop-first. |

### Vista del colaborador (autoservicio)

| Ruta | Componente | Notas |
|---|---|---|
| `/me/agenda` | `pages/me/agenda.tsx` | Agenda personal del colaborador. Sin permisos especiales (requiere perfil `employees` vinculado). |
| `/me/perfil` | `pages/me/profile.tsx` | Perfil + salario enmascarado con icono 👁 para revelar (audita `employee.salary_viewed_self`). |

---

## Eventos de auditoría

Todos vía `AuditService::log` con `branch_id` + `actor_active_branch_id` agregados automáticamente.

| Action | Disparador | Metadata |
|---|---|---|
| `employee.created` | `EmployeeController::store` | `branch_id`, `position_id`, `contract_type` |
| `employee.updated` | `EmployeeController::update` | `changes` (before/after) |
| `employee.archived` | `EmployeeController::archive` | `employee_id`, `archived_at` |
| `employee.vinculation_changed` | `EmployeeController::changeVinculationState` | `before`, `after`, `valid_from`, `valid_until`, `shifts_cancelled` |
| `employee.vinculation_change_denied` | Policy bloquea | `reason` (REASON_SELF / TARGET_IS_OWNER / ADMIN_CANNOT_DEMOTE_OWNER) |
| `employee.salary_viewed` | `viewSalary` (owner/admin) | `employee_id` |
| `employee.salary_viewed_self` | `MeShiftController::viewSalary` | — |
| `shift.created` | `ShiftController::store` | `employee_id`, `starts_at`, `ends_at` |
| `shift.updated` | `ShiftController::update` | `changes` map |
| `shift.cancelled` | `ShiftController::cancel` | `reason`, `note` |
| `shift.suggested` | `ShiftController::suggest` | `branch_id`, `week_start`, `assigned`, `unassigned`, `warnings` |

---

## Edge cases y empty states

- **Turno solapado**: `POST /api/v1/shifts` valida overlap contra turnos `scheduled` del mismo empleado y rechaza con 422 `El colaborador ya tiene un turno en esa franja.`
- **Cruce de medianoche**: `ends_at > starts_at` se acepta aunque `ends_at` caiga en el día siguiente. La query `scopeBetween` usa `starts_at < $to AND ends_at > $from` (overlap clásico).
- **Editar turno cancelado**: rechazado con 422 `Un turno cancelado no se puede editar; crea uno nuevo.` Mantiene inmutabilidad de trazabilidad.
- **Re-cancelar turno**: rechazado con 422 `El turno ya está cancelado.`
- **Colaborador sin perfil HHRR**: `MeShiftController` retorna 404 si el actor no tiene `Employee` vinculado por `user_id`. Frontend muestra mensaje "No tenés perfil de colaborador en esta empresa."
- **Sin turnos en la semana** (`/planner/week`): `EditorialEmpty` con CTA "Asignar primer turno" + sugerencia de usar el botón Sugerir.
- **Sin colaboradores**: empty state con CTA "Crear colaborador" → `/employees/create`.
- **Sede archivada después de asignar turnos**: los turnos persisten (auditoría). El planificador filtra sedes activas en el selector pero los turnos históricos siguen visibles en reportes.
- **Salario sin permiso** (`employees.view_salary` faltante): el campo se renderiza enmascarado (`••••••`) y el icono ojo se oculta. El endpoint `/salary` responde 403 si se intenta acceder vía API.
- **Cambio de estado a `vacation`/`sick_leave`/`compensatory`** con ventana que abarca turnos `scheduled`: cascada automática de cancelación con razón `vinculation_state`. Si la ventana no abarca ningún turno, la operación es no-op silenciosa.

---

## Fuera de alcance (estado actual)

Documentado en `FUNCIONALIDADES_APP.md §13` como pendiente:

- Notificaciones (in-app / email / WhatsApp) al asignar o cancelar turnos.
- Self-service del colaborador (solicitar cambio, días libres).
- Check-in / check-out explícito (la validación por hora actual basta).
- Integración de nómina (prestaciones, parafiscales, retención).
- Definición de demanda por sede precargada (hoy el admin la define al disparar `suggest`).
