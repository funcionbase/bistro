# Repartidores y Domicilios

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

Los domicilios (`deliveries`) representan la entrega física de un pedido a un cliente. Reglas:

- Un pedido tiene **un domicilio activo** a la vez.
- Un domicilio se asigna a un usuario con permiso `deliveries` (un repartidor o un mesero que también entrega).
- Soporta **reasignación** con motivo cuando el repartidor original no puede completarlo.
- `SoftDeletes`: los domicilios eliminados se conservan para auditoría.

---

## Estados

```
assigned → picked_up → in_route → completed
                                        │
                                  cancelled (en cualquier punto previo)
```

`duration_minutes` se calcula automáticamente al completar.

---

## Endpoints

### Lectura

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/deliveries` | `deliveries.read,read` |
| `GET` | `/api/v1/deliveries/{id}` | `deliveries.read,read` |
| `GET` | `/api/v1/deliveries/couriers` | `deliveries.read,read` |
| `GET` | `/api/v1/deliveries/metrics` | `deliveries.read,read` |
| `GET` | `/api/v1/deliveries/reassign-reasons` | `deliveries.read,read` |
| `GET` | `/api/v1/orders/{orderId}/available-deliverers` | `deliveries.read,read` |

### Escritura

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/api/v1/deliveries` | `deliveries.create,create` |
| `POST` | `/api/v1/orders/{orderId}/assign-courier` | `deliveries.create,create` |
| `POST` | `/api/v1/deliveries/{id}/reassign` | `deliveries.update,update` |
| `PATCH` | `/api/v1/deliveries/{id}/complete` | `deliveries.update,update` |
| `DELETE` | `/api/v1/deliveries/{id}` | `deliveries.delete,delete` |

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
    "id": 305,
    "order_id": 1042,
    "user_id": 7,
    "status": "assigned",
    "assigned_at": "2026-05-02T14:45:00-05:00"
  }
}
```

Si el pedido ya tiene domicilio activo: `409 DELIVERY_ALREADY_ACTIVE`.

### Reasignar

```http
POST /api/v1/deliveries/305/reassign HTTP/1.1
Content-Type: application/json

{
  "user_id": 9,
  "reason": "vehicle_breakdown"
}
```

Comportamiento:
- Cancela el domicilio anterior (status `cancelled`).
- Crea uno nuevo (status `assigned`).
- Registra `audit_logs` con la razón.

Razones disponibles vía `GET /api/v1/deliveries/reassign-reasons`. Configurables en `config/delivery.php`.

### Completar

```http
PATCH /api/v1/deliveries/305/complete HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "delivery": {
    "id": 305,
    "status": "completed",
    "completed_at": "2026-05-02T15:25:00-05:00",
    "duration_minutes": 40
  }
}
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

`config/delivery.php`:

| Clave | Descripción |
|-------|-------------|
| `reassign_reasons` | Lista cerrada de razones aceptadas |
| `default_status` | Estado inicial al crear (default `assigned`) |
| `auto_complete_minutes` | Minutos tras `picked_up` para autocompletar (opcional) |

---

## Notas de seguridad

- Los repartidores solo ven sus propios domicilios (lo gestiona el frontend con `?user_id=mio`).
- La reasignación queda auditada con razón obligatoria.
- `SoftDeletes`: nunca borrar físicamente; permite reportes históricos.
