# Mesas — Sesiones de mesa

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Gestión del servicio en sala con **cuenta abierta por mesa física**. Cubre
dos superficies operativas complementarias:

1. **Grilla de mesas** (`/orders/tables`, `pages/orders/tables/index.tsx`):
   vista rápida disponibles vs ocupadas, para abrir una mesa nueva o
   ingresar a su detalle.
2. **Sesión grupal de mesa** (`/orders/table-sessions`, `show` con detalle):
   modelo `TableSession` introducido en #191, soporta múltiples comensales
   con cuenta compartida, aprobaciones por tanda del mesero, pago dividido
   por el cajero y cancelaciones controladas.

Está pensada para flujos paisas reales: el comensal escanea el QR de la
mesa (ver `Carrito-Publico.md`) o un mesero abre la mesa manualmente desde
la grilla. La caja debe estar **abierta** para operar (ver `Caja-POS.md`).

---

## Modelo de datos

| Tabla | Campos clave | Notas |
|---|---|---|
| `tables` | `id` (UUID), `company_nit`, `branch_id`, `number` (string), `qr_token` (Str::random(40)), `seats`, `archived_at` | UNIQUE parcial por (company_nit, branch_id, number) cuando no archivada. QR regenerable. |
| `table_sessions` | `id`, `table_id`, `company_nit`, `branch_id`, `opened_at`, `expires_at`, `closed_at`, `status`, `accepts_new_guests` (bool) | UNIQUE parcial: 1 sola sesión activa por `table_id`. `status` ∈ `open`/`locked`/`closed`/`expired`. `active_statuses` viene de `config('tables.active_statuses')`. |
| `table_session_guests` | `id`, `table_session_id`, `display_name`, `phone`, `client_uuid`, `joined_at` | Identifica a cada comensal del grupo. |
| `orders` | `table_session_id` (FK nullable), `order_type='table'`, `status` (`pending_approval` para buffer, luego `pending`/`in_kitchen`/…) | Una sesión puede tener: 1 buffer (`status=pending_approval`) + N órdenes aprobadas. |
| `order_items` | `status` (`pending`/`pending_approval`/`approved`/`rejected`/`cancelled`/`served`), `notes`, `cancellation_reason` | Aprobación granular por ítem (mesero). |

Estados de la sesión:

| Estado | Descripción |
|---|---|
| `open` | Acepta nuevos comensales y nuevos ítems en buffer. |
| `locked` | Tras aprobar la primera tanda. Sigue aceptando ítems si `accepts_new_guests=true`, pero la mesa se ve "ocupada". |
| `closed` | Todo cobrado, mesa vuelve a disponible. |
| `expired` | Job programado cerró por inactividad sin pago. |

---

## Permisos RBAC

| Slug | owner | admin | waiter | cashier | manager | supervisor |
|---|---|---|---|---|---|---|
| `orders.read` | RCUD | RCUD | R--- | R--- | R--- | R--- |
| `orders.create` | RCUD | RCUD | RC-- | RC-- | RC-- | RC-- |
| `orders.update` | RCUD | RCUD | -CU- | -CU- | -CU- | -CU- |
| `company.update` (admin de mesas físicas) | RCUD | RCUD | ---- | ---- | ---- | ---- |

- Grilla `/orders/tables` y `/orders/table-sessions`: gate `orders.read`.
- Aprobar/rechazar tandas, editar notas, cerrar mesa vacía: `orders.update`.
- Cobrar (pago dividido / pago total / refund): `orders.update` + caja `open`.
- CRUD del catálogo `tables` (números físicos, QR, sillas): `company.update`.

---

## Endpoints

### Grilla rápida (Mesas)

| Método | Ruta | Auth/Middleware | Permiso | Descripción |
|---|---|---|---|---|
| `GET` | `/api/v1/orders/tables` | `jwt` + `company.access` + `branch.access` | `orders.read` | Estado actual de cada mesa (disponible/ocupada + total). Polling 8s. |

