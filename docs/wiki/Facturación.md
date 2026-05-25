# Facturación

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

flexyflow factura mensualmente la suscripción de cada empresa al plan vigente. El módulo soporta:

- Suscripciones con planes (`free`/`pro`/`enterprise`).
- Generación automática de facturas mensuales (cron).
- Marcado de mora con días de gracia.
- Descuentos temporales.
- PDFs descargables de facturas.

---

## Modelo

| Tabla | Campos clave |
|-------|--------------|
| `billing_plans` | `id`, `name`, `price`, `interval` (`monthly`), `features` (JSONB) |
| `subscriptions` | `company_nit`, `billing_plan_id`, `status` (`active`/`paused`/`cancelled`), `started_at`, `ends_at` |
| `subscription_discounts` | `subscription_id`, `percentage`, `expires_at`, `reason` |
| `invoices` | `id`, `company_nit`, `subscription_id`, `period_from`, `period_to`, `amount_due`, `paid_amount`, `status` (`draft`/`pending`/`paid`/`overdue`/`voided`), `pdf_path`, `voided_by_invoice_id` |
| `invoice_lines` | `invoice_id`, `description`, `quantity`, `unit_price`, `total` |
| `invoice_payments` | `invoice_id`, `amount`, `paid_at`, `method`, `reference` |

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

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/billing/subscription` | `billing.read,read` |
| `GET` | `/api/v1/billing/invoices` | `billing.read,read` |
| `GET` | `/api/v1/billing/invoices/{id}` | `billing.read,read` |
| `GET` | `/api/v1/billing/invoices/{id}/download` | `billing.read,read` |

### PDF público (URL firmada)

| Método | Ruta | Auth |
|--------|------|------|
| `GET` | `/api/v1/billing/invoices/{id}/pdf` | URL firmada (`signed`) |

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
    "id": 14,
    "plan": { "id": 2, "name": "Pro", "price": 89000, "interval": "monthly" },
    "status": "active",
    "started_at": "2025-11-01",
    "current_period_start": "2026-04-20",
    "current_period_end": "2026-05-20",
    "discount": null
  },
  "overdue_total": 0,
  "earliest_overdue_date": null
}
```

### Lista paginada de facturas

```http
GET /api/v1/billing/invoices?page=1 HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "data": [
    {
      "id": 412,
      "period_from": "2026-04-20",
      "period_to": "2026-05-20",
      "amount_due": 89000,
      "paid_amount": 89000,
      "status": "paid",
      "issued_at": "2026-04-20T03:00:00-05:00",
      "paid_at": "2026-04-21T10:23:00-05:00"
    }
  ],
  "current_page": 1,
  "last_page": 6,
  "total": 60
}
```

---

## Comandos programados

Definidos en `routes/console.php` o `bootstrap/app.php`:

| Comando Artisan | Cron | Descripción |
|------------------|------|-------------|
| `billing:generate-monthly-invoices` | `0 3 20 * *` | Genera facturas el día 20 a las 03:00 |
| `billing:mark-overdue-invoices` | `0 3 16 * *` | Marca como `overdue` el día 16 a las 03:00 |
| `billing:expire-discounts` | `0 4 1 * *` | Expira descuentos el día 1 a las 04:00 |

Las fechas son configurables en `.env` (ver [Variables de Entorno](Variables-de-Entorno.md)).

---

## Configuración

`config/billing.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `currency` | `COP` | Moneda |
| `grace_months` | `2` | Meses de gracia antes de suspender |
| `due_day` | `15` | Día del mes de vencimiento |
| `generate_day` | `20` | Día de generación |
| `overdue_day` | `16` | Día para marcar vencidas |
| `pdf_driver` | `dompdf` | Motor PDF |

---

## Política de mora

```
Día 1-15:    factura `pending`, sin restricción
Día 16:      pasa a `overdue` si no se pagó
Día 16-N:    empresa pasa a `mora` (banner en UI)
Mes 2 sin pago:  empresa pasa a `delinquent`
Mes 3+ sin pago: empresa pasa a `suspended` (solo lectura)
```

Configurable con `BILLING_GRACE_MONTHS`.

---

## Notas crédito

Las notas crédito (`invoice_type = credit-note`) son facturas con monto negativo que vinculan a una previa vía `voided_by_invoice_id`. Cuando se emiten, la factura original pasa a `voided` y la nota crédito queda como registro de auditoría.

---

## Notas de seguridad

- El acceso a facturación está restringido a `billing.read` (típicamente `owner` y `admin`).
- Los PDFs se almacenan en `invoices.pdf_path` y se sirven con URL firmada de TTL corto.
- Las facturas `paid` y `voided` no pueden ser modificadas; intentar editar devuelve `409 INVOICE_LOCKED`.
- `BILLING_GRACE_MONTHS=0` desactiva la gracia y suspende inmediatamente al vencer.
