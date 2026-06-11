# Repartidores y Domicilios

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma
> Fuente única: `bistro/backend/constants/DELIVERY_STATUSES.md`

---

## Visión general

Los domicilios (`deliveries`) representan la asignación física de un pedido a un repartidor. Reglas:

- Un pedido tiene **un domicilio activo (`pending`)** a la vez.
- Un domicilio se asigna a un usuario con permiso `deliveries.*` (un repartidor o un mesero que también entrega).
- Soporta **reasignación** con motivo cuando el repartidor original no puede completarlo: la `Delivery` original pasa a `cancelled` y se crea una nueva `pending` apuntando a la anterior con `previous_delivery_id`.
- **Modo courier** (`deliveries.self_assign`): el domiciliario se auto-asigna pedidos en `ready` desde su pantalla (ver `bistro/backend/constants/COURIER_MODE.md`).
- `SoftDeletes`: los domicilios eliminados se conservan para auditoría.

---

## Estados

Estados de `deliveries.status` (lista cerrada):

| status | Categoría | Label | Notas |
|---|---|---|---|
| `pending` | operativo | Pendiente | Default al crearse. Cubre asignado, en camino, esperando salida. |
| `completed` | terminal | Entregado | El domiciliario marcó entrega exitosa. |
| `cancelled` | terminal | Cancelado | Reasignado, rechazado por el cliente o error de usuario revertido. |

> El catálogo NO distingue `picked_up` / `in_route` como estados de BD — el flujo operativo de pickup/route se modela con timestamps + UI; el estado contable son los 3 anteriores.

### Reglas de transición

1. `pending → completed`: el domiciliario asignado (o admin con `deliveries.update`) marca entrega vía `PATCH /deliveries/{id}/complete`. Backfilea `orders.status='completed'`.
2. `pending → cancelled`: tres vías
   - **Reasignación** (`reassigned`): `Delivery` original → `cancelled`, se crea nueva `pending` para el nuevo repartidor.
   - **Rechazo del cliente** (`pedido_rechazado`): el cliente no recibe la entrega; la orden vuelve a `ready` o pasa a `cancelled` según contexto.
   - **Error revertido** (`error_usuario`): se marcó `completed` por error y se revierte.
3. `completed → cancelled` (revert): solo dentro de una ventana corta (#119), genera `DeliveryStatusLog` con `reason='error_usuario'` + nueva `Delivery` pendiente.
4. `completed → pending` NO se permite directamente — la reversión siempre crea entidad nueva (trazabilidad DIAN).

`duration_minutes` se calcula automáticamente al completar (`diffInMinutes(assigned_at, delivered_at)`).

---

## Razones canónicas

Lista cerrada (validada por CHECK constraint en BD y constantes `DeliveryService::REASON_*`):

| reason | Cuándo | `deliveries.status_change_reason` | `delivery_status_logs.reason` |
|---|---|---|---|
| `error_usuario` | Marcó completed por error y revirtió | ✅ | ✅ |
| `pedido_rechazado` | Cliente rechazó la entrega | ✅ | ✅ |
| `reassigned` | Entrega se reasignó a otro repartidor | ❌ (queda NULL) | ✅ |

Lectura: `GET /api/v1/deliveries/reassign-reasons`. Razones de reasignación legacy (`client_request`, `not_available`, `route_change`, `other`) viven en `config/delivery.php`.

---

## Endpoints

Todos bajo `/api/v1` con `jwt + company.access + branch.access`.

### Lectura

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/deliveries` | `deliveries.read,read` |
| `GET` | `/deliveries/{id}` | `deliveries.read,read` |
| `GET` | `/deliveries/mine` | `deliveries.read,read` (cualquier autenticado lo invoca para sus propios) |
| `GET` | `/deliveries/couriers` | `deliveries.read,read` |
| `GET` | `/deliveries/metrics` | `deliveries.read,read` |
| `GET` | `/deliveries/reassign-reasons` | `deliveries.read,read` |
| `GET` | `/deliveries/available` | `deliveries.self_assign,read` (modo courier) |
| `GET` | `/orders/{orderId}/available-deliverers` | `deliveries.read,read` |

### Escritura

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/deliveries` | `deliveries.create,create` |
| `POST` | `/orders/{orderId}/assign-courier` | `deliveries.create,create` |
| `POST` | `/deliveries/orders/{orderId}/self-assign` | `deliveries.self_assign,read` |
| `POST` | `/deliveries/{id}/reassign` | `deliveries.update,update` |
| `PATCH` | `/deliveries/{id}/complete` | `deliveries.update,update` |
| `PUT` | `/deliveries/{id}/revert` | `deliveries.update,update` |
| `PUT` | `/deliveries/{id}/reject` | `deliveries.update,update` |
| `DELETE` | `/deliveries/{id}` | `deliveries.delete,delete` |

### Export

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/exports/deliveries/pdf` | `deliveries.read,read` |

---

## Ejemplos

### Asignar repartidor a un pedido

```http
POST /api/v1/orders/1042/assign-courier HTTP/1.1
Content-Type: application/json

{ "user_id": 7 }
```

```http
HTTP/1.1 201 Created
{
  "delivery": {
    "id": "uuid-305",
    "order_id": 1042,
    "user_id": 7,
    "status": "pending",
    "assigned_at": "2026-05-02T14:45:00-05:00"
  }
}
```

Si el pedido ya tiene domicilio activo: `409 DELIVERY_ALREADY_ACTIVE`.

### Auto-asignación (modo courier)

```http
POST /api/v1/deliveries/orders/1042/self-assign HTTP/1.1
```

El domiciliario con permiso `deliveries.self_assign` toma una orden en `ready` sin intervención del cajero/mesero.

### Reasignar

```http
POST /api/v1/deliveries/305/reassign HTTP/1.1
Content-Type: application/json

{
  "user_id": 9,
  "reason": "not_available"
}
```

Comportamiento:
- Cancela el domicilio anterior (`status=cancelled`, `delivery_status_logs.reason='reassigned'`).
- Crea uno nuevo `pending` con `previous_delivery_id` apuntando al anterior.
- Registra `audit_logs` con la razón.

### Completar

```http
PATCH /api/v1/deliveries/305/complete HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "delivery": {
    "id": "uuid-305",
    "status": "completed",
    "delivered_at": "2026-05-02T15:25:00-05:00",
    "duration_minutes": 40
  }
}
```

Backfilea `orders.status='completed'` (terminal_success → cuenta como ingreso).

### Revertir entrega errónea

```http
PUT /api/v1/deliveries/305/revert HTTP/1.1
Content-Type: application/json

