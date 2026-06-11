# TAXES_AND_REGIMES — Fuente única de verdad

> **Antes de tocar el catálogo `companies.tax_regime` u `orders.tax_regime`,
> lee este archivo + [`ACCOUNTING_RULES.md`](./ACCOUNTING_RULES.md).**
> **Después de modificar el catálogo, actualiza este archivo + `config/taxes.php`
> + la UI de configuración (`pages/company/settings.tsx`) + las migraciones en
> el mismo PR.**

---

## Fuente de verdad ejecutable

- `bistro/backend/config/taxes.php` — catálogo canónico
  (`regimes` con `rate`+`label` por slug, `available_regimes` plana).
- `bistro/backend/database/migrations/0001_01_01_000100_create_companies_block.php:66`
  — columna `companies.tax_regime` (default `'simple'`).
- `bistro/backend/database/migrations/0001_01_01_000700_create_orders_block.php:52`
  — columna `orders.tax_regime` (snapshot al cerrar la orden, nullable).
- `bistro/backend/app/Http/Requests/Company/UpdateCompanyRequest.php:61` —
  `Rule::in(config('taxes.available_regimes'))`.
- `bistro/backend/app/Http/Controllers/Company/CompanyController.php:60` — expone
  `tax_presets` al frontend desde `config('taxes.regimes')`.

> El `.md` es **referencia humana**. Si difiere del código, el código gana.

---

## Regímenes canónicos (al 2026-05-18)

| slug | label UI (es-CO) | tasa default | Nota |
|---|---|---|---|
| `simple` | Régimen Simple (sin IVA) | 0% | Default. Régimen Simple de Tributación. Restaurantes pequeños/medianos típicamente acogidos. **No factura IVA**. |
| `inc_8` | INC 8% | 8% | Impuesto Nacional al Consumo — restaurantes y bares fuera del Simple. Sustituye al IVA en venta de alimentos preparados. |
| `iva_19` | IVA 19% | 19% | Régimen Común. Tarifa general. Aplica a franquicias / cadenas / régimen común. |
| `iva_5` | IVA 5% | 5% | Tarifa reducida (productos canasta básica, ciertos servicios). Raro en restaurantes. |
| `iva_exento` | IVA Exento | 0% | Productos/servicios exentos con derecho a devolución. Diferente de `simple` para reportes DIAN. |
| `custom` | Personalizado | 0% configurable | Permite que la empresa defina `default_tax_rate` y `default_tax_label` libremente. |

Fuente: `config/taxes.php`.

### Reglas clave

1. **`companies.tax_regime`**: configuración global por empresa. Mutable solo
   con permiso `company.update` (admin restringido a `-CU-`, owner bypass).
2. **`orders.tax_regime`**: snapshot al **crear** la orden (ver
   `OrderController::store` línea 219). Si la empresa cambia de régimen luego,
   las órdenes ya emitidas mantienen su régimen original — DIAN exige
   trazabilidad.
3. **`tax_included_in_price`**: bool por empresa. Si `true`, los precios del
   menú ya incluyen impuesto (`subtotal = total - tax_amount`). Si `false`,
   se calcula sobre el precio base.
4. **Columnas en `orders`**:
   - `tax_amount` `decimal(12,2)` — monto absoluto del impuesto cobrado.
   - `tax_rate` `decimal(5,2)` — tasa aplicada (snapshot del momento).
   - `snapshot_default_tax_rate` `decimal(5,2)` — tasa que tenía la empresa
     al momento de crear la orden (para auditar cambios posteriores).
   - `tax_regime` `varchar(20)` nullable — snapshot del régimen.
   - `tax_included_in_price` bool — snapshot del flag.

### Propina (`tip_amount`)

Columna separada `orders.tip_amount` (`decimal(12,2)`, default `0`). NO suma
a `total`, NO entra a base gravable, NO es ingreso operativo del negocio
(es del mesero). Sugerida 10% en CO, **voluntaria**.

---

## Impacto RBAC

- **Permiso para mutar `tax_regime` de la empresa**: `company.update`. Admin
  tiene `can_create + can_update` por default; owner bypass siempre.
- **Permiso para ver presets** (`/api/v1/company/me`): `company.read` o
  `billing.read`. Owner bypass siempre.
