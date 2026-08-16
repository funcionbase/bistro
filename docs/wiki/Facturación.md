# Facturación

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

bistro factura mensualmente (post-pago) la suscripción de cada empresa al plan vigente. El módulo soporta:

- Suscripción única por empresa. Desde 2026-07 hay dos planes: **Plan Básico** (slug `default`, $0 COP/mes, is_default — plataforma completa sin costo) y **Plan Plus** (slug `plus`, $300.000 COP/mes IVA 19% incluido + `BILLING_DIAN_UNIT_PRICE` (default $10) COP por documento electrónico DIAN emitido en el período, incluye módulo DIAN). El cargo por uso se calcula sobre `electronic_documents.issued_at` del mes facturado (todos los `document_type`/`status`, cuenta lo que consumió consecutivo) y se agrega como líneas adicionales del invoice (una por resolución) — no recibe descuento de promo code, solo la mensualidad lo recibe (`BillingService::computeInvoiceBreakdown`). El cambio de plan se opera con `billing:change-plan` (workflow `bistro-ops-company-plan.yml`). Los tiers legacy `starter`/`basic`/`pro`/`enterprise` quedan inactivos para preservar FKs (#246). Con precio $0 no se generan invoices (guard en `generateMonthlyInvoices`); invoices de $0 por descuento 100% se auto-pagan al vencimiento.
- Generación automática de facturas mensuales por cron (día 1 a las 03:00 América/Bogotá).
- Marcado de mora con `due_day` + gracia configurable en meses (`BILLING_PAST_DUE_GRACE_MONTHS`).
- Descuentos por promo code (`subscription_discounts` + `company_promo_codes`).
- PDFs descargables con CUFE/QR DIAN cuando `BILLING_EMIT_DIAN_FOR_INVOICES=true`.

---

## Modelo

| Tabla | Campos clave |
|-------|--------------|
| `billing_plans` | `id` UUID, `slug` (unique), `name`, `price` `decimal(12,2)`, `currency` `CHAR(3)` default `COP`, `billing_cycle` (`monthly`), `features` (JSONB), `is_active`, `is_default`, `tax_regime`, `tax_rate`, `price_includes_tax` |
| `subscriptions` | `id` UUID, `company_nit`, `billing_plan_id`, `status` (`active`/`paused`/`cancelled`), `starts_at`, `ends_at`, `cancelled_at`, `cancelled_by`. UNIQUE parcial `WHERE status='active'` por empresa. |
| `subscription_discounts` | `id` UUID, `company_nit`, `discount_percent` `decimal(5,2)` con CHECK > 0 ≤ 100, `description`, `starts_at`, `ends_at`, `months_duration`, `status`, `created_by`, `cancelled_by`, `cancelled_at` |
| `invoices` | `id` UUID, `company_nit`, `subscription_id`, `type`, `period_from`, `period_to`, `days_billed`, `base_amount`, `base_amount_taxable`, `discount_percent`, `discount_amount`, `tax_amount`, `tax_rate`, `tax_regime`, `amount` (total a cobrar), `currency`, `due_date`, `generated_at`, `status` (`draft`/`pending`/`paid`/`overdue`/`voided`), `voided_by_invoice_id` (self-FK), `pdf_path`, `pdf_generated_at`, `electronic_document_id`, `plan_name_snapshot`, `plan_price_snapshot`, `company_promo_code_id`. UNIQUE parcial `(subscription_id, period_from, period_to) WHERE status!='voided'`. |
| `invoice_lines` | `id` UUID, `invoice_id`, `description`, `quantity`, `unit_price`, `subtotal`, `sort_order` |
| `invoice_payments` | `id` UUID, `invoice_id`, `company_nit`, `amount`, `currency`, `payment_reference` (obligatoria), `payment_date`, `payment_method`, `registered_by`, `notes` |

Todos los montos son `decimal(12,2)` con cast `decimal:2` en el modelo (§13 CLAUDE.md). El modelo `Invoice` bloquea mutaciones a campos financieros tras la creación (lanza `LogicException` en `updating`).

`base_amount` es el bruto TOTAL pre-descuento — plan + cargo por uso DIAN cuando aplica — de forma que `base_amount − discount_amount = amount` siempre reconcilie (PDF/CSV/frontend muestran esa resta). El descuento de promo code solo pega sobre el plan; el cargo por uso nunca se descuenta.

---

## Estados de factura

```
draft → pending → paid
                ↘ overdue
                ↘ voided (por nota crédito)
```

| Estado | Significado |
|--------|-------------|
| `draft` | Generada pero no enviada |
| `pending` | Enviada, esperando pago |
| `paid` | Pagada (suma de `invoice_payments` ≥ `amount_due`) |
| `overdue` | Vencida (no pagada después de `BILLING_DUE_DAY`) |
| `voided` | Anulada por una nota crédito |

Las facturas `paid` y `voided` son **inmutables**.

---

## Endpoints

### Suscripción y facturas (panel admin)

Todos requieren JWT de usuario + `permission:billing.read,read`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/billing/plans` | Catálogo de planes activos |
| `GET` | `/api/v1/billing/subscription` | Suscripción activa + `overdue_total` + `earliest_overdue_date` + `dian_usage` (solo planes con módulo DIAN) |
| `GET` | `/api/v1/billing/invoices` | Lista paginada de facturas (filtros por status/período) |
| `GET` | `/api/v1/billing/invoices/export.csv` | Export CSV |
| `GET` | `/api/v1/billing/invoices/{id}` | Detalle con `lines` + `payments` |
| `GET` | `/api/v1/billing/invoices/{id}/download` | Descarga PDF autenticada |

### Comprobantes de pago manuales (#175)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/billing/payment-proofs` | Historial de comprobantes |
| `POST` | `/api/v1/billing/payment-proofs` | Sube comprobante (exige `billing.write`) |
| `GET` | `/api/v1/billing/payment-proofs/{uuid}` | Stream del archivo para preview inline |

### Promo codes self-service (#246)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/billing/promo-code` | Código activo en la empresa |
| `POST` | `/api/v1/billing/promo-code/preview` | Vista previa de impacto |
| `POST` | `/api/v1/billing/promo-code` | Aplica código (owner/admin) |
| `DELETE` | `/api/v1/billing/promo-code` | Cancela código activo (owner/admin) |

### Plan default público (sin JWT)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/billing/plans/default` | Plan default (slug `default`) para enrollment |

### PDF público (URL firmada)

| Método | Ruta | Auth |
|--------|------|------|
| `GET` | `/api/v1/billing/invoices/{id}/pdf` | URL firmada (`signed`), TTL `BILLING_DOWNLOAD_TTL` (default 3600s) |

---

## Ejemplos

### Suscripción actual

```http
GET /api/v1/billing/subscription HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "subscription": {
    "id": "uuid",
    "plan": { "slug": "default", "name": "Plan Default", "price": "100000.00", "currency": "COP", "billing_cycle": "monthly" },
    "status": "active",
    "starts_at": "2025-11-01",
    "ends_at": null,
    "discount": null
  },
  "overdue_total": 0,
  "earliest_overdue_date": null,
  "dian_usage": {
    "period_from": "2026-07-01",
    "period_to": "2026-07-31",
    "unit_price": 10,
    "total_documents": 128,
    "usage_amount": 1280,
    "plan_amount": 300000,
    "estimated_total": 301280,
    "resolutions": [
      { "resolution_id": "uuid", "prefix": "NCFE", "resolution_number": "18760000003", "document_type": "invoice", "count": 128 }
    ]
  }
}
```

`dian_usage` es `null` cuando el plan de la empresa no incluye `'dian'` en `features` (Plan Básico). Muestra el período EN CURSO (mes calendario actual), no el ya facturado — `estimated_total` no aplica descuento de promo code (informativo; el descuento real solo se ve en el invoice generado el día 1).

### Lista paginada de facturas

```http
GET /api/v1/billing/invoices?page=1 HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "data": [
    {
      "id": "uuid",
      "period_from": "2026-05-01",
      "period_to": "2026-05-31",
      "base_amount": "100000.00",
      "tax_amount": "15966.39",
      "tax_rate": "19.00",
      "tax_regime": "iva_19",
      "amount": "100000.00",
      "currency": "COP",
      "due_date": "2026-06-15",
      "status": "paid",
      "generated_at": "2026-06-01T03:00:00-05:00"
    }
  ],
  "current_page": 1,
  "last_page": 6,
  "total": 60,
  "per_page": 10
}
```

---

## Comandos programados

Definidos en `routes/console.php`. Todos N-instance safe con `->onOneServer()` + `->withoutOverlapping()` (cache lock vía `CACHE_STORE=database` sobre PostgreSQL — el proyecto NO usa Redis).

| Comando Artisan | Cron | Descripción |
|------------------|------|-------------|
| `billing:generate-monthly-invoices` | `0 3 1 * *` (post-pago: factura el mes anterior) | Genera facturas el día 1 a las 03:00 Bogotá |
| `billing:mark-overdue-invoices` | diario 04:30 | Marca `pending → overdue` cuando `due_date < today` y recalcula `company.status` |
| `billing:expire-discounts` | diario 04:45 | Expira `company_promo_codes.status='active'` con `ends_at < today` (#246) |
| `companies:recalculate-statuses` | cada 4 horas | Reactivación post-pago: past_due/suspended → active si deuda liquidada (#193) |
| `billing:export-delinquent` | diario 05:30 | Export CSV diario a S3 con foto de empresas past_due/suspended |

Defensa contable adicional: `BillingService::generateMonthlyInvoices` envuelve cada invoice en `DB::transaction` con `lockForUpdate`, y el UNIQUE parcial `(subscription_id, period_from, period_to) WHERE status!='voided'` rechaza carreras entre workers.

Las fechas son configurables en `.env` (`BILLING_GENERATE_DAY=1`, `BILLING_GENERATE_HOUR=3`, `BILLING_DUE_DAY=10`, `BILLING_OVERDUE_DAY=16` — ver [Variables de Entorno](Variables-de-Entorno.md)). El ciclo cierra el día 1 de cada mes (mes calendario completo) y el pago vence el día 10.

---

## Configuración

`config/billing.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `currency` | `COP` | Moneda (ISO 4217) |
| `grace_months` | `2` | Meses de gracia legacy (uso interno) |
| `due_day` | `15` | Día del mes en que vence la factura |
| `generate_day` | `1` | Día del mes de generación (post-pago: factura el mes anterior) |
| `overdue_day` | `16` | Día a partir del cual se marcan vencidas |
| `funcionbase_tax_regime` | `iva_19` | Régimen fiscal de bistro como proveedor SaaS |
| `funcionbase_tax_rate` | `19.00` | Tasa IVA del plan default |
| `default_plan_slug` | `default` | Slug del plan default usado por `/billing/plans/default` |
| `emit_dian_for_invoices` | `true` | Si dispara `EmitDianInvoiceJob` tras generar invoice |
| `past_due_grace_months` | `3` | Meses calendario en past_due antes de pasar a suspended (#175) |
| `trial_days` | `90` | Días de prueba post-creación sin invoices |
| `payment_proof_disk` | `s3_documents` | Disco S3 para comprobantes de pago |
| `download_ttl` | `3600` | TTL de URL firmada del PDF |
| `pdf_driver` | `dompdf` | Motor PDF |

---

## Política de mora (#175 + #193)

```
Día 1-15:    factura `pending`, sin restricción
Día 16+:     factura → `overdue`; empresa pasa a `past_due` (banner en UI)
Mes 2 sin pago (configurable):  past_due continúa (banner persistente)
Mes 3+ sin pago: empresa pasa a `suspended` — modo solo-lectura, bot bloqueado (#193)
Pago aprobado:   companies:recalculate-statuses revierte a `active` en máx. 4h
```

Configurable con `BILLING_PAST_DUE_GRACE_MONTHS` (default `3`).

Endpoints como `/api/v1/billing/*`, `/auth/login`, `/companies/me` siguen disponibles en `suspended` para que el usuario suba comprobantes.

---

## Notas crédito

Las notas crédito son facturas con monto negativo que vinculan a una previa vía `voided_by_invoice_id` (self-FK). Cuando se emiten, la factura original pasa a `voided` y la nota crédito queda como registro de auditoría inmutable. **Nunca** se hace `UPDATE` para anular una factura (regla §13 CLAUDE.md: refunds y notas crédito como asiento nuevo).

**Conservación legal DIAN** (§13 CLAUDE.md): 5 años para personas naturales / 10 años para jurídicas. No se permite borrar invoices ni payment_receipts antes de plazo — soft-delete máximo, jamás `truncate` en pdn.

---

## Notas de seguridad

- El acceso a facturación está restringido a `billing.read` (típicamente `owner` y `admin`).
- Los PDFs se almacenan en `invoices.pdf_path` y se sirven con URL firmada de TTL corto.
- Las facturas `paid` y `voided` no pueden ser modificadas; el modelo lanza `LogicException` si se intenta mutar `amount`, `base_amount`, `tax_amount`, etc.
- `BILLING_PAST_DUE_GRACE_MONTHS=0` desactiva la gracia y suspende inmediatamente al vencer.
- Si `BILLING_EMIT_DIAN_FOR_INVOICES=true`, cada invoice generada dispara `EmitDianInvoiceJob` (ShouldBeUnique por `invoice_id`) que asocia el `electronic_document_id` con CUFE.

---

## Gate del módulo DIAN por plan (#facturación-dian)

Solo el Plan Plus puede operar el módulo DIAN (`billing_plans.features` incluye `'dian'`). El item de sidebar ("Documentos DIAN" / "Facturación DIAN") queda **siempre visible** por RBAC — el gate por plan pasa a nivel de página/acción, no de navegación: la empresa entra igual y ve un aviso "Opción no incluida en tu plan actual" sin datos ni controles operables.

**Backend** — dos capas:
1. `App\Http\Middleware\EnsurePlanFeature` (alias `plan.feature:<feature>`) envuelve TODO el grupo de rutas `dian/*` operativas y de config (resoluciones, provider-config, adquirentes, documentos) en `routes/api.php` — excepto `dian/fiscal-profile`, que es dato general de empresa editable sin importar el plan. Responde `403 {code: 'plan.feature_not_included'}`.
2. `DianDispatchService::emit()`/`retry()` repiten el check (`BillingService::companyHasFeature($nit, 'dian')`) porque `EmitDianDocumentJob` no pasa por middleware — lanza `PlanFeatureNotIncludedException`, capturada en `ElectronicDocumentController` (store/retry/creditNote/convertToFev) → mismo 403. El job no-opea (no quema reintentos) si la empresa bajó de plan entre el cierre de la orden y que el job corra.

`BillingService::companyHasFeature()` lee el plan **activo** (no el snapshot histórico) — un upgrade habilita DIAN de inmediato.

**Frontend** — `activeCompany.plan_features` viaja en el bootstrap (`BootstrapService::buildSessionContext`, sin fetch extra) y se lee con el hook `useHasPlanFeature('dian')`. Tres puntos gateados, los tres muestran el mismo aviso ("Opción no incluida en tu plan actual" / componente `PlanLockedBlock`) en vez de dejar operar:
- `/company/dian` y `/dian/documents` (páginas completas: sin plan, no hacen fetch y muestran solo el bloqueo).
- `DianOrderActions` (detalle de orden — oculta el botón "Emitir documento DIAN"/acciones, muestra mensaje inline).
- `TablePaymentSheet` (POS "Cerrar y cobrar" — oculta el toggle "Cliente solicita factura DIAN").
