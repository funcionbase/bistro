# ACCOUNTING_RULES — Fuente única de verdad (resumen estructurado)

> **Antes de tocar tablas/lógica financiera, lee este archivo + `CLAUDE.md` §12.**
> **Después de modificar reglas contables, actualiza este archivo + `CLAUDE.md` §12 +
> `PAYMENT_METHODS.md` + las migraciones en el mismo PR.**

> Fuente narrativa primaria: `CLAUDE.md` raíz §12 "Reglas contables (legislación colombiana)".
> Este archivo es el **resumen estructurado** para checklist pre-merge.

## Archivos que deben quedar sincronizados

- [ ] `CLAUDE.md` raíz §12 — fuente narrativa principal
- [ ] `bistro/backend/constants/PAYMENT_METHODS.md` — convención de signos y métodos
- [ ] `bistro/backend/constants/ORDER_STATUSES.md` — revenue_statuses, refunds, terminales
- [ ] Migraciones de columnas monetarias (`decimal(12,2)`)
- [ ] Eloquent `casts()` (`'campo' => 'decimal:2'`)
- [ ] `bistro/backend/config/orders.php` (`timezone`, `revenue`)
- [ ] `bistro/backend/app/Services/AuditService.php` — toda mutación financiera audita
- [ ] Controllers financieros (`OrderController`, `CashRegisterController`, `PurchaseController`)

---

## Checklist pre-merge para PRs financieros

> Pegar esta sección como checklist en la descripción del PR si toca dinero.

- [ ] **Mutaciones envueltas** en `DB::transaction(...)` con `Order::lockForUpdate()` (o equivalente).
- [ ] **`AuditService::log(...)` invocado** con metadata reconstructible (montos, referencias, motivo, actor).
- [ ] **Columnas monetarias** `decimal(12,2)` en migración + `cast('decimal:2')` en modelo.
- [ ] **Reportes consumen `config('orders.*')`**, no listas hardcoded.
- [ ] **Operación reversible vía nuevo asiento** (refund/nota crédito), nunca `UPDATE`/`DELETE` de un receipt o factura ya creada.
- [ ] **Si introduce `payment_method` nuevo**: lista cerrada actualizada en `PAYMENT_METHODS.md` + todos los reportes ajustados.
- [ ] **Política documentada** en `docs/wiki/BACKEND_FILES.md` (o módulo correspondiente).
- [ ] **Filtros de período**: `ordered_at` para órdenes, `paid_at` para receipts. NO `created_at`.
- [ ] **Timezone explícita**: `America/Bogota` en filtros que involucren "del día".
- [ ] **No mutaciones retroactivas** a órdenes en estado terminal (afecta KPIs estables).

---

## Reglas resumidas

### Moneda y precisión

| Regla | Valor / patrón |
|---|---|
| Moneda default | **COP** |
| Multimoneda | No por ahora. Si se añade: columna `currency_code` (ISO 4217). NUNCA mezclar monedas en la misma columna. |
| Precisión BD | `decimal(12,2)` mínimo |
| Cast Eloquent | `'amount' => 'decimal:2'` en `casts()` |
| Aritmética PHP | `App\Support\Money::round($v, 2)` — **banker's rounding** (PHP_ROUND_HALF_EVEN). NUNCA `float`/`double` ni `round()` plano. |
| Aritmética TS | `roundMoney(v, 2)` de `frontend/src/lib/money.ts` — espejo del helper PHP. |
| Presentación CO | Truncar a peso (sin decimales) vía `formatCOP`. **Solo presentación**, BD siempre `decimal:2`. |

#### Redondeo bancario (half-to-even)

En #246 (pricing SaaS) adoptamos **PHP_ROUND_HALF_EVEN** como convención única para
todo cálculo que produzca un monto persistido. Razón: en cierres agregados,
"half up" introduce sesgo positivo. "Half to even" lo neutraliza promediando
la decisión sobre los enteros pares.

Helpers obligatorios:

