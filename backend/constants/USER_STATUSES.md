# USER_STATUSES — Fuente única de verdad

> **Antes de modificar el enum `users.status`, lee este archivo.**
> **Después de modificar, actualiza este archivo + la migración + el modelo
> `User` + los tipos TS + los componentes de badge en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `application/database/migrations/0001_01_01_000000_create_foundation_tables.php:33-34` — **fuente única canónica** (enum BD)
- [ ] `application/app/Models/User.php:68-76` — helpers `isPendingEnrollment()`, `isActive()`
- [ ] `application/resources/js/types/index.ts:340` — tipo `UserStatus`
- [ ] `application/resources/js/components/users-table.tsx:32-44` — badge `userStatusBadge()`
- [ ] `application/app/Http/Controllers/Enrollment/UserEnrollmentController.php:48` — transición `pending_enrollment → active`
- [ ] `application/app/Http/Controllers/Api/Employees/EmployeeController.php:257+` — sincronización `users.status` con colaborador

---

## Estados de `users.status` (columna principal)

Una sola fila por usuario humano. Ciclo simple: el usuario nace `pending_enrollment` al ser creado vía Google OAuth o invitación, completa enrollment y pasa a `active`. El admin puede desactivar manualmente y volver a activar.

| status | Categoría | Label UI (es-CO) | Badge | Terminal | Notas |
|---|---|---|---|---|---|
| `pending_enrollment` | inicial | Pendiente | `variant="warning"` | no | Default al crearse. No tiene `first_name`, `last_name`, `cedula`. No puede operar la app, solo completar perfil. |
| `active` | operativo | Activo | `variant="safe"` | no | Único estado que permite operar. JWT se emite con este estado. |
| `inactive` | suspendido | Inactivo | `variant="outline"` muted | no | Acceso revocado por admin. Conserva data histórica. Puede reactivarse. |

Fuente: `database/migrations/0001_01_01_000000_create_foundation_tables.php:33`.

### Reglas de transición

1. **`pending_enrollment → active`**: única vía `POST /api/v1/enrollment/user` (`UserEnrollmentController::store`). Valida `first_name`, `last_name`, `cedula`, aceptaciones legales (`terms` + `privacy`).
2. **`active → inactive`**: vía admin desde `UsersTable` (`onToggleStatus`). Requiere permiso `users.update` o ser owner (`is_system=true`). NO se permite auto-desactivarse (`user.id !== currentUserId` en `confirmToggleStatus`).
3. **`inactive → active`**: misma vía que el toggle inverso. No requiere re-enrollment.
4. **`pending_enrollment → inactive`** y **`inactive → pending_enrollment`**: NO permitidas. Si un usuario abandona el enrollment, queda en `pending_enrollment` permanentemente (no se le aplica `inactive`).

### Distinción importante: `users.status` vs `company_users.status`

- `users.status` — estado del **usuario humano**. Global, una sola empresa o varias.
- `company_users.status` (`'active' | 'inactive'`) — estado del **membership** del usuario en una empresa específica. Se usa para "desvincular sin borrar" sin tocar `users.status`.

El frontend pinta los dos por separado en `UsersTable`: columna "Estado" muestra `user.status`, columna "Acceso" muestra `member.status`.

---

## Impacto RBAC

- **Permiso para mutar**: `users.update` (slug del catálogo) — owner bypass siempre.
- **Auto-desactivación bloqueada**: `user.id !== currentUserId` en frontend; backend valida lo mismo en el endpoint.
- **Owner inviolable**: si el usuario es el **único owner** de la empresa, no se puede desactivar (regla "último-owner" — `application/constants/ROLES_SYSTEM.md`).
- **Auditoría**: cada cambio dispara `AuditService::log('user.status_changed', ...)` con `from`, `to`, `actor_id`, `actor_active_branch_id`.

---

## Cómo añadir un estado nuevo

1. Editar la migración foundation O crear una migración nueva que altere el enum (PostgreSQL: drop CHECK constraint y crear uno nuevo con todos los valores).
2. Añadir el helper en `User.php` (`isXxx()`).
3. Editar `UserStatus` en `resources/js/types/index.ts`.
4. Añadir caso `case '<nuevo>':` en `userStatusBadge()` de `users-table.tsx`.
5. Documentar transiciones permitidas (con/sin permiso, auditoría).
6. Actualizar este `.md` (tabla + reglas).
7. PR descripción: "Nuevo estado `<x>`. Transiciones permitidas: <desde/a>. Permiso requerido: <slug>. Audita: sí/no."

---

## Divergencias / deuda detectadas (al 2026-05-18)

- ✅ **Resuelto en este PR (#203)**: `types/index.ts:340` declaraba `'active' | 'pending_enrollment'`, faltaba `'inactive'` — fila huérfana porque el backend SÍ lo persiste. Badge de `users-table.tsx` caía al default genérico cuando un user estaba `inactive`. **Fixed**: tipo `UserStatus` ahora incluye `'inactive'` + badge dedicado con label "Inactivo".

---

## Referencias cruzadas

- `application/database/migrations/0001_01_01_000000_create_foundation_tables.php:33` — fuente canónica.
- `application/app/Models/User.php:68-76` — helpers.
- `application/resources/js/types/index.ts:340` — tipo TS.
- `application/resources/js/components/users-table.tsx:32-44` — badge UI.
- `application/constants/PERMISSIONS_CATALOG.md` — permiso `users.update`.
- `application/constants/ROLES_SYSTEM.md` — regla último-owner.

> Última revisión: 2026-05-18 (#203)
