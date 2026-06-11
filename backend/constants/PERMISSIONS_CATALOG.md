# Catálogo de permisos (slugs)

> **Fuente de verdad ejecutable**:
> `application/database/seeders/FeatureSeeder.php` (catálogo) +
> `application/database/seeders/PermissionTemplateSeeder.php` (defaults por
> `role_type`).
> Este archivo es **espejo de referencia humana** — se actualiza manualmente
> en el mismo PR que agrega/renombra/elimina un slug. El código gana en caso
> de drift.

---

## Convención de la celda

- `R` = `can_read`, `C` = `can_create`, `U` = `can_update`, `D` = `can_delete`.
- `RCUD` = los 4. `R---` = solo read. `----` = ninguno.
- `OO` (en columna `owner_only`) = `features.is_owner_only=true`: no se otorga
  a `admin` ni a `employee` por seeder, y solo aparece en `UserPermissionsEditor`
  cuando el actor es owner.
- Para `waiter`/`cook`/`cashier`/`manager`/`accountant`/`marketing`/`inventory_manager`/`supervisor`
  ver [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md) (defaults exactos por map).
- Para `Domiciliario`/`Cocina` ver [`ROLES_DEMO.md`](./ROLES_DEMO.md).

### Labels UI (presentación frontend)

Los labels en es-CO de cada acción (`Crear`, `Leer`, `Actualizar`, `Eliminar`)
viven en `application/config/rbac.php` (#203) y se exponen vía Inertia shared
prop `rbacActions`. El componente `permissions-matrix.tsx` los consume desde
ahí en lugar de declarar el array de tuplas (key, label) a mano. Si se agrega
una columna nueva al modelo `permissions` (raro), actualizar:

1. La migración de `permissions` (columna `can_<nueva>`).
2. `config/rbac.php` (slug + label).
3. Este archivo (definición de la convención de celda).

**Total slugs activos**: 82 (74 + 8 de #115 KDS: `kds.{read,create,update,delete}` +
`kds_stations.{read,create,update,delete}`).

**Total `role_type` sembrados** en `PermissionTemplateSeeder`: 11 (`owner`, `admin`,
`employee` + flujo mesa QR: `waiter`, `cook`, `cashier` + administrativos #215 F4:
`manager`, `accountant`, `marketing`, `inventory_manager`, `supervisor`).

---

## Órdenes

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `orders.read` | RCUD | RCUD | R--- | |
| `orders.create` | RCUD | RCUD | ---- | |
| `orders.update` | RCUD | RCUD | ---- | |
| `orders.delete` | RCUD | RCU- (no D para `orders`/`users`/`roles`) | ---- | |

## Cocina (KDS #115)

Dos features siguiendo el patrón canónico CRUD del proyecto.

**Operación cocinero** — `kds`:

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `kds.read` | RCUD | RCUD | ---- | |
| `kds.create` | RCUD | RCUD | ---- | (reservado v1 — sin uso operativo) |
| `kds.update` | RCUD | RCUD | ---- | |
| `kds.delete` | RCUD | RCUD | ---- | (reservado v1) |

**Gestión admin de estaciones + device-tokens** — `kds_stations` (sensible de
sede: default `[false,false,false,false]` para admin, asignable manualmente
por owner — mismo patrón que `cash_register.bypass_switch_lock`,
`inventory.transfer_cross_branch`, `chats.reassign_branch`):

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `kds_stations.read` | RCUD | ---- | ---- | (sensible) |
| `kds_stations.create` | RCUD | ---- | ---- | (sensible) |
| `kds_stations.update` | RCUD | ---- | ---- | (sensible) |
| `kds_stations.delete` | RCUD | ---- | ---- | (sensible) |

> Templates operativos: `cook` con `kds.read`=R--- + `kds.update`=--U-.
> `manager` y `supervisor` idem. `waiter` y `cashier` solo `kds.read`=R---.
> `accountant`/`marketing`/`inventory_manager` sin acceso por default. Ver
> [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md).

## Menú

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `menu.read` | RCUD | RCUD | ---- | |
| `menu.create` | RCUD | RCUD | ---- | |
| `menu.update` | RCUD | RCUD | ---- | |
| `menu.delete` | RCUD | RCUD | ---- | |

## Horarios

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `hours.read` | RCUD | RCUD | ---- | |
| `hours.update` | RCUD | RCUD | ---- | |

## Entregas (delivery / courier)

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `deliveries.read` | RCUD | RCUD | ---- | |
| `deliveries.create` | RCUD | RCUD | ---- | |
| `deliveries.update` | RCUD | RCUD | ---- | |
| `deliveries.delete` | RCUD | RCUD | ---- | |
| `deliveries.self_assign` | RCUD | RCUD | ---- | |

> `deliveries.self_assign` activa el modo *courier-only* cuando es el único
> permiso "operativo" del rol. Ver [`COURIER_MODE.md`](./COURIER_MODE.md).

## Cupones

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `coupons.read` | RCUD | RCUD | ---- | |
| `coupons.create` | RCUD | RCUD | ---- | |
| `coupons.update` | RCUD | RCUD | ---- | |
| `coupons.delete` | RCUD | RCUD | ---- | |

## Chats

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `chats.read` | RCUD | RCUD | R--- | |
| `chats.update` | RCUD | RCUD | ---- | |
| `chats.reassign_branch` | RCUD | ---- | ---- | (sensible de sede #192 — asignable manualmente) |

## Clientes

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `clients.read` | RCUD | RCUD | R--- | |
| `clients.create` | RCUD | RCUD | ---- | |
| `clients.update` | RCUD | RCUD | ---- | |
| `clients.delete` | RCUD | RCUD | ---- | |

## Fidelización

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `loyalty.read` | RCUD | RCUD | ---- | |
| `loyalty.update` | RCUD | RCUD | ---- | |

## Colaboradores

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `employees.read` | RCUD | RCUD | ---- | |
| `employees.create` | RCUD | RCUD | ---- | |
| `employees.update` | RCUD | RCUD | ---- | |
| `employees.delete` | RCUD | RCUD | ---- | |
| `employees.view_salary` | RCUD | RCUD | ---- | |
| `workforce.settings` | RCUD | RCUD | ---- | |

## Planificador (turnos)

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `shifts.read` | RCUD | RCUD | R--- (#182 para `/me/agenda`) | |
| `shifts.manage` | RCUD | RCUD | ---- | |
| `shifts.suggest` | RCUD | RCUD | ---- | |

## Inventario

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `inventory.read` | RCUD | RCUD | ---- | |
| `inventory.create` | RCUD | RCUD | ---- | |
| `inventory.update` | RCUD | RCUD | ---- | |
| `inventory.delete` | RCUD | RCUD | ---- | |
| `inventory.transfer_cross_branch` | RCUD | ---- | ---- | (sensible de sede #192 — asignable manualmente) |
| `warehouses.manage` | RCUD | RCUD | ---- | |
| `warehouses.assign_branches` | -U-- | -U-- | ---- | (config cross-sede #costeo-multibodega — asignar/desasignar bodegas a sedes + default por sede) |

## Compras (suppliers + purchase orders)

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `suppliers.read` | RCUD | RCUD | ---- | |
| `suppliers.create` | RCUD | RCUD | ---- | |
| `suppliers.update` | RCUD | RCUD | ---- | |
| `suppliers.delete` | RCUD | RCUD | ---- | |
| `purchases.read` | RCUD | RCUD | ---- | |
| `purchases.create` | RCUD | RCUD | ---- | |
| `purchases.update` | RCUD | RCUD | ---- | |
| `purchases.receive` | RCUD | RCUD | ---- | |
| `purchases.pay` | RCUD | RCUD | ---- | |
| `purchases.delete` | RCUD | RCUD | ---- | |

## Caja

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `cash_register.bypass_switch_lock` | RCUD | ---- | ---- | (sensible de sede #192 — asignable manualmente) |

## Sedes (multi-sede #117 / #192)

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `branches.manage` | RCUD | RCUD | ---- | |
| `branches.assign_users` | RCUD | RCUD | ---- | |
| `branches.copy_menu` | RCUD | RCUD | ---- | |
| `branches.view_all` | RCUD | RCUD | ---- | |

## Reportes / métricas

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `reports.read` | RCUD | R--- | R--- | |
| `metrics.view_all_branches` | RCUD | RCUD | ---- | Sin `OO` pero **owner-bypass + lógica derivada**: ver `FeaturePermissionService::userCanViewConsolidated` (cobertura total de sedes lo activa automáticamente). |
| `workforce.reports` | RCUD | RCUD | ---- | |

## Empresa / facturación / usuarios y roles

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `company.update` | RCUD | -CU- | ---- | |
| `company.fiscal_profile` | RCUD | ---- | ---- | (owner-only por template) |
| `billing.read` | RCUD | R--- | ---- | |
| `users.read` | RCUD | RCU- (sin D) | ---- | |
| `users.update` | RCUD | RCU- (sin D) | ---- | |
| `roles.create` | RCUD | RCU- (sin D) | ---- | |
| `roles.read` | RCUD | RCU- (sin D) | ---- | |
| `roles.update` | RCUD | RCU- (sin D) | ---- | |
| `roles.delete` | RCUD | RCU- (sin D) | ---- | |

> Nota sobre `admin`: para los grupos `roles` y `users` el default omite
> `can_delete` (`in_array($group, ['roles','users'], true) ? false : true`).
> Para borrar un rol o un usuario hace falta `owner`.

## WhatsApp

| Slug | owner | admin | employee | owner_only |
|---|---|---|---|---|
| `whatsapp.read` | RCUD | -R-- | ---- | |
| `whatsapp.connect` | RCUD | CR-- | ---- | |
| `whatsapp.update` | RCUD | -RU- | ---- | |
| `whatsapp.swap_phone` | RCUD | ---- | ---- | **OO** (`is_owner_only=true`) |
| `whatsapp.disconnect` | RCUD | ---- | ---- | **OO** (`is_owner_only=true`) |

---

## Notificaciones push (#149)

| Slug | Owner | Admin | Empleado / templates | Notas |
|---|---|---|---|---|
| `notifications.read` | RCUD | RCUD | RCUD | Self-service — todos los `role_type` reciben `[true,true,true,true]`. |
| `notifications.create` | RCUD | RCUD | RCUD | Suscribir un dispositivo. |
| `notifications.update` | RCUD | RCUD | RCUD | Toggle por tipo (futuro: pending / inventario por separado). |
| `notifications.delete` | RCUD | RCUD | RCUD | Revocar sub propia. |

> **No existe un permiso "enviar push a otros"**. El sistema decide
> destinatarios según permisos OPERATIVOS (`orders.update` para
> pending; `reports.read` o `inventory.read` para inventario digest).
> Esto evita la explosión combinatoria y alinea con la regla de mínima
> sorpresa: si podés operar sobre la cosa, te avisamos.
>
> Catálogo de tipos y payloads:
> [`NOTIFICATIONS.md`](./NOTIFICATIONS.md).

---

## Facturación electrónica DIAN (#235)

| Slug | owner | admin | cashier | waiter | manager | supervisor | accountant | owner_only |
|---|---|---|---|---|---|---|---|---|
| `dian.config.read` | RCUD | R--- | ---- | ---- | ---- | ---- | R--- | (sensible) |
| `dian.config.write` | RCUD | ---- | ---- | ---- | ---- | ---- | ---- | (sensible, owner-only por seeder) |
| `dian.default_recipient.write` | RCUD | ---- | ---- | ---- | ---- | ---- | ---- | (sensible, owner-only) |
| `dian.documents.read` | RCUD | R--- | R--- | R--- | R--- | R--- | R--- | |
| `dian.documents.emit` | RCUD | C--- | C--- | ---- | C--- | C--- | ---- | |
| `dian.documents.credit_note` | RCUD | C--- | ---- | ---- | C--- | ---- | ---- | (sensible — anula factura aceptada) |
| `dian.documents.retry` | RCUD | --U- | ---- | ---- | --U- | ---- | ---- | |
| `dian.recipients.read` | RCUD | R--- | R--- | ---- | R--- | R--- | R--- | |
| `dian.recipients.write` | RCUD | --U- | --U- | ---- | --U- | ---- | ---- | |
| `dian.print` | RCUD | --U- | --U- | --U- | --U- | --U- | ---- | |

> Resumen RBAC: owner full RCUD. Admin recibe la mayoría EXCEPTO
> `dian.config.write` y `dian.default_recipient.write` (cambiar
> credenciales del provider o adquirente por defecto — sensible). Cashier
> emite documentos, completa perfiles de contactos al vuelo, imprime.
> Waiter ve listado (consultar estado del doc de la mesa atendida) y
> reimprime tirilla. Manager respalda a caja (emite, nota crédito,
> reintenta, completa perfiles). Supervisor refuerza en horas pico
> (emite + reimprime sin nota crédito). Accountant solo lectura para
> conciliación contable.
>
> Aislamiento por sede: el listado `/dian/documents` y el endpoint
> `GET /api/v1/dian/documents` ya filtran por `branch_id` del JWT activo;
> un cashier de Pereira jamás ve documentos de Cartago.
>
> **Mock provider**: el seeder demo crea `DianProviderConfig` con
> `provider_slug='mock'` + `environment='habilitacion'`. Para PDN se
> contrata Factura1/Siigo/etc. y se cambia desde la UI (rotación
> auditada en `dian.provider.updated`).

---

## Permisos `is_owner_only` (catálogo)

| Slug | Razón |
|---|---|
| `whatsapp.swap_phone` | Cambiar número conectado libera el actual + relanza Embedded Signup. Acción irreversible para el negocio. |
| `whatsapp.disconnect` | Desconectar libera el número en Meta + soft-delete. Acción irreversible. |
| `dian.config.write` | Tocar credenciales del provider DIAN + clave técnica de la resolución es acción legalmente sensible (#235). Owner-only por defecto (no marcado con `is_owner_only=true` en BD, sino vía seeder template: admin recibe `[false,false,false,false]`). |
| `dian.default_recipient.write` | Cambiar el adquirente por defecto desvía toda emisión automática hacia otro NIT — riesgo contable. Owner-only por seeder. |

> Si se agrega un nuevo `is_owner_only=true`, debe documentarse en esta
> sección **y** en el `.md` del dominio.

## Permisos sensibles de sede (no `OO`, pero default owner-only)

Los siguientes NO llevan `is_owner_only=true` (sí aparecen en
`UserPermissionsEditor`), pero `PermissionTemplateSeeder` los deja
`[false,false,false,false]` para `admin` y `employee`. El owner los puede
asignar manualmente a admin si quiere delegarlos:

- `chats.reassign_branch` (#192)
- `cash_register.bypass_switch_lock` (#192)
- `inventory.transfer_cross_branch` (#192)
- `kds_stations.*` (#115 — afecta dispositivos físicos en cocina; los 4 slugs CRUD)

Más detalle: [`BRANCH_RBAC.md`](./BRANCH_RBAC.md).

## Pares espejo que deben mantenerse sincronizados

- `FeatureSeeder.php` ↔ tablas de este `.md` (slug nuevo / renombrado / eliminado).
- `PermissionTemplateSeeder.php` ↔ columnas owner/admin/employee.
- `config/roles.default_employee_permissions` ↔ celdas `employee=R---`.
- `Feature::where('is_owner_only', true)` ↔ sección "Permisos `is_owner_only`".

## Vista "por permiso" — dónde se valida cada slug

Este archivo es **catálogo + defaults**. Para responder *"¿dónde vive la validación, UI y auditoría de un permiso concreto?"*, consultá en este orden:

- [`MIDDLEWARE_MAP.md`](./MIDDLEWARE_MAP.md) — alias `permission:<slug>,<action>` y middlewares cruzados (`EnsureCompanyAccess`, `EnsureBranchAccess`, etc.). Para saber qué middleware corre antes de aplicar el permiso.
- [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) — qué acciones emiten `AuditService::log` y con qué `data` mínimo. Mapa permiso↔acción auditada (no siempre 1:1).
- [`FEATURES_INDEX.md`](./FEATURES_INDEX.md) — para un dominio funcional dado (Órdenes, Compras, WhatsApp, etc.), qué controllers backend y qué páginas frontend lo consumen. Útil cuando el permiso es uno de varios del módulo.
- [`RBAC_CHECKLIST.md`](./RBAC_CHECKLIST.md) — escenario #1 lista paso a paso dónde tocar al introducir un permiso (incluye validación backend, UI y audit).

> Última revisión: 2026-05-18 (#201) — 74 slugs, 2 `is_owner_only`, 3 sensibles de sede. Sección "Vista por permiso" agregada en #202.
