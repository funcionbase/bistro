# Caja / POS

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Punto de venta operado por cajeros y administradores para capturar pedidos
manuales (mesa, domicilio, para llevar) cuando el cliente no usa el carrito
público (QR) ni el bot de WhatsApp. Cubre el ciclo completo del turno:
**apertura de caja → captura de órdenes → cobro con método de pago →
egresos → cierre con cuadre**.

Vive en `pages/caja/index.tsx` (alias canónico `/orders/cashier`,
back-compat 302 desde `/caja`). El gate web exige `orders.read` y el botón
"Cobrar" exige `orders.create`. Toda operación de caja ocurre en el contexto
de una sede activa (`active_branch_id`) — cada sede opera su propia caja
(multi-sede #117).

---

## Modelo de datos

| Tabla | Campos clave | Notas |
|---|---|---|
| `cash_register_sessions` | `id` (UUID), `company_nit`, `branch_id`, `opened_by_user_id`, `opened_at`, `opening_amount` (decimal:2), `closed_by_user_id`, `closed_at`, `closing_amount`, `expected_cash`, `cash_difference`, `status` (`open`/`closed`), `opening_notes`, `closing_notes` | UNIQUE parcial: una sola sesión `open` por (company_nit, branch_id). Inmutable: para corregir un cierre, se abre otra sesión con notas. |
| `cash_register_expenses` | `id`, `cash_session_id`, `company_nit`, `amount` (decimal:2 positivo), `category`, `payment_method` (`cash`/`card`/`transfer`), `description`, `created_by_user_id`, `created_at` | Append-only (`$timestamps=false`). Sin PUT/DELETE: para revertir se crea otro egreso `otro` con descripción explícita. |
| `payment_receipts` | `id`, `order_id`, `company_nit`, `payment_method` (`cash`/`card`/`transfer`/`refund`), `amount` (decimal:2 **signed**), `reference`, `paid_at`, `cash_session_id`, `payment_data` (JSON) | Inmutable (`$timestamps=false`). Cobros positivos, devoluciones negativas. Net por método = `SUM(amount) GROUP BY payment_method`. |
| `orders` | `company_nit`, `branch_id`, `session_id` (prefijo `caja-<uuid>` para órdenes POS), `client_phone`, `order_type` (`table`/`delivery`/`pickup`), `table_number`, `delivery_address`, `items` (JSON snapshot), `status`, `total` (decimal:2), `discount_amount`, `coupon_code`, `tip_amount`, `ordered_at` | `total` es **neto del descuento**. `tip_amount` no suma a `total` ni a base gravable. |

Categorías de egreso (lista cerrada en `config/cash_register.php`):
`domiciliario_pago`, `proveedor`, `imprevisto`, `propina_distribuida`, `otro`.

---

## Permisos RBAC

| Slug | owner | admin | cashier | manager | supervisor | waiter |
|---|---|---|---|---|---|---|
| `orders.read` | RCUD | RCUD | R--- | R--- | R--- | R--- |
| `orders.create` | RCUD | RCUD | RC-- | RC-- | RC-- | ---- |
| `orders.update` | RCUD | RCUD | RCU- | RCU- | RCU- | ---- |
| `reports.read` (egresos por sesión) | RCUD | RCUD | ---- | R--- | R--- | ---- |

- Apertura de caja: `orders.create`.
- Cierre, cobro, devolución, egresos: `orders.update`.
- Listado de egresos históricos: `reports.read`.
- `role.is_system=true` (owner) bypasea cualquier check.

---

## Endpoints

| Método | Ruta | Auth/Middleware | Permiso | Descripción |
|---|---|---|---|---|
| `GET` | `/api/v1/cash-register/current` | `jwt` + `company.access` + `branch.access` | `orders.read` | Sesión `open` actual de la sede o `null`. |
| `POST` | `/api/v1/cash-register/open` | idem | `orders.create` | Abre turno con `opening_amount` y notas. |
| `POST` | `/api/v1/cash-register/close` | idem | `orders.update` | Cierra turno con `closing_amount`, calcula `expected_cash` y `cash_difference`. |
| `POST` | `/api/v1/cash-register/expenses` | idem | `orders.update` | Registra egreso contra sesión `open`. |
| `GET` | `/api/v1/cash-register/sessions/{id}/expenses` | idem | `reports.read` | Egresos históricos de una sesión. |
| `GET` | `/api/v1/cash-register/sessions` | idem | `reports.read` | Listado paginado de sesiones cerradas. |
| `GET` | `/api/v1/cash-register/sessions/{id}` | idem | `reports.read` | Detalle de una sesión cerrada (cuadre completo). |
| `POST` | `/api/v1/orders` | idem | `orders.create` | Crea orden manual (`OrderController::store`). |
| `POST` | `/api/v1/orders/{id}/items` | idem | `orders.update` | Agrega ítems a orden no terminal (`appendItems`). |
| `POST` | `/api/v1/orders/{id}/close-with-payment` | idem | `orders.update` | Cobra orden con método de pago. |
| `POST` | `/api/v1/orders/{id}/cancel` | idem | `orders.update` | Cancela orden sin pago. |
| `POST` | `/api/v1/orders/{id}/refund` | idem | `orders.update` | Devolución total o parcial. |
| `GET` | `/api/v1/orders/{id}/receipt-escpos` | idem | `orders.read` | Binario ESC/POS para impresora térmica. |

Controladores: `App\Http\Controllers\Api\CashRegisterController`,
`App\Http\Controllers\Api\OrderController`,
`App\Http\Controllers\Api\ReceiptPrintController`. Servicio: `CashRegisterService`,
`ReceiptPrintingService`.

---

## Flujos funcionales

### Apertura de turno

1. Al cargar `/orders/cashier`, el hook `useCashRegister` (poll 10s)
   consulta `GET /api/v1/cash-register/current`.
2. Si no hay sesión `open`, `<CashRegisterPanel>` reemplaza la UI de
   captura con una pantalla "Caja cerrada" e input de **Efectivo inicial
   en caja** (puede ser 0) + notas opcionales.
3. `POST /cash-register/open` persiste `opening_amount`, `opening_notes`,
   `opened_by_user_id`, `opened_at`, `branch_id` (de la sede activa).
4. Se emite `cash_register.opened` en `audit_logs` con metadata
   (`opening_amount`, `branch_id`, notas).
5. El polling detecta la sesión y descongela la UI de captura.

### Creación de orden (`POST /api/v1/orders`)

Validación inline en `OrderController::store`:

- `order_type`: `table` | `delivery` | `pickup`.
- `table` exige `table_number` (string max:20).
- `delivery` exige `delivery_address` (max:500) + `client_phone`.
- `items[]`: array no vacío con `id`, `quantity`, `notes`.
- Sólo se aceptan ítems del menú activo (`RestaurantMenu::active()`) con
  `available=true` y que el `active_days` incluya el día actual.

Persistencia: precios y nombres se **copian del menú en BD** (snapshot
inmutable); `total = SUM(price × quantity)` calculado server-side via
`OrderController::computeItemsTotal`. `session_id` lleva prefijo `caja-`
para distinguir del bot/cart. `cost=0` (no implementado), `discount_amount=0`,
`coupon_code=null` (los cupones se aplican en otro endpoint).

`MenuController::showPublic` y `OrderController` lanzan **423
`cash_register_closed`** si no hay sesión `open`: el menú público se
bloquea para clientes mientras la caja está cerrada.

### Cobro (`POST /api/v1/orders/{id}/close-with-payment`)

Body: `{ payment_method, amount_received?, reference?, tip_amount? }`.

- `cash`: valida `amount_received >= total + tip`. Calcula `change_returned`.
- `card`: exige `reference` (número de comprobante del datáfono).
- `transfer`: muestra QR de la empresa; exige `reference`.

Persistencia atómica:

```php
DB::transaction(function () use ($order, ...) {
    $order = Order::lockForUpdate()->findOrFail($order->id);
    PaymentReceipt::create([
        'payment_method' => $method,
        'amount' => $amount,            // positivo en cobro
        'reference' => $reference,
        'cash_session_id' => $session->id,
        'paid_at' => now(),
        'order_id' => $order->id,
    ]);
    $order->update(['status' => 'completed', 'tip_amount' => $tip]);
});
```

Audit: `order.closed_with_payment` con `payment_method`, `amount`,
`tip_amount`, `change_returned`.

### Egreso de caja (`POST /api/v1/cash-register/expenses`)

- Sólo contra sesión `open`.
- `category` debe estar en `config('cash_register.expense_categories')`.
- `payment_method` ∈ `cash | card | transfer`; los `cash` reducen
  `expected_cash` al cierre.
- Append-only: no hay PUT/DELETE. Reverso = nuevo egreso categoría `otro`.
- Audit: `cash.expense.recorded` con `category`, `amount`, `payment_method`,
  `description`.

### Devolución (`POST /api/v1/orders/{id}/refund`)

- `amount` opcional: si se omite, devuelve el remanente completo.
- Para `card` / `transfer`: exige `reference` de la devolución hecha al cliente.
- Crea un **nuevo** `PaymentReceipt` con `amount` negativo y
  `payment_method='refund'`. `payment_data.original_method` guarda el
  método original cobrado.
- `Order::lockForUpdate()` previene race conditions con refunds concurrentes.
- Refunds parciales: múltiples receipts por orden hasta agotar `total`.
  `status` pasa a `refunded` SOLO cuando el remanente llega a 0.
- Audit: `order.refunded` con `amount`, `reference`, `payment_method`,
  `partial` (bool).

### Cierre de turno

1. Modal con resumen: `Inicial + Cobros cash + Propinas cash − Devoluciones cash
   − Egresos cash = Esperado en caja`.
2. Input **Efectivo contado**; diferencia proyectada en vivo
   (verde=cuadre, ámbar=sobrante, rojo=faltante).
3. Backend recalcula y persiste `expected_cash` y
   `cash_difference = closing − expected`. `status → closed`.
4. Audit: `cash_register.closed` con `expected_cash`, `closing_amount`,
   `cash_difference`, breakdown de egresos por categoría.
5. La sesión cerrada es **inmutable**: no puede reabrirse ni modificarse.

### Impresión térmica (ESC/POS #105)

`GET /api/v1/orders/{id}/receipt-escpos?width=58|80&copy=true|false`
devuelve binario `application/octet-stream` generado por
`ReceiptPrintController → ReceiptPrintingService → EscposBuilder` (CP850,
corte, doble alto). **No** crea `PaymentReceipt` — los recibos contables
son inmutables; el neto por método se computa
`SUM(amount) GROUP BY payment_method` desde los receipts existentes.

Frontend: `<PrintReceiptButton orderId={...} />` envía el binario por
WebUSB (Chromium/Edge sobre HTTPS). Vendors soportados: Epson (0x04b8),
Star (0x0519), Xprinter/STM (0x0483, 0x1a86), Bixolon clones (0x0fe6).
Sin WebUSB → fallback descarga `.bin`.

Settings (`company_settings`): `printing.receipt_width` (58|80),
`printing.header_lines` (json[]), `printing.footer_message`,
`printing.show_qr_menu`, `printing.copies`.

---

## Componentes frontend

| Archivo | Propósito |
|---|---|
| `pages/caja/index.tsx` | Página POS principal (selector de tipo, lista de items, carrito). |
| `pages/caja/table-session.tsx` | Vista de cobro de mesa con pago dividido (#191 Fase 6). |
| `components/cash-register/cash-register-panel.tsx` | Envoltura que sustituye la UI cuando la caja está cerrada. |
| `components/cash-register/cash-register-alert-banner.tsx` | Banner ámbar global cuando `should_alert=true`. |
| `components/cash-register/expense-modal.tsx` | Modal de captura de egreso (categoría + método + monto). |
| `components/printing/print-receipt-button.tsx` | WebUSB ESC/POS. |
| `hooks/use-cash-register.ts` | Estado compartido + poll 10s + `recordExpense`/`openSession`/`closeSession`. |
| `components/ui/cashier-skeleton.tsx` | Skeleton de carga. |

---

## Estados y transiciones

```
cash_register_sessions:
  open ──(close)──► closed   (terminal; nunca vuelve a open)

orders (relevantes a POS):
  pending ──(close-with-payment)──► completed ──(refund total)──► refunded
        └──(cancel)──► cancelled
        └──(appendItems)──► pending (mismo estado, total recalculado)
```

---

## Eventos de auditoría

| Acción | Disparador | Metadata clave |
|---|---|---|
| `cash_register.opened` | `POST /cash-register/open` | `opening_amount`, `branch_id`, `opening_notes` |
| `cash_register.closed` | `POST /cash-register/close` | `expected_cash`, `closing_amount`, `cash_difference`, breakdown por categoría |
| `cash.expense.recorded` | `POST /cash-register/expenses` | `category`, `amount`, `payment_method`, `description` |
| `order.closed_with_payment` | `closeWithPayment` | `payment_method`, `amount`, `tip_amount`, `change_returned`, `reference` |
| `order.refunded` | `refund` | `amount`, `reference`, `payment_method`, `partial`, `original_method` |
| `order.cancelled` | `cancel` | `reason`, `had_receipts` |
| `order.items_appended` | `appendItems` | `added_count`, `new_total`, `delta` |

Todos heredan `branch_id` y `actor_active_branch_id` automáticamente vía
`AuditService::log`.

---

## Validaciones contables

Aplican las reglas del proyecto (CLAUDE.md §13):

- **Moneda**: COP. Columnas monetarias `decimal(12,2)` con cast `decimal:2`.
- **Inmutabilidad**: `payment_receipts` y `cash_register_expenses` jamás se
  actualizan ni se eliminan. Reverso = receipt o egreso nuevo.
- **Atomicidad**: toda mutación dentro de `DB::transaction` con
  `Order::lockForUpdate` (previene doble cobro / doble cierre concurrente).
- **Signos**: cobros positivos, devoluciones negativas. Net por método
  siempre en SQL (`SUM(amount) GROUP BY payment_method`), nunca iterado en PHP.
- **Propina** (`tip_amount`): persiste separada, NO suma a `total`, NO entra
  a base gravable ni a `revenue`.
- **Cupones**: el descuento ya está aplicado a `orders.total` (no restar de
  nuevo en reportes). `discount_amount` y `coupon_code` quedan en la orden
  como informativos.
- **Refunds card/transfer**: `reference` obligatoria (único respaldo
  contable de la devolución hecha al cliente).
- **Conservación DIAN**: 5 años (natural) / 10 años (jurídica). Sin
  `truncate` en producción.

---

## Edge cases y empty states

| Caso | Respuesta |
|---|---|
| Sin sesión `open` y se intenta crear orden | 423 `cash_register_closed` (también afecta `/api/v1/public/menu/{nit}`) |
| Sin sede activa | 400 `branch.access` middleware (`active_branch_id` ausente) |
| `order_type=table` sin `table_number` | 422 `{errors:{table_number:[...]}}` |
| `order_type=delivery` sin `delivery_address` | 422 |
| Ítem inexistente o `available=false` | 422 (mensaje genérico para no exponer estado) |
| Menú activo no aplica para hoy (`active_days`) | 422 `{errors:{menu:[...]}}` |
| No hay menú activo | 422 `{errors:{menu:['No hay un menú activo para la empresa.']}}` |
| Refund sin remanente | 422 `{errors:{amount:['Sin saldo para devolver.']}}` |
| Cierre con `closing_amount` negativo | 422 |
| Receipt ESC/POS sin pagos asociados | 409 `no_receipts` |
| ESC/POS de orden de otra empresa | 404 |
| Caja cerrada hace ≥1h en horario hábil con menú activo | Banner ámbar global con CTA "Abrir caja" |
| Segunda apertura simultánea (race) | 409 (UNIQUE parcial en BD impide doble `open`) |