- PHP: `App\Support\Money::round($v, 2)` / `Money::applyPercent($base, $pct)` / `Money::extractBase($gross, $rate)` / `Money::sum([...])`.
- TS: `roundMoney`, `applyPercent`, `extractBase`, `sumMoney` en `frontend/src/lib/money.ts`.

Cualquier `round()` plano en `BillingService`, `DianXmlBuilder`, `PromoCodeService`
y `TaxCalculator` debe migrarse al helper.

### Inmutabilidad y trazabilidad

- `payment_receipts` son **inmutables**. Para corregir → otro registro (refund con monto negativo, nota de ajuste).
- Mutaciones financieras (`closeWithPayment`, `refund`, `cancel`, `appendItems`, `updateStatus`):
  1. `DB::transaction(...)`.
  2. `Order::lockForUpdate()` sobre la entidad principal.
  3. `AuditService::log(...)` con acción + metadatos.
- No endpoints que muten receipts ya creados. DIAN exige trazabilidad histórica.

### Signos en `payment_receipts`

| Campo | Valor canónico |
|---|---|
| `payment_method` | `cash \| card \| transfer \| refund` (lista cerrada) |
| `amount` | **signed**: cobros positivos, refunds negativos |
| Neto del día | `SUM(amount) GROUP BY payment_method` en SQL, NO en PHP |

### Estados de orden

- Fuente única: `config/orders.php`. NUNCA duplicar listas en controllers/services/blades/frontend.
- `revenue_statuses = ['completed']`. NO incluir `in_transit` ni pre-entrega.
- Terminales son finales. Refund crea nuevo asiento, no muta el original.

### Invariante `orders.total`