### Sesiones grupales

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/table-sessions` | `orders.read` | Lista sesiones activas (`status ∈ open|locked`). |
| `GET` | `/api/v1/table-sessions/{id}` | `orders.read` | Detalle: guests, buffer, órdenes aprobadas, items_by_status, recibos. |
| `GET` | `/api/v1/table-sessions/billable` | `orders.read` | Sesiones con saldo cobrable (para vista cajero). |
| `GET` | `/api/v1/orders/pending-approvals` | `orders.read` | Tandas buffer esperando aprobación del mesero. |
| `GET` | `/api/v1/orders/pending-cancellations` | `orders.read` | Solicitudes de cancelación del comensal pendientes de decisión. |
| `POST` | `/api/v1/table-sessions/{id}/approve-batch` | `orders.update` | Aprueba toda la buffer (`pending_approval` → `pending`). Crea orden nueva. |
| `POST` | `/api/v1/table-sessions/{id}/items/{item}/reject` | `orders.update` | Rechaza un ítem del buffer. |
| `POST` | `/api/v1/table-sessions/{id}/items/{item}/cancel` | `orders.update` | Decide solicitud de cancelación de un ítem ya aprobado. |
| `PATCH` | `/api/v1/table-sessions/{id}/items/{item}/notes` | `orders.update` | Edita notas del ítem (cocina). |
| `POST` | `/api/v1/table-sessions/{id}/notes` | `orders.update` | Agrega nota al grupo o alerta a cocina. |
| `POST` | `/api/v1/table-sessions/{id}/close-empty` | `orders.update` | Cierra sesión sin ítems (cliente se fue). |
| `POST` | `/api/v1/table-sessions/{id}/accepts-new-guests` | `orders.update` | Toggle `accepts_new_guests`. |

### Cobro por mesa (#191 Fase 6)

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/caja/table-sessions/{id}` | `orders.read` | Detalle para cobro (items + saldo + receipts emitidos). |
| `POST` | `/api/v1/caja/table-sessions/{id}/pay-partial` | `orders.update` | Pago parcial (por ítem o por comensal). |
| `POST` | `/api/v1/caja/table-sessions/{id}/pay-all` | `orders.update` | Cobra el remanente completo. |
| `POST` | `/api/v1/caja/table-sessions/{id}/refund-item` | `orders.update` | Devuelve un ítem específico ya pagado. |

