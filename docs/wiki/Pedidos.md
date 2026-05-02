# Pedidos

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

Los pedidos (`orders`) son creados por dos canales:

1. **Bot externo** (WhatsApp) vía JWT de bot — el caso principal de producción.
2. **API interna** `POST /api/v1/orders` — para registro manual desde el panel.

La gestión interna es principalmente lectura, cambio de estado y métricas. El kanban del panel agrupa pedidos por estado.

---

## Estados

```
pending → in_kitchen → ready → in_delivery → completed
   ↓           ↓           ↓           ↓
abandoned   cancelled   cancelled   cancelled
                            ↓
                       successful (alias terminal)
```

| Estado | Descripción |
|--------|-------------|
| `pending` | Pedido recibido, sin confirmar |
| `in_kitchen` | En preparación |
| `ready` | Listo para entrega o pickup |
| `in_delivery` | En camino al cliente |
| `completed` | Entregado y cobrado |
| `successful` | Alias terminal (compatibilidad) |
| `abandoned` | Cliente no completó el flujo |
| `cancelled` | Cancelado por restaurante o cliente |

---

## Estructura de items (JSON)

Almacenado en `orders.items`:

```json
[
  {
    "id": "uuid-item",
    "name": "Bandeja paisa",
    "price": 32000,
    "quantity": 2,
    "category": "Platos principales",
    "notes": "Sin cebolla, frijoles aparte"
  },
  {
    "id": "uuid-item-2",
    "name": "Limonada de coco",
    "price": 8000,
    "quantity": 1,
    "category": "Bebidas",
    "notes": null
  }
]
```

---

## Tipos de orden

Campo `order_type` en `orders`:

| Valor | Descripción | Campos extra |
|-------|-------------|--------------|
| `dine_in` | Para mesa | `table_number` |
| `takeaway` | Para llevar | — |
| `delivery` | Domicilio | `delivery_address` |

---

## Endpoints

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| `GET` | `/api/v1/orders` | `orders.read,read` | Lista pedidos (filtros: status, date_from, date_to) |
| `POST` | `/api/v1/orders` | `orders.create,create` | Crea pedido manual |
| `PATCH` | `/api/v1/orders/{id}/status` | `orders.update,update` | Cambia estado |

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

Validaciones:
- Solo se permiten transiciones lineales hacia adelante o transiciones a estados terminales (`cancelled`, `abandoned`).
- Pasar a `in_delivery` requiere que el pedido tenga un `Delivery` asociado en estado `assigned`.

---

## Pedidos del bot (canal externo)

El bot crea pedidos vía su propio JWT (`BOT_JWT_SECRET`) sobre rutas `/api/external/*` o vía sesiones de carrito. Ver [WhatsApp Bot](WhatsApp-Bot.md).

---

## Índices de rendimiento

La tabla `orders` tiene índices compuestos para el dashboard:

- `(company_nit, status, ordered_at)` — kanban por estado
- `(company_nit, ordered_at)` — métricas por período

Configurados en `2026_05_01_210000_dashboard_performance`.

---

## Notas de seguridad

- Los pedidos están confinados al `active_company_nit`; no es posible listar/leer pedidos de otra empresa.
- El bot solo puede crear pedidos para la empresa de su JWT (validación cruzada en `ValidateBotJwt`).
- Las transiciones de estado quedan en `audit_logs` (acción `order.status_changed`).
