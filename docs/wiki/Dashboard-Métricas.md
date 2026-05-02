# Dashboard y Métricas

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

El dashboard muestra el estado operativo en tiempo real (con polling) y las métricas analíticas (con caché). Los reportes exportan datasets a PDF.

Capa frontend: `pages/dashboard.tsx` (Inertia con `Inertia::defer`) + `pages/metrics/index.tsx` (filtros y heatmaps). Capa backend: `MetricsController` + `MetricsService` con caché por tipo.

---

## Endpoints de métricas

Todos requieren `permission:reports.read,read`.

### KPIs y resumen

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/metrics/summary` | Resumen del período (revenue, pedidos, ticket promedio) |
| `GET` | `/api/v1/metrics/kpis/today` | KPIs del día actual |
| `GET` | `/api/v1/metrics/orders/active` | Pedidos activos por estado |

### Rankings y heatmaps

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/metrics/items/top` | Top platos vendidos del período |
| `GET` | `/api/v1/metrics/dishes/ranking` | Variante con más detalle |
| `GET` | `/api/v1/metrics/orders/heatmap` | Heatmap horario (24 horas) |
| `GET` | `/api/v1/metrics/orders/heatmap/weekly` | Heatmap semanal 7×24 |
| `GET` | `/api/v1/metrics/activity/heatmap` | Heatmap de actividad combinada |

### Carrito

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/metrics/carts/abandonment` | Tasa de abandono y revenue perdido |
| `GET` | `/api/v1/metrics/cart/abandonment` | Variante alterna |

---

## Filtros de período

Todos los endpoints aceptan `?period=`:

| Valor | Significado |
|-------|-------------|
| `today` | Día actual |
| `week` | Últimos 7 días |
| `month` | Mes en curso |
| `custom` | Requiere `?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` |

`PeriodResolver` valida los valores; uno inválido devuelve `422`.

---

## Ejemplos

### KPIs del día

```http
GET /api/v1/metrics/kpis/today HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "kpis": {
    "revenue": 1250000,
    "orders_count": 38,
    "average_ticket": 32894,
    "rating_average": 4.7
  }
}
```

### Heatmap semanal

```http
GET /api/v1/metrics/orders/heatmap/weekly?period=month HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "data": [
    { "day_of_week": 1, "hour": 12, "count": 42 },
    { "day_of_week": 1, "hour": 13, "count": 67 },
    ...
  ]
}
```

### Top platos

```http
GET /api/v1/metrics/items/top?period=week&limit=10 HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "items": [
    { "name": "Bandeja paisa", "quantity": 120, "revenue": 3840000 },
    { "name": "Limonada de coco", "quantity": 95, "revenue": 760000 }
  ]
}
```

---

## Estrategia de caché

Configurable en `config/metrics.php`:

| Métrica | TTL por defecto | Por qué |
|---------|----------------|---------|
| `active_orders` | 30 s | Tiempo casi-real |
| `summary` | 60 s | Acepta latencia mínima |
| `heatmap` | 600 s | Cambia poco hora a hora |
| `top_items` | 300 s | Idem |

Cualquier mutación en `orders` no invalida el caché automáticamente; el TTL corto es la política.

---

## Reportes y exportación PDF

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/reports/orders` | `reports.read,read` |
| `POST` | `/api/v1/reports/export` | `reports.read,read` |
| `GET` | `/api/v1/reports/download/{token}` | Token firmado |
| `POST` | `/api/v1/exports/orders/pdf` | `reports.read,read` |
| `POST` | `/api/v1/exports/metrics/pdf` | `reports.read,read` |
| `POST` | `/api/v1/exports/couriers/pdf` | `deliveries.read,read` |
| `POST` | `/api/v1/exports/coupons/pdf` | `coupons.read,read` |
| `POST` | `/api/v1/exports/billing/pdf` | `billing.read,read` |

Notas:
- Exports >500 registros muestran aviso de límite.
- Los tokens de descarga (`/reports/download/{token}`) son firmados con TTL corto.
- Motor PDF: `dompdf` (configurable en `config/pdf.php` y `config/dompdf.php`).

---

## Comportamiento del frontend

`pages/dashboard.tsx`:

- Usa `Inertia::defer` para los props `summary`, `heatmap`, `abandonment`, `deliveries` con skeletons.
- Filtro de período via `usePeriodFilter` que llama `router.reload({ only: ['summary','heatmap','abandonment','deliveries'] })`.
- `deliveries` requiere `deliveries.read`; si falta el permiso, el panel se omite silenciosamente.

`pages/metrics/index.tsx`:

- Filtros de período + custom dates.
- Polling 30 s para `active_orders`.
- Heatmaps renderizados con `dashboard/heatmap-chart.tsx`.

---

## Notas de seguridad

- Las métricas se calculan **siempre** con scope a `active_company_nit`; nunca cruzan empresas.
- El JWT firmado de descarga (`/reports/download/{token}`) no requiere autenticación adicional, pero expira rápido (configurable).
- Los exports PDF se generan en cola cuando el dataset es grande (futuro mejora; hoy son síncronos con timeout protegido).