{ "reason": "error_usuario" }
```

Solo dentro de la ventana de reverso (#119). Pasa la `Delivery` a `cancelled` y crea una nueva `pending`.

### Rechazar entrega

```http
PUT /api/v1/deliveries/305/reject HTTP/1.1
Content-Type: application/json

{ "reason": "pedido_rechazado" }
```

---

## Métricas de repartidores

```http
GET /api/v1/deliveries/metrics?period=week HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "period": "week",
  "metrics": [
    {
      "user_id": 7,
      "user_name": "Carlos M.",
      "total_deliveries": 42,
      "completed": 40,
      "cancelled": 2,
      "avg_duration_minutes": 28.5
    }
  ]
}
```

Períodos válidos: `today`, `week`, `month`.

---

## Configuración

`bistro/backend/config/delivery.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `notify_on_assignment` | `true` | Avisar al cliente vía WhatsApp al asignar |
| `notify_on_completion` | `true` | Avisar al cliente al completar |
| `share_courier_phone` | `true` | Compartir teléfono del repartidor con el cliente |
| `max_active_per_courier` | `3` | Tope de pedidos activos simultáneos por repartidor |
| `vehicle_type` | `bike` | Vehículo por defecto |
| `reassign_reasons` | array | Razones legacy: `client_request`, `not_available`, `route_change`, `other` |

Razones canónicas de cambio de estado (lista cerrada validada en BD): `error_usuario`, `pedido_rechazado`, `reassigned` (ver §Razones canónicas).

---

## Impacto RBAC

- `deliveries.read` — listar/ver.
- `deliveries.create` — crear y asignar.
- `deliveries.update` — reasignar, completar, revertir, rechazar.
- `deliveries.delete` — borrar (soft).
- `deliveries.self_assign` — modo courier (auto-asignación). Cuando es el único permiso → courier-only nav.
- Auditoría: cada transición dispara `delivery.status_changed` o `delivery.reassigned` con `from_status`, `to_status`, `reason`, `actor_id`, `actor_active_branch_id`.

---

## Notas de seguridad

- Los repartidores solo ven sus propios domicilios vía `GET /deliveries/mine` (el endpoint resuelve `user_id` desde el JWT).
- La reasignación queda auditada con razón obligatoria.
- `SoftDeletes`: nunca borrar físicamente; permite reportes históricos.
- CHECK constraints en BD garantizan que `status_change_reason` y `delivery_status_logs.reason` solo aceptan valores canónicos.
