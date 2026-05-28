# Pedidos

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma
> Fuente única de estados: `application/backend/config/orders.php`
> Constantes canónicas: `application/backend/constants/ORDER_STATUSES.md`

---

## Visión general

Los pedidos (`orders`) son creados por varios canales:

1. **Mesa con QR** (cliente final escanea QR de la mesa): genera items en estado `pending_approval` que el mesero aprueba (#191).
2. **Bot externo** (WhatsApp) vía JWT de bot — caso principal de pedidos de domicilio.
3. **API interna** `POST /api/v1/orders` — registro manual desde el panel (cajero / mesero).
4. **Sync offline** `POST /api/v1/orders/sync-batch` — el POS sube en lote pedidos creados sin conexión.

Tanto la orden como cada `order_item` tienen ciclo de vida propio (#191). El kanban del panel agrupa pedidos por `orders.status`; el KDS opera sobre `order_items` independientemente.

---

## Estados de la orden

Modelo plano, **forward-only**: una orden solo avanza (rank destino > rank actual) o se queda igual. Volver atrás está prohibido — para corregir, se cancela y se crea una nueva (trazabilidad DIAN).

| status | Categoría | Label UI | kanban_rank | Cuenta como ingreso |
|---|---|---|---|---|
| `pending_approval` | `pre_operational` | Pendiente aprobación | — | no |
| `pending` | `operational` | Pendiente | 1 | no |
| `in_kitchen` | `operational` | En cocina | 2 | no |
| `ready` | `operational` | Para entrega | 3 | no |
| `in_transit` | `operational` | En tránsito | 4 | no |
| `completed` | `terminal_success` | Completado | 5 | ✅ sí |
| `failed` | `terminal_failure` | Entrega fallida | — | no |
| `cancelled` | `terminal_failure` | Cancelado | — | no |
| `refunded` | `terminal_failure` | Devolución | — | no |
| `abandoned` | `terminal_failure` | Abandonado | — | no |

Grupos canónicos en `config/orders.php`:

```
all              = todos (10)
operational      = [pending, in_kitchen, ready, in_transit]
terminal_success = [completed]
terminal_failure = [failed, cancelled, refunded, abandoned]
kanban           = [pending, in_kitchen, ready, in_transit, completed]
revenue          = [completed]   ← único estado que cuenta como ingreso
```

`pending_approval` aplica a órdenes de mesa con QR antes de que el mesero las apruebe. No entra en `operational`, `kanban` ni `revenue`.

Labels visibles pueden variar por vertical (#237): `in_kitchen` se muestra como "En barra" en café/bar, "En horno" en bakery. Los slugs en BD/código no cambian — el label se obtiene de `labels.order_statuses` del endpoint `GET /api/v1/me/active-context`.

---

## Estados de items (`order_items`)

Cada item tiene ciclo independiente (#191). El cliente los agrega (`pending_approval`), el mesero aprueba (`approved`), cocina los produce (`in_kitchen → ready`), el mesero los entrega (`served`); pueden cancelarse antes de `served` con razón categorizada.

| item_status | Label | rank | Consumible | Excluido de `orders.total` |
|---|---|---|---|---|
| `pending_approval` | Por aprobar | 1 | no | no |
| `approved` | Aprobado | 2 | ✅ | no |
| `in_kitchen` | En cocina | 3 | ✅ | no |
| `ready` | Listo | 4 | ✅ | no |
| `served` | Entregado | 5 | ✅ | no |
| `cancelled` | Cancelado | — | no | ✅ sí |

Razones canónicas de cancelación de item (`config/orders.php → item_statuses.cancellation_reasons`):
`customer`, `waiter`, `waiter_approved`, `kitchen`, `system`, `refunded`.

---

## Estructura de items (JSON)

Snapshot almacenado en `orders.items` (jsonb):

```json
[
  {
    "id": "uuid-item",
    "name": "Bandeja paisa",
    "price": 32000,
    "quantity": 2,
    "category": "Platos principales",
    "notes": "Sin cebolla, frijoles aparte"
  }
]
```

`order_items` (tabla relacional) lleva el ciclo de vida individual (`status`, `kds_station_id`, timestamps, `cancellation_reason`).

---

## Tipos de orden

Campo `order_type` en `orders`:

| Valor | Descripción | Campos extra |
|-------|-------------|--------------|
| `dine_in` | Para mesa | `table_number`, `table_session_id` |
| `takeaway` | Para llevar | — |
| `delivery` | Domicilio | `delivery_address` |

---

## Endpoints

Todos bajo `/api/v1` con middleware `jwt + company.access + branch.access` salvo indicación.

### Lectura

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| `GET` | `/orders` | `orders.read,read` | Lista pedidos (filtros: status, date_from, date_to, branch) |
| `GET` | `/orders/{id}` | `orders.read,read` | Detalle |
| `GET` | `/orders/tables` | `orders.read,read` | Resumen por mesa abierta |
| `GET` | `/orders/pending-approvals` | `orders.read,read` | Mesas con items por aprobar (#191) |
| `GET` | `/orders/pending-cancellations` | `orders.read,read` | Solicitudes de cancelación pendientes |
| `GET` | `/orders/{id}/receipt-escpos` | `orders.read,read` | Comanda ESC/POS para impresora térmica |

### Escritura

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| `POST` | `/orders` | `orders.create,create` | Crea pedido manual |
| `POST` | `/orders/sync-batch` | `orders.create,create` | Sube lote de pedidos offline |
| `PATCH` | `/orders/{id}/status` | `orders.update,update` | Cambia estado (forward-only, valida contra `config('orders.kanban')`) |
| `POST` | `/orders/{id}/items` | `orders.update,update` | Agrega items a una orden abierta (`appendItems`) |
| `POST` | `/orders/{id}/close-with-payment` | `orders.update,update` | Cierra la orden con uno o varios `PaymentReceipt` (cash/card/transfer) |
| `POST` | `/orders/{id}/cancel` | `orders.update,update` | Cancela (estado terminal) |
| `POST` | `/orders/{id}/refund` | `orders.update,update` | Devolución: crea `PaymentReceipt` con `amount` negativo y status → `refunded` |

### Reportes y export

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/reports/orders` | `orders.read,read` |
| `POST` | `/reports/export` | `orders.read,read` |
| `POST` | `/exports/orders/pdf` | `orders.read,read` |
| `POST` | `/exports/orders/csv` | `orders.read,read` |

---

## Ejemplo: crear pedido manual

```http
POST /api/v1/orders HTTP/1.1
Content-Type: application/json

{
  "order_type": "dine_in",
  "table_number": "5",
  "items": [
    { "id": "uuid", "name": "Bandeja paisa", "price": 32000, "quantity": 1, "category": "Platos principales" }
  ],
  "client_phone": "+573001234567",
  "notes": "Cliente recurrente"
}
```

```http
HTTP/1.1 201 Created
{
  "order": {
    "id": 1042,
    "status": "pending",
    "order_type": "dine_in",
    "table_number": "5",
    "total": 32000,
    "ordered_at": "2026-05-02T14:30:00-05:00"
  }
}
```

---

## Cambio de estado

```http
PATCH /api/v1/orders/1042/status HTTP/1.1
Content-Type: application/json

{ "status": "in_kitchen" }
```

Validaciones (`OrderController::updateStatus`):
- `status` debe estar en `config('orders.kanban')` (`pending`, `in_kitchen`, `ready`, `in_transit`, `completed`).
- Forward-only: `kanban_rank[to] >= kanban_rank[from]`. Mismo rank → no-op silencioso (idempotencia drag-and-drop).
- Estados `terminal_failure` (`failed`/`cancelled`/`refunded`/`abandoned`) bloquean cualquier transición posterior.
- Mutación envuelta en `DB::transaction` + `Order::lockForUpdate()`.
- Al pasar a `in_kitchen` por primera vez (`inventory_consumed_at IS NULL`) se descuenta inventario por receta (idempotente).
- Auditoría: `AuditService::log('order.status_changed', ...)` con `from`, `to`, `inventory_consumed`.

Para `cancelled` / `refunded` usar los endpoints dedicados (`/orders/{id}/cancel`, `/orders/{id}/refund`) — generan los asientos contables correspondientes.

---

## Cierre con pago

```http
POST /api/v1/orders/1042/close-with-payment HTTP/1.1
Content-Type: application/json

{
  "payments": [
    { "method": "cash", "amount": 20000 },
    { "method": "card", "amount": 12000, "reference": "POS-9988" }
  ],
  "tip_amount": 3200
}
```

Reglas (CLAUDE.md §13, `ACCOUNTING_RULES.md`):
- `DB::transaction` + `Order::lockForUpdate()` para prevenir doble cobro.
- Métodos válidos: `cash | card | transfer` (lista cerrada en `config/payments.php`).
- `card` y `transfer` exigen `reference` (voucher / número de comprobante).
- Cada pago crea un `PaymentReceipt` inmutable con `amount` **signed** `decimal(12,2)` y `paid_at`.
- `tip_amount` se guarda separado, NO suma a `total` ni a base gravable (propina voluntaria CO).
- Auditoría: `order.close_with_payment` con monto, método, referencia.

---

## Devolución (refund)

```http
POST /api/v1/orders/1042/refund HTTP/1.1
Content-Type: application/json

{
  "method": "card",
  "amount": 32000,
  "reference": "REV-POS-9988",
  "reason": "Plato en mal estado"
}
```

- Crea `PaymentReceipt` con `payment_method='refund'` y `amount` negativo.
- Cambia `orders.status` a `refunded` (`terminal_failure`).
- NUNCA `UPDATE` sobre el receipt original (inmutabilidad DIAN).
- Refunds parciales: múltiples filas negativas posibles. Si SUM(refunds) == SUM(cobros) → orden refunded.
- Tarjeta/transferencia: `reference` obligatoria. Efectivo: `actor_id` del JWT queda en audit.

---

## Reglas contables aplicables (resumen)

Detalle completo en `CLAUDE.md` §13 + `application/backend/constants/ACCOUNTING_RULES.md`.

- Columnas monetarias `decimal(12,2)` (`total`, `subtotal`, `tax_amount`, `tip_amount`, `discount_amount`). NUNCA `float`.
- Mutaciones financieras envueltas en `DB::transaction` + `lockForUpdate`.
- `orders.total = SUM(items.price * items.quantity) WHERE item.status ∉ excluded_from_total`. Helper único: `OrderController::computeItemsTotal`.
- `orders.total` ya viene **neto del descuento** — `discount_amount` es informativo. NO restarlo en reportes.
- Precios SIEMPRE se leen del menú activo en BD, no del payload del cliente.
- `revenue_statuses = ['completed']`. Reportes muestran **gross / refunds / net** explícito.
- Filtros temporales: órdenes por `ordered_at`, receipts por `paid_at`, timezone canónico `America/Bogota`.
- Conservación DIAN: 5 años personas naturales, 10 años jurídicas. Soft-delete máximo, jamás `truncate` en PDN.
- Snapshot tributario (`subtotal`, `tax_amount`, `tax_rate`, `tax_regime`, `tax_included_in_price`) y snapshot del adquirente DIAN (`billing_*`, #235) son inmutables tras emitir.

---

## Pedidos del bot (canal externo)

El bot crea pedidos vía su propio JWT (`BOT_JWT_SECRET`) sobre rutas `/api/external/*` y sesiones de carrito (`/api/v1/cart/{jwt}`). Ver [WhatsApp Bot](WhatsApp-Bot.md).

---

## Índices de rendimiento

La tabla `orders` tiene índices compuestos para el dashboard y kanban:

- `(company_nit, status, ordered_at)` — kanban por estado.
- `(company_nit, ordered_at)` — métricas por período.

---

## Notas de seguridad

- Los pedidos están confinados al `active_company_nit` + `active_branch_id`; no es posible listar/leer pedidos de otra empresa o sede.
- El bot solo puede crear pedidos para la empresa de su JWT (validación cruzada en `ValidateBotJwt`).
- Toda transición de estado y mutación financiera queda en `audit_logs` con `branch_id` + `actor_active_branch_id` automáticos.
- Receipts y facturas DIAN son inmutables; correcciones son siempre nuevos asientos.
