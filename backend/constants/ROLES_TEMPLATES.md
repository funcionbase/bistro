# Roles de plantilla (operativos, no system)

> **Fuente de verdad ejecutable**:
> `application/config/roles.php` (`role_names`, `role_colors`) +
> `application/database/seeders/PermissionTemplateSeeder.php` (maps de
> permisos por `role_type`) +
> `application/app/Console/Commands/SyncRoleTemplatesCommand.php`
> (comando `roles:sync-templates` que crea/sincroniza los roles en empresas
> existentes).
> Este archivo es **espejo de referencia** — el código gana en caso de drift.

---

## Para qué existen

El flujo de mesa con QR (#191 Fase 7) introdujo 3 roles operativos pensados
para staff de salón / cocina / caja, **sin** marcar `is_system=true`. Eso
significa:

- El owner los puede **renombrar / eliminar** desde el editor de roles.
- El owner puede **ajustar sus permisos** desde `/identities/roles`.
- **No tienen bypass** del RBAC: cada endpoint debe pasar el
  `permission:<slug>,<action>` correspondiente.
- Se crean **opcionalmente por empresa** vía `php artisan roles:sync-templates`
  (no se siembran automáticamente en onboarding). Idempotente.

## Catálogo

### Flujo de mesa con QR (#191 F7)

| `role_type` | Nombre localizado | Color | Para qué se diseñó |
|---|---|---|---|
| `waiter` | Mesero | `#0EA5E9` | Pantalla del mesero: aprobar/rechazar tandas, editar notas, resolver `cancellation_requests`, ver/responder chats. |
| `cook` | Cocinero | `#F97316` | KDS exclusivo: ver y operar la pantalla de cocina (`kds.read` + `kds.update`) y mover el estado de items (`orders.update`). |
| `cashier` | Cajero | `#16A34A` | Caja con pago dividido: ver órdenes, cerrar/refund, ver reportes propios. |

### Administrativos (#215 F4)

| `role_type` | Nombre localizado | Color | Para qué se diseñó |
|---|---|---|---|
| `manager` | Gerente | `#14B8A6` | Gerente operativo de una sede: cierra órdenes, ajusta menú, gestiona turnos, mueve inventario en su sede. NO toca contabilidad ni configuración de empresa. |
| `accountant` | Contador | `#475569` | Lectura financiera cross-sede (`metrics.view_all_branches`): billing, purchases, suppliers, workforce.reports, employees.view_salary, coupons/loyalty. Sin mutaciones. |
| `marketing` | Marketing | `#EC4899` | Cupones (CRUD parcial: read/create/update, no delete), loyalty (read/update), clientes (read/update), chats (responder). No toca operación ni contabilidad. |
| `inventory_manager` | Bodeguero | `#A16207` | CRUD sobre `inventory` + `warehouses.manage` + `suppliers` (read/create/update) + `purchases` (read/create/update/receive). **Sin** `purchases.pay`, `purchases.delete`, ni `inventory.transfer_cross_branch` (cross-sede sensible). |
| `supervisor` | Supervisor | `#6366F1` | Read-mostly operativo: turno entrante revisa órdenes, deliveries, shifts, inventory, chats, reports, employees, hours, clients. Solo `orders.update` y `deliveries.update` como mutaciones. |

## Defaults exactos (espejo del map en `PermissionTemplateSeeder`)

**Convención de la celda**: `R`=read, `C`=create, `U`=update, `D`=delete. `-` = no.

### `waiter`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `menu.read` | - | ✅ | - | - |
| `chats.read` | - | ✅ | - | - |
| `chats.update` | - | - | ✅ | - |
| `clients.read` | - | ✅ | - | - |
| `clients.update` | - | - | ✅ | - |
| `hours.read` | - | ✅ | - | - |
| `kds.read` | - | ✅ | - | - |

### `cook`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `menu.read` | - | ✅ | - | - |
| `kds.read` | - | ✅ | - | - |
| `kds.update` | - | - | ✅ | - |

### `cashier`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `menu.read` | - | ✅ | - | - |
| `reports.read` | - | ✅ | - | - |
| `clients.read` | - | ✅ | - | - |
| `kds.read` | - | ✅ | - | - |

### `manager`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `menu.read` | - | ✅ | - | - |
| `menu.update` | - | - | ✅ | - |
| `hours.read` | - | ✅ | - | - |
| `hours.update` | - | - | ✅ | - |
| `shifts.read` | - | ✅ | - | - |
| `shifts.manage` | ✅ | ✅ | ✅ | ✅ |
| `inventory.read` | - | ✅ | - | - |
| `inventory.update` | - | - | ✅ | - |
| `reports.read` | - | ✅ | - | - |
| `chats.read` | - | ✅ | - | - |
| `chats.update` | - | - | ✅ | - |
| `clients.read` | - | ✅ | - | - |
| `clients.update` | - | - | ✅ | - |
| `employees.read` | - | ✅ | - | - |
| `coupons.read` | - | ✅ | - | - |
| `loyalty.read` | - | ✅ | - | - |
| `kds.read` | - | ✅ | - | - |
| `kds.update` | - | - | ✅ | - |

### `accountant`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `reports.read` | - | ✅ | - | - |
| `billing.read` | - | ✅ | - | - |
| `metrics.view_all_branches` | - | ✅ | - | - |
| `purchases.read` | - | ✅ | - | - |
| `suppliers.read` | - | ✅ | - | - |
| `employees.read` | - | ✅ | - | - |
| `employees.view_salary` | - | ✅ | - | - |
| `workforce.reports` | - | ✅ | - | - |
| `coupons.read` | - | ✅ | - | - |
| `loyalty.read` | - | ✅ | - | - |
| `inventory.read` | - | ✅ | - | - |

### `marketing`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `coupons.read` | - | ✅ | - | - |
| `coupons.create` | ✅ | - | - | - |
| `coupons.update` | - | - | ✅ | - |
| `loyalty.read` | - | ✅ | - | - |
| `loyalty.update` | - | - | ✅ | - |
| `clients.read` | - | ✅ | - | - |
| `clients.update` | - | - | ✅ | - |
| `chats.read` | - | ✅ | - | - |
| `chats.update` | - | - | ✅ | - |

### `inventory_manager`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `inventory.read` | - | ✅ | - | - |
| `inventory.create` | ✅ | - | - | - |
| `inventory.update` | - | - | ✅ | - |
| `inventory.delete` | - | - | - | ✅ |
| `warehouses.manage` | ✅ | ✅ | ✅ | ✅ |
| `suppliers.read` | - | ✅ | - | - |
| `suppliers.create` | ✅ | - | - | - |
| `suppliers.update` | - | - | ✅ | - |
| `purchases.read` | - | ✅ | - | - |
| `purchases.create` | ✅ | - | - | - |
| `purchases.update` | - | - | ✅ | - |
| `purchases.receive` | - | - | ✅ | - |

> **No** se incluye `purchases.pay`, `purchases.delete` ni
> `inventory.transfer_cross_branch` por riesgo financiero / cross-branch.
> Si la empresa lo requiere, el owner los asigna manualmente vía
> `UserPermissionsEditor`.

### `supervisor`

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `deliveries.read` | - | ✅ | - | - |
| `deliveries.update` | - | - | ✅ | - |
| `shifts.read` | - | ✅ | - | - |
| `inventory.read` | - | ✅ | - | - |
| `chats.read` | - | ✅ | - | - |
| `reports.read` | - | ✅ | - | - |
| `employees.read` | - | ✅ | - | - |
| `hours.read` | - | ✅ | - | - |
| `clients.read` | - | ✅ | - | - |
| `kds.read` | - | ✅ | - | - |
| `kds.update` | - | - | ✅ | - |

> Cualquier slug **no listado** queda en `[false,false,false,false]` para
> el role_type correspondiente. Si se quiere cambiar el default, editar el
> map en `PermissionTemplateSeeder.php` **y** actualizar la tabla acá.

## Comando `roles:sync-templates`

```bash
# Crear/sincronizar los 3 roles en TODAS las empresas:
php artisan roles:sync-templates

# Solo en empresas específicas:
php artisan roles:sync-templates --company=900111222 --company=901333444

# Solo algunos roles:
php artisan roles:sync-templates --role=waiter,cashier

# Dry-run (no escribe, solo reporta):
php artisan roles:sync-templates --dry-run
```

- **Idempotente**: si el rol ya existe (match por `company_nit + name
  localizado`), reconcilia los permisos al template actual; no crea
  duplicados.
- **N-instance safe** si se programa: en `routes/console.php` debe llevar
  `->onOneServer()->withoutOverlapping()` (regla raíz `CLAUDE.md`).

## Cuándo agregar otro `role_type` de plantilla

Sigue el checklist completo en [`RBAC_CHECKLIST.md`](./RBAC_CHECKLIST.md)
sección "Agregar un rol de plantilla nuevo". Resumen:

1. `config/roles.php::role_names` + `role_colors`.
2. `PermissionTemplateSeeder.php`: agregar el `role_type` al `foreach` y
   declarar su map.
3. `SyncRoleTemplatesCommand`: agregar al default del flag `--role`.
4. Actualizar esta tabla.

## Pares espejo que deben mantenerse sincronizados

- `application/config/roles.php::role_names` ↔ `PermissionTemplateSeeder.php` (lista del `foreach`) ↔ tabla de arriba.
- `application/config/roles.php::role_colors` ↔ tabla de arriba.
- `PermissionTemplateSeeder.php::$waiterMap/$cookMap/$cashierMap/$managerMap/$accountantMap/$marketingMap/$inventoryManagerMap/$supervisorMap` ↔ los 8 sub-bloques de defaults en este `.md`.
- `SyncRoleTemplatesCommand::$signature` (default `--role`) ↔ catálogo arriba.

> Última revisión: 2026-05-19 (#215 F4 — agregados manager / accountant / marketing / inventory_manager / supervisor).