| Regla | Detalle |
|---|---|
| Cálculo | `SUM(line_total)` con items no excluidos (≠ `cancelled`), desglose por línea vía `TaxCalculator::calculateLine` (#293) |
| Fuente de líneas | Filas `order_items` (creadas por `OrderController::materializeOrderItems` en caja/sync y por los servicios QR). `orders.items` JSON = **proyección de lectura**. |
| Creación (caja/sync) | `OrderController::buildOrderLines` → desglose por línea + `materializeOrderItems` → filas con `tax_rate` snapshoteado |
| Mutación de líneas | `App\Support\OrderTotalCalculator::recalculateAndSave` → recalcula `subtotal/tax_amount/tax_rate/total/cost` desde filas y reproyecta el JSON (QR + `appendItems`; fallback JSON-only para órdenes legacy sin filas) |
| Snapshot tributario | Ambos flujos usan el snapshot de la orden (`tax_included_in_price`, `snapshot_default_tax_rate`, `order_items.tax_rate` por línea) — NUNCA el estado vivo de la empresa |
| Descuento | `orders.total` viene **neto del descuento**. `discount_amount` es informativo. NUNCA restarlo en reportes (doble descuento). |
| Origen del precio | Menú activo en BD, NUNCA del payload del cliente. |

### Impuestos (IVA / INC)

| Régimen | Tarifa | Aplica a |
|---|---|---|
| `iva_19` | 19% | Régimen común (franquicias, restaurantes formales) |
| `iva_5` | 5% | Tarifas diferenciales (raro) |
| `iva_exento` | 0% | Productos exentos (raro) |
| `inc_8` | 8% | Restaurantes y bares no responsables de IVA |
| `simple_no_iva` | — | Régimen Simple (RST). NO factura IVA. |

- Hoy `orders.total` es precio final sin desglose. Al implementar IVA/INC: migración con `tax_amount`, `tax_rate`, `tax_regime`.
- **Propina**: voluntaria 10% sugerida. Columna separada `tip_amount`. NO suma a `total` ni a base gravable. La puede dejar el cliente en el checkout público (F2); `closeWithPayment` la CONSERVA si el request no manda `tip_amount` (null ≠ 0: enviar 0 explícito la anula).
- **Columnas informativas del checkout público (F2)**: `orders.payment_preference` (slug canónico elegido por el cliente), `orders.cash_pays_with` ("¿con cuánto pagas?", solo efectivo) y `orders.customer_notes` (indicaciones de entrega). NINGUNA participa en `total`, base gravable ni `payment_receipts` — el pago real siempre lo registra caja.
- **Abono del domiciliario (F6)**: ES el pago de la orden, modelado como `PaymentReceipt` cash normal con `payment_data.courier_advance=true` + `courier_user_id` (ver `PAYMENT_METHODS.md`). El arqueo cuadra sin lógica nueva; la reversión por entrega fallida es el `refund` existente (asiento negativo, orden → `refunded`). `revenue` sigue siendo solo `completed` — el receipt en `in_transit` no altera reportes de ingreso. Guard 409 `ORDER_ALREADY_PAID` en `closeWithPayment` evita doble cobro. Sin abono previo, la cancelación `category=no_show` activa el estado `failed`.

### Facturación electrónica DIAN

- Facturas requieren **CUFE** + numeración consecutiva autorizada.
- Servicio dedicado (no en `OrderController`). Tabla `invoices` separada.
- Facturas inmutables. Anulación = nota crédito (otro documento).
- **Conservación**: 5 años (personas naturales) / 10 años (jurídicas). Soft-delete máximo, jamás `truncate` en PDN.

### Descuento comercial UBL (`AllowanceCharge`)

En invoices SaaS con promo aplicado, el descuento se modela como
`AllowanceCharge` UBL **antes del IVA**. Esto significa que la base gravable
ya viene reducida por el descuento — el IVA se calcula sobre la base neta,
no sobre la base bruta.

Fórmula (ejemplo plan 100k bruto, descuento 20%, IVA 19%):

```
base_original     = 100.000 / 1,19 = 84.033,61
descuento_pct     = 20
descuento_base    = round_even(base_original × 0,20) = 16.806,72
base_neta         = 84.033,61 − 16.806,72 = 67.226,89
iva_amount        = round_even(base_neta × 0,19) = 12.773,11
total_final       = 67.226,89 + 12.773,11 = 80.000,00
```

XML UBL emite `AllowanceCharge` con `ChargeIndicator=false`,
`MultiplierFactorNumeric=0.20`, `BaseAmount=84.033,61`, `Amount=16.806,72`.

### Período post-pago (mes vencido)

Las invoices SaaS son **post-pago**: el primero de cada mes se factura el mes
anterior completo.

- `period_from` = primer día mes anterior (00:00:00 `America/Bogota`).
- `period_to` = último día mes anterior (23:59:59).
- `due_date` = día 15 del mes actual (configurable vía `billing.due_day`).
- Factura del 1 de junio cubre 1–31 mayo.

### Soft-delete policy

| Tabla | Soft-delete | Justificación |
|---|---|---|
| `promo_codes` | ✅ | Catálogo administrable, se pueden archivar sin perder histórico |
| `company_promo_codes` | ✅ | Se cancelan sin destruir; histórico contable conservable |
| `subscriptions` | ✅ | Contrato cancelable, conservar para auditoría |
| `invoices` | ❌ | **Inmutables** — anulación vía nota crédito |
| `electronic_documents` | ❌ | **Inmutables** — exigido por DIAN |
| `invoice_payments` | ❌ | **Inmutables** — refund crea nueva fila |

### Retención 10 años

Las facturas e invoices DIAN se conservan **10 años** (personas jurídicas).
No hay job de cleanup automático — política pasiva. Soft-deleted records sin
auto-purge. Si en el futuro hay cumplimiento de "olvido", se evalúa entonces.

### Devoluciones y notas crédito

- Refund crea `PaymentReceipt` con `amount` negativo. Orden → `refunded`.
- **Card/transfer**: **siempre** exigir `reference` (número de comprobante de la devolución). Único respaldo contable.
- **Efectivo**: puede omitir `reference`, pero registrar `actor_id` en AuditLog.
- **Refunds parciales**: múltiples filas negativas por orden — sumar todos.

### Reportes y cierres

- Agregación en SQL (`selectRaw('SUM(...) GROUP BY ...')`), NUNCA iterando en PHP.
- Mostrar **gross / refunds / net** explícitos. No solo gross — induce a error contable.
- Filtros: `ordered_at` para órdenes, `paid_at` para receipts. NO siempre coinciden.
- Cuadre de caja: agrupar por `paid_at::date AT TIME ZONE 'America/Bogota'`.
- **KPIs históricos estables**: cerrado un período, recálculos posteriores deben dar el mismo número. No permitir mutaciones retroactivas a estado terminal.

### Inventario: WAC por bodega (#costeo-multibodega)

El insumo es **catálogo de empresa** (`ingredients` sin `branch_id`, único por
`(company_nit, name)`). El stock y el **WAC (promedio ponderado)** viven por
bodega en `ingredient_stocks (quantity, current_cost decimal(12,2))`. El insumo
ya **no** tiene un costo global (`ingredients.current_cost` fue eliminado).

| Regla | Detalle |
|---|---|
| WAC | Por `(ingredient, warehouse)` en `ingredient_stocks.current_cost`, `decimal:2`, `bcmath`. Se recalcula solo en `entry` sobre el stock de ESA bodega (`InventoryService::weightedAverageCost`). |
| Transferencia | Traslada valor: la bodega destino mezcla su WAC con el costo entrante (= WAC de la bodega origen); el WAC del origen no cambia. Movimientos `transfer` son inmutables. |
| Costeo de receta | `RecipeCostService::compute($companyNit, $branchId, $menuItemId)` — **filtra por sede** y costea cada línea desde `ingredient_stocks.current_cost` de la bodega de la línea (`recipes.warehouse_id`, NOT NULL). Línea sin fila de stock → costo 0 + `misconfigured=true`. |
| Snapshot food cost | `menu_item_cost_history` unique `(company_nit, branch_id, menu_item_id, snapshot_date)` — un snapshot por sede/día (antes "ganaba la última sede" con cartas clonadas). |
| Valorización | `SUM(quantity × ingredient_stocks.current_cost)` por bodega, en SQL. El `cost` mostrado es el WAC ponderado entre bodegas (`value / stock`). |
| Reverso de compra | `voidWithCreditNote` valora el reverso al WAC **corriente de la bodega** de la recepción (`purchase_order_items.warehouse_id`), no a un costo global. |
| Consolidación | Migración `consolidate_ingredients_company_wide`: fusión automática de homónimos por `(company_nit, nombre_norm, unit)` con artefacto de auditoría en `storage/app/inventory/` + veto pre-PDN en QA. Movimientos inmutables: solo se re-apuntan FKs, nunca se editan importes. |

Movimientos (`ingredient_movements`) siguen siendo **append-only**; el WAC se
recomputa leyéndolos, nunca mutándolos.

---

## Anti-patrones prohibidos

- ❌ Sumar órdenes en PHP y restar refunds aparte. Hacerlo en SQL con SUM(amount).
- ❌ Mostrar solo gross en reportes (sin refunds/net).
- ❌ `UPDATE` de un `PaymentReceipt` ya creado.
- ❌ `DELETE` físico de receipts o invoices antes de la conservación DIAN.
- ❌ Restar `discount_amount` de `orders.total` en reportes (doble descuento).
- ❌ Filtrar receipts por `created_at` (en cuadre debe ser `paid_at`).
- ❌ `float` o `double` para columnas monetarias.
- ❌ Trustear `price`/`total` del payload del cliente.
- ❌ Saltarse `DB::transaction` + `lockForUpdate` en una mutación financiera concurrente.

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202)_

---

## Referencias cruzadas

- `CLAUDE.md` raíz §12 — narrativa primaria.
- `bistro/backend/constants/PAYMENT_METHODS.md` — lista cerrada de métodos.
- `bistro/backend/constants/ORDER_STATUSES.md` — revenue + terminales.
- `bistro/backend/constants/AUDIT_EVENTS.md` — qué se audita y con qué metadata.
