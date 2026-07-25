# PAYMENT_METHODS — Fuente única de verdad

> **Antes de modificar métodos de pago o reglas de receipts, lee este archivo.**
> **Después de modificar, actualiza este archivo + `CLAUDE.md` §12 + el enum
> en `PaymentReceipt` model + reportes en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `bistro/backend/config/payments.php` — **fuente única canónica** (#203): `methods`, `receipt_methods`, `labels`, `requires_reference`
- [ ] `bistro/backend/app/Http/Middleware/HandleInertiaRequests.php:208-217` — shared prop `paymentMethods`
- [ ] `bistro/frontend/src/types/index.ts` — `PaymentMethod`, `PaymentReceiptMethod`, `PaymentMethodsConfig`
- [ ] `bistro/frontend/src/hooks/use-payment-methods.ts` — hook `usePaymentMethods()` + fallback embebido
- [ ] `bistro/backend/config/cash_register.php:33` — `expense_payment_methods` (espejo)
- [ ] `bistro/backend/config/purchases.php:42` — `payment_methods` (espejo)
- [ ] `CLAUDE.md` raíz §13 "Reglas contables (legislación colombiana)" — fuente narrativa primaria
- [ ] `bistro/backend/app/Models/PaymentReceipt.php` — enum/casts de `payment_method`
- [ ] Migración de `payment_receipts` (`amount` signed `decimal(12,2)`, columnas `reference`, `paid_at`)
- [ ] Controllers que crean receipts (`OrderController::closeWithPayment`, `OrderController::refund`)
- [ ] `bistro/backend/app/Http/Controllers/Reports/*` — agregación por `payment_method` con SUM(amount)
- [ ] Componentes frontend consumers: `payment-method-picker.tsx`, `split-payment-sheet.tsx`, `expense-modal.tsx`, `cash-drawer-card.tsx`, `refund-summary-card.tsx`, `pages/caja/table-session.tsx`
- [ ] `bistro/backend/constants/ORDER_STATUSES.md` — transición a `refunded`
- [ ] `bistro/backend/constants/ACCOUNTING_RULES.md` — reglas DIAN cruzadas

---

## Divergencias / deuda detectadas (al 2026-05-18)

- ✅ **Resuelto en #203**: `'cash' | 'card' | 'transfer'` y `'cash' | 'card' | 'transfer' | 'refund'` vivían hardcoded en 10+ archivos TS (`cash-register-panel`, `expense-modal`, `use-cash-register`, `split-payment-sheet`, `payment-method-picker`, `cash-drawer-card`, `refund-summary-card`, `order-detail-modal`, `use-orders`, `use-tables`, `lib/offline/db`, `pages/caja/table-session`). Creamos `config/payments.php` como **fuente única canónica**, lo exponemos via Inertia `paymentMethods` shared prop, tipos centralizados (`PaymentMethod`, `PaymentReceiptMethod`) en `types/index.ts`, y hook `usePaymentMethods()` con fallback embebido (similar a `lib/order-status.ts`). Los selectores y labels en componentes ahora consumen del catálogo.

---

## Lista cerrada de `payment_method`

**No se inventan valores nuevos sin sub-issue + migración + actualización de todos los reportes.**

### Métodos en `payment_receipts` (DB — lista DIAN cerrada)

| Valor | Signo | Reference obligatoria | Caso de uso |
|---|---|---|---|
| `cash` | positivo | no (recomendada en refund con autorización) | Pago en efectivo. Cuadre de caja diario. |
| `card` | positivo | sí (voucher / autorización POS) | Pago con tarjeta débito/crédito. |
| `transfer` | positivo | sí (número de comprobante PSE/Nequi/Daviplata) | Transferencia electrónica. |
| `refund` | negativo (amount < 0) | **sí siempre para card/transfer**; opcional para cash con `actor_id` audit | Devolución (parcial o total). Crea nuevo asiento, NO modifica el receipt original. |

### Métodos en UI (`config/payments.php` → `methods`) — BUG-022 aclarado

La UI expone **5 métodos** seleccionables al cobrar: `cash | card | transfer | nequi | daviplata`.  
`nequi` y `daviplata` son **alias de UI** que el backend normaliza a `transfer` en `payment_receipts`.  
Esto preserva la lista DIAN cerrada (4 valores) sin perder la distinción Nequi/Daviplata para el cajero.

| Método UI | Almacenado como | Reference obligatoria |
|---|---|---|
| `nequi` | `transfer` | sí |
| `daviplata` | `transfer` | sí |

Fuente: `bistro/backend/config/payments.php` → `'methods'` vs `'receipt_methods'`.
Fuente narrativa: `CLAUDE.md` §12 "Convención de signos en payment_receipts".

### Aliases de empresa (`company_settings.payment_methods`) — checkout público F2

`/company/preferences` guarda los métodos habilitados con slugs en **español**
(legado). `config('payments.company_aliases')` los mapea al canónico para el
checkout público:

| Slug empresa | Canónico |
|---|---|
| `efectivo` | `cash` |
| `tarjeta` | `card` |
| `transferencia` | `transfer` |
| `nequi` | `nequi` |
| `daviplata` | `daviplata` |

- `MenuController::buildPublicPaymentMethods` expone al menú público
  `restaurant.payment_methods[{slug, label, account}]` (account =
  `company_settings.payment_method_accounts[slug español]`).
- El cliente elige y `orders.payment_preference` guarda el slug **canónico**.
  Es **INFORMATIVO**: no crea receipt ni toca total. El pago real lo registra
  caja (`closeWithPayment`), que normaliza nequi/daviplata → `transfer`.
- `orders.cash_pays_with` (decimal 12,2) = "¿con cuánto vas a pagar?" del
  cliente (solo efectivo, validado ≥ total+propina en el controller).
  Informativo — las devueltas reales se calculan al cobrar.

---

## Convenciones críticas

### 1. Receipts son INMUTABLES

```
No UPDATE de PaymentReceipt jamás. Para corregir:
  - Refund: crear PaymentReceipt con method='refund' + amount negativo.
  - Nota de ajuste: nueva fila, no edición.
```

### 2. Amount es SIGNED

```sql
-- Net por método:
SELECT payment_method, SUM(amount) AS net
FROM payment_receipts
WHERE paid_at >= :start AND paid_at < :end
GROUP BY payment_method;

-- Total neto del día:
SELECT SUM(amount) AS net_total FROM payment_receipts ...;
```

NUNCA sumar órdenes y restar refunds en PHP cuando se puede en SQL.

### 3. Precisión: `decimal(12,2)`

Todas las columnas monetarias (`amount`, `total`, `tip_amount`, `discount_amount`, `tax_amount`):

- Migración: `->decimal('amount', 12, 2)`.
- Eloquent cast: `'amount' => 'decimal:2'` en `casts()`.
- PHP: `round($v, 2)` al componer totales. `round` no `(int)`.
- **NUNCA `float`/`double`** (errores de coma flotante en moneda).

### 4. Mutaciones bajo lock

Toda creación de receipt:

```php
DB::transaction(function () use ($order, $amount, $method, $reference) {
    $order = Order::lockForUpdate()->find($order->id); // re-fetch con lock
    // validaciones...
    $receipt = PaymentReceipt::create([...]);
    AuditService::log('order.close_with_payment', $actor, $order, [
        'amount' => $amount,
        'method' => $method,
        'reference' => $reference,
    ]);
});
```

### 5. Filtros de período

- **Órdenes** → filtrar por `ordered_at`.
- **Receipts / cuadre de caja** → filtrar por `paid_at`.
- **No siempre coinciden** (cobro al día siguiente). NUNCA usar `created_at` para reportes financieros.
- Timezone: `America/Bogota` (UTC-5 sin DST). `paid_at::date AT TIME ZONE 'America/Bogota'`.

### 6. Refunds parciales

`payment_receipts.amount` soporta múltiples filas negativas por la misma `order_id`. Estado de la orden:

- Total devuelto = SUM positivos → `refunded` (terminal_failure).
- Total parcial → orden sigue en su estado (la diferencia queda como saldo a favor o crédito en otra modalidad).

---

## Reglas tributarias colombianas (resumen)

Detalle completo en `ACCOUNTING_RULES.md` y `CLAUDE.md` §12.

| Régimen | IVA | INC | Aplicación típica |
|---|---|---|---|
| `iva_19` | 19% | — | Régimen común (franquicias, restaurantes grandes formales) |
| `inc_8` | — | 8% | Restaurantes y bares no responsables de IVA |
| `simple_no_iva` | — | — | Régimen Simple (RST) — reporta sin desglose IVA |
| `iva_exento` | 0% | — | Productos exentos (raro en restaurantes) |
| `iva_5` | 5% | — | Tarifa diferencial (raro en restaurantes) |

Hoy el sistema no desglosa impuestos (`orders.total` = final). Al migrar, agregar `tax_amount`, `tax_rate`, `tax_regime` (`CLAUDE.md` §12 → "Impuestos (IVA / INC)").

### Propina

- **Voluntaria 10% sugerida** (CO ley).
- Columna separada `tip_amount` cuando se cobre.
- **NO suma a `total`** ni a base gravable.

### Conservación DIAN

- **5 años** personas naturales.
- **10 años** personas jurídicas.
- Receipts y facturas: **soft-delete máximo, jamás `truncate` en PDN**.
- Facturación electrónica DIAN: tabla `invoices` separada de `orders`. CUFE + numeración consecutiva autorizada. Anulación = nota crédito (otro documento), no `UPDATE`.

### Reportes

- Agregación en SQL (`selectRaw('SUM(...) GROUP BY ...')`).
- Mostrar **gross / refunds / net** explícito — no solo gross.
- KPIs históricos estables: no permitir mutaciones retroactivas a órdenes en estado terminal.

---

## Cómo añadir un método de pago nuevo

> ⚠️ **Decisión de producto + contabilidad.** Requiere sub-issue, no se hace al pasar.

1. Plantear el caso de negocio: ¿es realmente nuevo o cae en `card`/`transfer`? (Ej: "Nequi" cae en `transfer`).
2. Migración:
   - Si la columna es `enum` PostgreSQL → `ALTER TYPE ... ADD VALUE`.
   - Si es `varchar` + check constraint → migración con drop+add.
3. Decidir signo: cobros positivos. ¿Existe variante "refund de este método"? Si sí, usar `refund` general; si requiere registro propio → otro slug.
4. Decidir si exige `reference`: tarjeta/transferencia → sí; efectivo → no (pero audit con `actor_id`).
5. Actualizar `PaymentReceipt` model (cast/enum).
6. Actualizar TODOS los reportes financieros: `Reports/*Controller`, exports PDF, dashboards. Buscar `payment_method` en `bistro/backend/app/` y agregar la nueva opción.
7. Actualizar UI: `PaymentModal.tsx` con el nuevo selector.
8. Actualizar la tabla canónica de este `.md`.
9. PR debe declarar: "Nuevo `payment_method`: `<x>`. Signo: positivo/negativo. Reference: sí/no. Impacto reportes: actualizados en … ."

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202 la lista cerrada es `cash | card | transfer | refund`)_

---

## Referencias cruzadas

- `CLAUDE.md` raíz §12 "Reglas contables (legislación colombiana)" — fuente narrativa primaria.
- `bistro/backend/constants/ACCOUNTING_RULES.md` — resumen estructurado.
- `bistro/backend/constants/ORDER_STATUSES.md` — transición a `refunded`.
- `bistro/backend/app/Models/PaymentReceipt.php` — modelo Eloquent (cast/enum).
- `bistro/backend/app/Http/Controllers/Api/OrderController.php` — `closeWithPayment`, `refund`.
- `docs/wiki/Facturación.md` — manual narrativo.
