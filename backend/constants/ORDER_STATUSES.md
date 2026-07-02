# ORDER_STATUSES — Fuente única de verdad

> **Antes de modificar estados de orden o item, lee este archivo.**
> **Después de modificar, actualiza este archivo + `config/orders.php` +
> `resources/js/lib/order-status.ts` (fallback) + el kanban en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `bistro/backend/config/orders.php` — **fuente única canónica**
- [ ] `bistro/frontend/src/lib/order-status.ts` — fallback embebido frontend (DEUDA TÉCNICA, ver §Deuda)
- [ ] `bistro/frontend/src/types` / `bistro/frontend/src/types/index.d.ts` — tipo `OrderStatus`, `OrderStatusesConfig`
- [ ] `bistro/backend/app/Http/Middleware/HandleInertiaRequests.php` — share de `orders` config a `auth/shared/orders.*`
- [ ] `bistro/backend/app/Models/Order.php` — invariantes y transiciones permitidas
- [ ] `bistro/frontend/src/pages/Orders/Kanban.tsx` — `kanban` + `kanban_rank`
- [ ] `docs/wiki/Pedidos.md` — manual narrativo
- [ ] `CLAUDE.md` raíz §12 — `revenue_statuses` + estados terminales

---

## Estados de `orders` (tabla principal)

Modelo plano sin sub-tipos. Forward-only: una orden solo puede avanzar en el flujo (rank destino > rank actual). Volver atrás → cancelar y crear nueva (trazabilidad DIAN).

| status | Categoría | Label UI | Badge (Tailwind) | kanban_rank | revenue |
|---|---|---|---|---|---|
| `pending_approval` ⓘ | `pre_operational` | Pendiente aprobación | `bg-slate-100 text-slate-700` | — | no |
| `pending` | `operational` | Pendiente | `bg-yellow-100 text-yellow-800` | 1 | no |
| `in_kitchen` | `operational` | En cocina | `bg-orange-100 text-orange-800` | 2 | no |
| `ready` | `operational` | Para entrega | `bg-blue-100 text-blue-800` | 3 | no |
| `in_transit` | `operational` | En tránsito | `bg-purple-100 text-purple-800` | 4 | no |
| `completed` | `terminal_success` | Completado | `bg-green-100 text-green-800` | 5 | ✅ **sí** |
| `failed` | `terminal_failure` | Entrega fallida | `bg-rose-100 text-rose-700` | — | no |
| `cancelled` | `terminal_failure` | Cancelado | `bg-red-100 text-red-700` | — | no |
| `refunded` | `terminal_failure` | Devolución | `bg-pink-100 text-pink-700` | — | no |
| `abandoned` | `terminal_failure` | Abandonado | `bg-amber-100 text-amber-700` | — | no |

