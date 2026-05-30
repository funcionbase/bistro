# Multi-tenant + multi-sede — guía operativa

> **Audiencia**: cualquier desarrollador o agente que toque código que respire datos
> operativos (órdenes, caja, inventario, menú, chats, etc.) y necesite respetar
> el aislamiento por sede sin romper la vista global de empresa.
>
> **TL;DR**: una fila operativa siempre vive en (`company_nit`, `branch_id`). El
> scope global la filtra automáticamente por la sede activa del JWT. Salirse del
> scope sin justificación rompe la promesa al usuario.

## 1. Modelo dual: `company_nit` + `branch_id`

| Eje | Identificador | Propósito |
|-----|---------------|-----------|
| Empresa | `companies.nit` (DIAN) | Identidad tributaria, marca, legales, billing. Inmutable post-creación (#193). El plan vive en `subscriptions` (#257). |
| Sede | `branches.id` (UUID) | Unidad operativa: caja, mesas, menú, inventario, KDS, reportes. |

- Las tablas globales (`companies`, `users`, `company_roles`, `permissions`,
  `subscriptions`, etc.) **no** tienen `branch_id`. Son compartidas dentro de
  la empresa.
- Las tablas operativas (29+ y creciendo) tienen `branch_id uuid NOT NULL`,
  FK a `branches.id` con `onDelete restrict`. La sede NO se borra; se
  archiva (`branches.archived_at`).
- `branches.is_default = true` marca una única sede como default por empresa.
  Si no existe ninguna activa, el frontend muestra un banner accionable
  (`MissingBranchBanner`) y los endpoints operativos devuelven 422.

## 2. Cómo entra una fila operativa con `branch_id`

`App\Models\Concerns\BelongsToBranch`:

1. Aplica un `BranchScope` global que filtra `WHERE branch_id = $request->attributes->get('active_branch_id')` automáticamente.
2. En el evento `creating`, si la fila no trae `branch_id`, lo toma del
   request attribute. Si no hay request HTTP (consola/seeder/job), lo toma de
   `app('belongs_to_branch.seeder_branch_id')` que setea
   `BelongsToBranch::setSeederBranch($branchId)`.
3. Si ni el request ni el container tienen branch_id, la fila se queda
   `NULL` y la BD lanza constraint violation — preferimos fallar ruidoso a
   crear huérfanos silenciosos.

```php
class Order extends Model {
    use BelongsToBranch; // listo, hereda scope + autopopulate.
}
```

## 3. Pipeline de middleware

Para un endpoint API operativo, el orden esperado es:

```php
Route::middleware([
    'jwt',                        // ValidateJwt — carga jwt_payload
    'company.access',             // EnsureCompanyAccess — verifica acceso a la empresa
    'company.verified',           // EnsureCompanyVerified — bloquea unverified
    'company.not_blocked',        // EnsureCompanyNotBlocked — bloquea suspended (#193)
    'branch.access',              // EnsureBranchAccess — inyecta active_branch_id
    'branch.consolidate',         // AllowConsolidatedBranches (opcional, ver §6)
    'permission:<feature>,<action>',
])->group(/* ... */);
```

Cada middleware deja sus resultados en `$request->attributes` para que el
scope global y los controllers los lean. **Nunca** leer `branch_id` desde
el JWT payload directamente: el middleware `branch.consolidate` puede
sobrescribirlo en runtime para soportar `?branch=<uuid>`.

## 4. JWT y resolución de sede activa

`App\Services\JwtService::issue($user, $companies, $activeCompanyNit, $activeBranchId)`:

- Si `$activeBranchId` es null, `resolveBranchContext` elige:
  1. La sede `is_default=true` no archivada (para non-owner solo si la tiene
     en `branch_users`).
  2. Si no, la primera por `created_at`.
- El payload lleva `active_branch_id` (uuid o null para consolidado) y
  `branches[]` con todas las sedes accesibles para el rol.

Cambio de sede en runtime: `POST /api/v1/auth/switch-branch { branch_id }`.
El endpoint:
1. Valida acceso vía pivot `branch_users` (owner bypass).
2. **Bloquea el switch si hay caja abierta** en la sede actual
   (#192 Fase 3.1), salvo permiso `cash_register.bypass_switch_lock`.
3. Reemite el JWT con la nueva sede.
4. Audita con `auth.branch.switched` (incluye `from_branch_id`, `to_branch_id`,
   `was_owner_bypass`).

## 5. Cómo agregar una nueva tabla operativa

1. **Migración**:
   ```php
   Schema::create('whatever', function (Blueprint $t) {
       $t->id();
       $t->string('company_nit'); // FK a companies.nit
       $t->uuid('branch_id');
       // ... otras columnas
       $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
       $t->index(['company_nit', 'branch_id']); // compuesto, evita seqscan
       $t->timestamps();
   });
   ```
2. **Modelo**: `use BelongsToBranch;` — listo, hereda scope + autopopulate.
3. **Factory** (si aplica): no setear `branch_id` explícito; el trait lo
   inyecta desde el seederBranch o del request.

## 6. Reportes consolidados

Middleware `branch.consolidate` (`AllowConsolidatedBranches`) intercepta el
query param `?branch`:

- `?branch=all` → si actor tiene `metrics.view_all_branches`, setea
  `active_branch_id=null` y `consolidated_branches=true`. BranchScope no filtra.
- `?branch=<uuid>` → si actor tiene `metrics.view_all_branches` y la sede
  pertenece a la empresa, sobreescribe `active_branch_id` para esa request.

`metrics.view_all_branches` se obtiene por tres rutas (#192 Fase 1.X):

1. **Owner** (`role.is_system=true`): bypass automático.
2. **Permiso explícito**: asignado al rol desde el editor.
3. **Cobertura total**: el usuario tiene acceso a TODAS las sedes activas
   vía `branch_users`. Si el owner crea una sede nueva y no agrega al
   usuario, pierde el privilegio.

La regla 3 se evalúa al emitir el JWT, no en cada request — es decir, si
cambia el set de sedes accesibles el usuario debe relogear o llamar a
`/auth/switch-branch` para refrescar permissions.

### Shape consolidado

Cuando `consolidated_branches=true` el controller debe devolver:

```json
{
  "scope": "consolidated",
  "summary": { /* totals consolidados */ },
  "per_branch": [
    { "branch_id": "...", "branch_name": "...", "totals": { /* ... */ } }
  ]
}
```

vs. el scope por sede:

```json
{
  "scope": "branch",
  "branch_id": "...",
  "summary": { /* ... */ }
}
```

El frontend usa `BranchComparisonTable` para renderizar `per_branch[]`.

## 7. Webhook WhatsApp y política de selección de sede

El chat es único por (`company_nit`, `client_phone`) — el `unique` constraint
lo garantiza. El `branch_id` del chat indica la sede que **lo atiende**:

- Al ingresar un mensaje de un teléfono **sin chat previo**: se asigna
  `branch_id = company.is_default branch`.
- Al ingresar un mensaje de un teléfono **con chat existente**: no se rebota
  su `branch_id` — la reasignación es manual.
- `POST /api/v1/chats/{chat}/reassign-branch` mueve el chat hacia otra
  sede. Requiere permiso `chats.reassign_branch` o ser owner, Y tener
  acceso (vía `branch_users`) a la sede destino.

## 8. Escape de scope (`withoutBranchScope()`)

Cada uso de `withoutBranchScope()` o `withoutGlobalScope(BranchScope::class)`
debe llevar un PHPDoc/comentario explicando POR QUÉ. Casos legítimos:

| Caso | Ejemplo |
|------|---------|
| Flujo público sin JWT | `TableJoinController`, `TableResolveController`, `MenuController` (menú QR), webhook WhatsApp |
| CRM cross-sede | `CrmService`, `ClientController::ensureClientExists` — un cliente es único a nivel empresa |
| Validaciones de uniqueness por empresa | `WarehouseController` (verificar única bodega default), `BranchController::copyMenu` (lookup de menú en otra sede) |
| Reportes consolidados con permiso | controllers protegidos por `branch.consolidate` |
| Jobs / consola sin contexto HTTP | `PurgeExpiredTableSessionsCommand`, `BranchesAuditOrphansCommand` |

Antipatrón: usar `withoutBranchScope()` para "evitar problemas" sin entender
la implicación. Cada escape expone datos cross-sede al actor de la query.

## 9. Auditoría de huérfanos

Comando `php artisan branches:audit-orphans` (#192 Fase 0):

- Recorre TODAS las tablas con columna `branch_id` (introspección
  dinámica sobre `information_schema`) y reporta huérfanos.
- Esperado: `0` huérfanos. El cron diario lo programa como canario en
  `routes/console.php`.
- Con `--fix-default` reasigna huérfanos a la sede `is_default` de cada
  empresa dentro de `DB::transaction` + audit log. **NO se programa
  automáticamente** — solo manual previa aprobación.
- `--json` emite reporte JSON para CI/monitoreo.

## 10. Rotación de empleados entre sedes

Distinto al pivot `branch_users` (acceso al sistema), los empleados
operativos tienen su propia estructura:

- `employees.primary_branch_id`: la sede "casa" del empleado.
- `employees_branches` (pivot): sedes adicionales donde puede trabajar.
- `employee_shifts.branch_id`: cada turno se programa en UNA sede concreta.

El planificador (#182) decide en qué sede genera el turno según la
disponibilidad del empleado en el pivot.

## 11. Inmutabilidad de `orders.branch_id`

`Order` tiene un guard en `static::updating`: si `isDirty('branch_id')`
lanza `LogicException`. Cambiar la sede de una orden creada rompe
reportes históricos, cierres de caja, movimientos de inventario y
auditorías sin forma honesta de reconstruir. Para mover una orden hay
que cancelar la original y crear una nueva — el rastro contable queda.

## 12. Anti-patrones

- ❌ Setear `branch_id` manualmente al insertar (el trait lo hace solo;
  duplicar = riesgo de inconsistencia).
- ❌ Actualizar `branch_id` de cualquier fila operativa con un
  `update(['branch_id' => $new])` (mejor: refactor a "duplicar fila en
  nueva sede + cancelar la anterior").
- ❌ Sumar en PHP lo que se puede en SQL — usar `GROUP BY branch_id` y
  `selectRaw('SUM(...)')`.
- ❌ Hardcodear listas de tablas con `branch_id` (preferir introspección
  dinámica como `BranchesAuditOrphansCommand`).
- ❌ Usar `withoutBranchScope()` sin justificación inline.

## Cambios futuros (out of scope)

- Transferencias de inventario cross-sede con doble asiento — `POST
  /api/v1/inventory/transfers/cross-branch`, permiso
  `inventory.transfer_cross_branch` (ya creado en #192 para asignación
  previa).
- Asignación masiva de chats por sede (bandeja "sin asignar",
  notificaciones al operador cuando recibe un chat reasignado).
- Reportes consolidados con UX comparativa avanzada (gráficas de aporte
  por sede en el dashboard).
- Branding por sede (logo, color) si surge demanda multi-marca.
- Alertas de seguridad por intentos no autorizados entre sedes.