### Admin de mesas físicas (#191 Fase 8)

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/tables` | `company.update,read` | Catálogo de mesas (incluye archivadas). |
| `POST` | `/api/v1/tables` | `company.update,update` | Crea mesa (genera `qr_token` Str::random(40)). |
| `PATCH` | `/api/v1/tables/{id}` | `company.update,update` | Edita `number`, `seats`. |
| `DELETE` | `/api/v1/tables/{id}` | `company.update,update` | Soft-archive (`archived_at`). |
| `POST` | `/api/v1/tables/{id}/restore` | `company.update,update` | Reactiva mesa archivada. |
| `POST` | `/api/v1/tables/{id}/regenerate-qr` | `company.update,update` | Reemite `qr_token` (invalida QR impresos). |

Controladores: `App\Http\Controllers\Api\TableSessionController`,
`App\Http\Controllers\Api\TableCashierController`,
`App\Http\Controllers\Api\TableAdminController`,
`App\Http\Controllers\Api\OrderController::tables`.
Servicios: `TableWaiterService`, `TableCashierService`, `TableSessionService`,
`TableOrderService`.

---

## Flujos funcionales

### Apertura desde grilla (flujo clásico — sin QR)

1. Mesero abre `/orders/tables`. Hook polling 8s a
   `GET /api/v1/orders/tables`.
2. Mesa **Disponible** (verde): click → redirect a
   `/orders/cashier?table=N`. Allí captura items y crea una `Order` con
   `order_type='table'`, `table_number=N` (texto libre, no usa
   `tables.id`). Esta es la ruta heredada — no abre `TableSession`.
3. Mesa **Ocupada** (ámbar): click → modal con detalle, total, ítems,
   botones **Agregar productos** y **Cerrar y cobrar**.
   - **Agregar**: `POST /api/v1/orders/{id}/items`. Backend recalcula
     `total` desde precios en BD; nunca confía en el payload.
   - **Cerrar y cobrar**: `PATCH /api/v1/orders/{id}/status` → `completed`,
     o flujo de cobro con método de pago (`close-with-payment`).
4. Cantidad de mesas en grilla configurable inline (input), persistida en
   `localStorage[tables.grid_size]`, default 12.

### Apertura desde QR (flujo `TableSession`)

1. Cliente escanea QR → `pages/table/join.tsx` carga
   `GET /api/v1/public/table/{qr_token}` (sin auth — ver
   `Carrito-Publico.md`).
2. Al unirse el primer comensal, `TableSessionService::joinOrCreate`
   abre `TableSession::status='open'` y emite
   `table.session.opened`. Comensales siguientes disparan
   `table.guest.joined`.
3. Cada item añadido por el comensal va a la **buffer**
   (`Order::status='pending_approval'`, único por sesión).

### Aprobación de tanda (mesero)

1. Mesero en `/orders/table-sessions/{id}` ve la buffer con ítems
   `pending_approval`.
2. `POST /table-sessions/{id}/approve-batch`:
   - Crea `Order` nueva con `status='pending'` y mueve los ítems
     aprobados a ella.
   - La sesión pasa de `open` → `locked` (si era la primera tanda).
   - Audit `table.batch.approved` con conteo y total.
3. Ítem específico se puede **rechazar** (`reject` → status `rejected`,
   audit `table.item.rejected_by_waiter`) o solicitar cancelación del
   comensal se aprueba/deniega (audit `table.cancellation.{approved,denied}`).

### Pago dividido (cajero, #191 Fase 6)

`TableCashierService` ejecuta los cobros bajo `DB::transaction` con
`lockForUpdate` sobre las órdenes de la sesión:

- **`pay-partial`**: cobra subset de ítems o asocia el receipt a un
  `guest_id`. Crea `PaymentReceipt` con `amount` positivo,
  `cash_session_id` (vincula con turno de caja activo),
  `payment_method` ∈ `cash|card|transfer`. Audit `table.payment.split`.
- **`pay-all`**: cobra remanente. Cuando llega a 0, sesión pasa a
  `closed` y la mesa vuelve a disponible. Audit `table.payment.full`.
- **`refund-item`**: nuevo receipt con `amount` negativo y
  `payment_method='refund'`. Conserva `original_method`. Audit
  `table.payment.refunded`.

La caja **debe estar abierta** (`CashRegisterSession::status='open'`) para
todos estos endpoints; de lo contrario responden 423.

### Cierre de mesa vacía

Si el comensal se va sin pedir nada (sólo se sentó), el mesero ejecuta
`POST /table-sessions/{id}/close-empty`. La sesión pasa a `closed` sin
generar `Order`. Audit `table.session.closed_empty`.

### Expiración por inactividad

Schedule programado (`routes/console.php`,
`Schedule::command('tables:purge-expired-sessions')` con
`->onOneServer()` por la regla N-instance) marca como `expired` sesiones
sin actividad ≥ N minutos (config). No cobra, no genera receipts; queda
para auditoría.

### Admin de mesas físicas

Owner/admin define el catálogo de mesas (número, sillas) en
`/company/tables`. Cada mesa nace con `qr_token` único. `regenerateQr`
invalida el QR impreso anterior. Soft-archive preserva sesiones históricas.

---

## Componentes frontend

| Archivo | Propósito |
|---|---|
| `pages/orders/tables/index.tsx` | Grilla rápida disponibles/ocupadas (poll 8s). |
| `pages/orders/table-sessions/index.tsx` | Lista de sesiones activas. |
| `pages/orders/table-sessions/show.tsx` | Detalle de sesión: guests, buffer, órdenes aprobadas, items_by_status, acciones de mesero. |
| `pages/caja/table-session.tsx` | UI de cobro con pago dividido. |
| `components/cash-register/cash-register-panel.tsx` | Envuelve la grilla — bloquea si caja cerrada. |
| `components/ui/tables-grid-skeleton.tsx` | Skeleton con banner de caja + filtros + grid. |
| `pages/company/tables.tsx` | Admin del catálogo físico (CRUD + QR). |

---

## Estados y transiciones

```
TableSession.status:
  open ──(approve-batch)──► locked ──(pay-all → remanente=0)──► closed
   │                            │
   └──(close-empty)───► closed  └──(job inactividad)──► expired