ⓘ `pending_approval` (#191): órdenes de mesa con QR aún no aprobadas por el mesero. NO entran en `operational`, `kanban` ni `revenue`.

Fuente: `config/orders.php:23-134`.

### Grupos canónicos

```php
'all'                = todos los 10 estados
'operational'        = [pending, in_kitchen, ready, in_transit]
'terminal_success'   = [completed]
'terminal_failure'   = [failed, cancelled, refunded, abandoned]
'kanban'             = [pending, in_kitchen, ready, in_transit, completed]
'revenue'            = [completed]  ← único estado que cuenta como ingreso
```

### Reglas de transición

1. **Avance solo hacia adelante**: `rank(destino) > rank(actual)`.
2. **Mismo rank = no-op silencioso**.
3. **Terminal_failure bloquea cualquier transición posterior** (`refunded`, `cancelled`, `failed`, `abandoned` son finales).
4. **Refund crea nuevo asiento** (`PaymentReceipt` con `amount` negativo) + cambia status a `refunded`. NO se `UPDATE`-ea el receipt original.
5. **Volver atrás → cancelar y crear nueva orden** (trazabilidad DIAN).

### Reglas contables sobre estados

- **`revenue_statuses = ['completed']`** — única lista que cuenta como ingreso. NO incluir `in_transit` ni pre-entrega.
- **`orders.total`** ya viene **neto del descuento**. `discount_amount` es informativo.
- **Mutaciones envueltas en `DB::transaction` + `Order::lockForUpdate()`** (`CLAUDE.md` §12).
- **Timezone canónico**: `America/Bogota` (UTC-5 sin DST). Filtros de "del día" / cuadre de caja: `paid_at::date` con esta TZ explícita.

---

## Estados de `order_items` (subitem)

Cada `order_item` tiene ciclo propio independiente del estado de la orden (#191).

| item_status | Label UI | Badge | kanban_rank | Consumible | Excluido de total |
|---|---|---|---|---|---|
| `pending_approval` | Por aprobar | `bg-slate-100 text-slate-700` | 1 | no | no |
| `approved` | Aprobado | `bg-yellow-100 text-yellow-800` | 2 | ✅ sí | no |
| `in_kitchen` | En cocina | `bg-orange-100 text-orange-800` | 3 | ✅ sí | no |
| `ready` | Listo | `bg-blue-100 text-blue-800` | 4 | ✅ sí | no |
| `served` | Entregado | `bg-green-100 text-green-800` | 5 | ✅ sí | no |
| `cancelled` | Cancelado | `bg-red-100 text-red-700` | — | no | ✅ **sí** (excluido de `orders.total`) |

Fuente: `config/orders.php:142-199`.

### Razones canónicas de cancelación de item

```php
'cancellation_reasons' = [
    'customer'         => 'Cancelado por el cliente',
    'waiter'           => 'Rechazado por el mesero',
    'waiter_approved'  => 'Aprobación de mesero a solicitud del cliente',
    'kitchen'          => 'Cancelado por cocina',
    'system'           => 'Cancelación automática',
    'refunded'         => 'Devuelto tras pago',
]
```

> **Nota (2026-07-01):** `cancellation_reason='refunded'` quedó **legacy** — solo
> describe filas históricas. El refund de un item pagado (`TableCashierService::refundItem`)
> ya NO cancela el item ni recalcula `orders.total`: la venta queda intacta y la
> devolución vive únicamente en el `PaymentReceipt` negativo (CLAUDE.md §13).
> El item devuelto se marca con `order_items.refunded_at` + `refund_receipt_id`
> (bloquea doble refund). Si los refunds acumulados cubren `orders.total` de una
> orden `completed`, la orden pasa a `refunded` (paridad con `OrderController::refund`).

### Invariante `orders.total`

`orders.total = SUM(line_total) WHERE item.status ∉ excluded_from_total` — neto del descuento si hay cupón.

**`order_items` es la FUENTE de líneas; `orders.items` JSON es proyección de lectura** (#293). El desglose por línea (`subtotal`/`tax_amount`/`total`) lo produce **`TaxCalculator::calculateLine`** en todos los flujos:

- **Creación** (caja `store`, sync offline): `OrderController::buildOrderLines` calcula el desglose y `materializeOrderItems` persiste las filas `order_items` (con `tax_rate` por línea). El JSON nace como espejo de esas líneas.
- **Mutación de líneas** (QR add/edit/cancel/approve, caja `appendItems`): `App\Support\OrderTotalCalculator::recalculateAndSave` recalcula `subtotal`, `tax_amount`, `tax_rate`, `total` y `cost` desde las filas y **reproyecta `orders.items` JSON**. (`appendItems` conserva un fallback JSON-only para órdenes legacy sin filas.)

Todos usan el **snapshot tributario de la orden** (`tax_included_in_price`, `snapshot_default_tax_rate`, y `order_items.tax_rate` por línea con fallback al default), nunca el estado vivo de la empresa.

---

## DEUDA TÉCNICA — Fallback duplicado en frontend

El archivo `bistro/frontend/src/lib/order-status.ts` mantiene un **`ORDER_STATUS_FALLBACK` embebido** con un subset de `config/orders.php`, usado cuando los shared props de Inertia no están disponibles aún (primer render antes del hidrate).

### Riesgo

Cualquier cambio en `config/orders.php` **debe replicarse manualmente** en `order-status.ts`. Si se olvida, el primer render usa valores stale y luego "salta" al valor correcto tras hidratar.

### Diferencias actuales (drift)

- ✅ **Resuelto en #203**: el fallback ahora incluye `pending_approval` (label, badge `bg-slate-100 text-slate-700`, category `pre_operational`). Tipo TS `OrderStatus` también incluye `pending_approval`; nuevo type alias `OrderStatusCategory = 'pre_operational' | 'operational' | 'terminal_success' | 'terminal_failure'`.

### Refactor propuesto (fuera de scope HU #202/#203)

Servir `config/orders.php` 100% por shared props de Inertia (`HandleInertiaRequests::share`) → eliminar el fallback. Requiere garantizar que `auth.shared.orders` esté presente en TODOS los flujos (incluyendo login pre-hidrate). Sub-issue futuro.

### Mientras tanto

**Si tocás `config/orders.php`, abrí `order-status.ts` y sincronizá.** El propio archivo TS lo recuerda en el comentario de la línea 6:

> *"Debe coincidir con `config/orders.php`. Cualquier cambio aquí debe replicarse allá."*

---

## Cómo añadir un estado nuevo

1. Decidir si es operacional, terminal éxito, terminal falla o pre-operacional.
2. Editar `config/orders.php`:
   - Agregar en `'all'`.
   - Agregar en el grupo correspondiente (`operational`/`terminal_*`/`kanban` si aplica).
   - Agregar `labels[<x>]`, `badges[<x>]`, `category[<x>]`.
   - Si entra al kanban, asignar `kanban_rank[<x>]`.
   - Si cuenta como ingreso, agregar a `revenue` (raro — usualmente solo `completed`).
3. **Replicar en `resources/js/lib/order-status.ts`** (mientras exista la deuda).
4. Si cambia el flujo de transición, actualizar `Order` model y servicios (`OrderController::updateStatus`).
5. Actualizar `Orders/Kanban.tsx` si entra al tablero.
6. Documentar en este `.md` (tabla canónica + transiciones).
7. PR descripción: "Nuevo estado `<x>`. Categoría: `<y>`. Cuenta como revenue: sí/no. Bloquea transiciones posteriores: sí/no."

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202 no hay estados retirados)_

Nota: `pending_approval` se introdujo en #191 (mesa con QR). Antes de eso solo existían los flujos delivery/mostrador desde `pending`.

---

## Labels dinámicos por vertical (#237)

Los **slugs en BD/código permanecen** (`in_kitchen`, `in_transit`, etc.) — son
inmutables por DIAN y por la cantidad de código que los referencia (28+ refs
backend, decenas frontend). La generalización por vertical se hace al **label
visible** vía `App\Services\BusinessLabelService::labels(Branch)`, sección
`order_statuses`.

| Slug | Restaurante | Café / Bar | Bakery | Catering |
|---|---|---|---|---|
| `in_kitchen` | En cocina | En barra | En horno | En cocina |
| `in_transit` | En domicilio | En domicilio | En domicilio | En ruta |
| `ready` | Listo | Listo | Listo | Listo |

El frontend lee `labels.order_statuses` del endpoint
`GET /api/v1/me/active-context` y lo cachea en el `BusinessProvider`. No hay
duplicación de slugs ni renombrado del `KdsController` (la pantalla sigue
siendo KDS internamente; su label visible se llama "Pantalla de barra" en
cafe/bar y "Pantalla de horno" en bakery vía `labels.modules.kds`).

### Por qué NO renombrar slugs

1. **DIAN**: receipts inmutables, trazabilidad histórica.
2. **Riesgo**: 28+ refs backend + tabla `orders.status` ya poblada en PDN.
3. **Sin valor agregado**: el label dinámico cubre la UX por completo.
4. **Reversible**: si el día de mañana se decide normalizar slugs, sigue
   siendo una migración de datos posterior — el plan no requiere hacerlo
   ahora.

---

## Referencias cruzadas

- `bistro/backend/config/orders.php:1-201` — fuente canónica.
- `bistro/frontend/src/lib/order-status.ts:8-48` — fallback frontend (deuda).
- `CLAUDE.md` raíz §12 "Estados de orden" + "Invariante orders.total".
- `docs/wiki/Pedidos.md` — manual narrativo.
- `bistro/backend/constants/ACCOUNTING_RULES.md` — refunds, conservación, revenue.
- `bistro/backend/constants/PAYMENT_METHODS.md` — receipts asociados.