- **Auditoría**: cualquier cambio de `tax_regime` dispara
  `AuditService::log('company.updated', ...)` con los campos modificados.
  Cambios financieros sensibles (régimen, tasa) DEBEN aparecer en el
  metadata para reconstruir el histórico.

---

## Frontend — cómo se consume

- **`pages/company/settings.tsx`**: lee `tax_presets` desde
  `apiFetch('/api/v1/company/me')` (no shared prop) y popula el selector
  `<Select>` (líneas 591-615). Al elegir un régimen, `setDefaultTaxRate` y
  `setDefaultTaxLabel` se auto-rellenan desde el preset.
- No hay shared prop `taxes` en Inertia hoy porque solo lo consume la página
  de configuración. Si en el futuro reportes/menú necesitan el catálogo
  globalmente, exponerlo vía `HandleInertiaRequests::share` siguiendo el
  patrón de `paymentMethods` / `employeeStatuses`.

---

## Cómo añadir un régimen nuevo

1. Editar `config/taxes.php`:
   - Agregar entrada al array `regimes` con `rate` + `label`.
   - Añadir el slug a `available_regimes`.
2. Actualizar el selector en `pages/company/settings.tsx` (líneas 607-614).
   **Idealmente refactorizar para iterar sobre `tax_presets` en lugar de
   listar `<SelectItem>` hardcoded** (deuda técnica abierta).
3. Si el régimen tiene impacto contable distinto (p.ej. nuevo `tax_amount` =
   `subtotal * 0.10`), revisar `OrderController::store` y `closeWithPayment`.
4. Actualizar este `.md` (tabla canónica).
5. PR descripción: "Nuevo régimen `<slug>`. Tasa: `<%>`. Aplica a `<sector>`.
   Reporte DIAN: ¿diferenciado?"

---

## Deuda y consideraciones futuras

- **Selector hardcoded vs `tax_presets`**: el `<Select>` en
  `pages/company/settings.tsx:607-614` lista los 6 regímenes manualmente
  mientras `tax_presets` ya viene de la API. Refactor pendiente para que el
  selector itere sobre `Object.entries(tax_presets)` y el catálogo viva solo
  en `config/taxes.php`.
- **Reportes por régimen**: hoy `orders.tax_regime` se snapshotea pero los
  reportes (`WorkforceReportController`, dashboards) no segmentan por
  régimen. Si la empresa cambia de `simple` a `inc_8`, los gross/net
  agregados mezclan ambos. Issue separado para segmentar reportes por
  período + régimen.
- **Facturación electrónica DIAN**: cuando se implemente CUFE + numeración
  consecutiva (CLAUDE.md §13), el `tax_regime` snapshot será obligatorio en
  el XML. Asegurar que `orders.tax_regime` nunca sea null al cerrar una
  orden que vaya a factura.
- **Régimen `custom`**: introduce ambigüedad para reportes DIAN. Solo
  habilitarlo si la empresa entiende que los reportes consolidados pueden
  no cuadrar con casillas estándar.

---

## Pares espejo que deben mantenerse sincronizados

- `config/taxes.php` ↔ tabla de regímenes de este `.md`.
- `app/Http/Requests/Company/UpdateCompanyRequest.php:61` ↔
  `available_regimes`.
- `app/Http/Controllers/Company/CompanyController.php:60` ↔
  `tax_presets` expuesto al frontend.
- `database/migrations/0001_01_01_000100_create_companies_block.php:66` ↔
  default `'simple'` y longitud `varchar(20)`.
- `database/migrations/0001_01_01_000700_create_orders_block.php:52` ↔
  snapshot column en `orders`.
- `resources/js/pages/company/settings.tsx:607-614` ↔ items del `<Select>`
  (idealmente eliminar al refactorizar).

---

## Referencias cruzadas

- [`ACCOUNTING_RULES.md`](./ACCOUNTING_RULES.md) — reglas contables CO
  (decimal(12,2), DB::transaction, refunds, conservación DIAN).
- [`ORDER_STATUSES.md`](./ORDER_STATUSES.md) — `orders.tax_regime` se
  snapshotea al pasar por `pending → completed`.
- [`PAYMENT_METHODS.md`](./PAYMENT_METHODS.md) — método de pago no afecta
  régimen, pero ambos viajan en `payment_receipts` y reportes.
- [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) — `company.updated` registra
  cambios de régimen.

> Última revisión: 2026-05-18 (#204)