Order.status (dentro de la sesión):
  pending_approval ──(approve-batch)──► (nueva Order) pending
                  └──(reject ítem)──► rejected

OrderItem.status:
  pending_approval ──► approved ──► pending (heredado de la orden) ─►
       served (vía KDS) | cancelled (cancellation aprobada)
```

---

## Eventos de auditoría

| Acción | Disparador |
|---|---|
| `table.session.opened` | Primer guest se une |
| `table.guest.joined` | Comensal adicional |
| `table.item.added_by_customer` | Comensal agrega ítem al buffer |
| `table.item.edited_by_customer` | Comensal edita cantidad/notas |
| `table.item.cancelled_by_customer` | Comensal cancela ítem pre-aprobación |
| `table.item.cancellation_requested` | Comensal pide cancelar ítem aprobado |
| `table.cancellation.approved` / `table.cancellation.denied` | Mesero decide solicitud |
| `table.item.approved` / `table.batch.approved` | Mesero aprueba |
| `table.item.rejected_by_waiter` | Mesero rechaza ítem buffer |
| `table.item.cancelled_by_waiter` | Mesero cancela ítem aprobado |
| `table.item.notes_edited_by_waiter` | Mesero edita notas a cocina |
| `table.batch.submitted` | Comensal envía lote (submitBatch) |
| `table.note.kitchen_alert_added` / `table.note.group_added` / `table.note.added_by_waiter` | Notas adicionales |
| `table.session.closed_empty` | Cierre sin ítems |
| `table.session.accepts_new_guests_changed` | Toggle |
| `table.payment.split` / `table.payment.full` / `table.payment.refunded` | Cobros (`TableCashierService`) |
| `table.created` / `table.updated` / `table.deactivated` / `table.reactivated` / `table.qr_regenerated` | Admin de mesas físicas |

---

## Validaciones contables

Aplican las reglas del proyecto (CLAUDE.md §13):

- `PaymentReceipt` inmutable: refund de un ítem = receipt nuevo con
  `amount` negativo + `reference` obligatoria en `card`/`transfer`.
- `TableCashierService` usa `DB::transaction` + `Order::lockForUpdate`
  en pago dividido para impedir doble cobro del mismo ítem entre
  cajeros simultáneos.
- Pago dividido vincula `cash_session_id` para que el cuadre de caja
  contabilice cada receipt en el turno correcto.
- `tip_amount` se persiste por orden, separado del `total`.
- `orders.total = SUM(items.price * items.quantity)` recalculado server-side
  en cada `appendItems`. Precios SIEMPRE leídos del menú activo en BD.

---

## Edge cases y empty states

| Caso | Respuesta |
|---|---|
| Caja cerrada al cobrar | 423 `cash_register_closed` |
| Sesión ya cerrada y se intenta pagar | 409 |
| Aprobar buffer vacía | 422 |
| Cancelar ítem ya servido (KDS marcó `served`) | 409 — pasar por flujo de refund |
| Dos cajeros cobran simultáneamente el mismo ítem | `lockForUpdate` serializa; el segundo recibe 409 `already_paid` |
| Mesero entra a sesión `closed` o `expired` | UI muestra detalle solo lectura |
| Regenerar QR de mesa con sesión activa | Permitido — sesión sigue válida; nuevos joins requieren QR nuevo |
| Crear mesa con `number` duplicado en la sede | 422 (UNIQUE parcial) |
| Listado vacío de mesas | Empty state con CTA "Crear mesa" (admin) o "Configura mesas en /company/tables" (cajero) |
