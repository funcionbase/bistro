# Cupones

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

Los cupones (`coupons`) son códigos canjeables que aplican un descuento a un pedido. Soportan dos tipos, restricciones por uso y validación pública (sin requerir contexto de empresa) para que el bot los valide antes de armar el carrito.

---

## Tipos

| Tipo | Significado | Campo `value` |
|------|-------------|---------------|
| `percentage` | Descuento porcentual (0–100) | Porcentaje (entero) |
| `fixed_amount` | Descuento fijo en COP | Monto en COP |

---

## Modelo

```
coupons
├── id, company_nit
├── code (único por empresa)
├── type, value
├── max_uses, uses_count
├── expires_at
├── first_order_only (bool)
├── status: 'active' | 'inactive' | 'exhausted'
├── deleted_at (SoftDeletes)
```

```
coupon_redemptions
├── coupon_id, order_id, client_phone
├── discount_amount
├── redeemed_at
```

---

## Endpoints

### Gestión interna

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/coupons` | `coupons.read,read` |
| `POST` | `/api/v1/coupons` | `coupons.create,create` |
| `GET` | `/api/v1/coupons/{id}` | `coupons.read,read` |
| `PUT` | `/api/v1/coupons/{id}` | `coupons.update,update` |
| `PATCH` | `/api/v1/coupons/{id}/status` | `coupons.update,update` |
| `DELETE` | `/api/v1/coupons/{id}` | `coupons.delete,delete` |
| `GET` | `/api/v1/coupons/{id}/redemptions` | `coupons.read,read` |

### Validación pública (sin `company.access`)

| Método | Ruta | Auth |
|--------|------|------|
| `GET` | `/api/v1/coupons/{code}/validate` | `jwt` |
| `POST` | `/api/v1/cart/apply-coupon` | `jwt` |

Estos endpoints usan el JWT del bot/cliente (con `company_nit` embebido) y no requieren membresía de usuario.

---

## Ejemplos

### Crear cupón

```http
POST /api/v1/coupons HTTP/1.1
Content-Type: application/json

{
  "code": "BIENVENIDO10",
  "type": "percentage",
  "value": 10,
  "max_uses": 100,
  "expires_at": "2026-12-31",
  "first_order_only": true
}
```

```http
HTTP/1.1 201 Created
{
  "coupon": {
    "id": 88,
    "code": "BIENVENIDO10",
    "type": "percentage",
    "value": 10,
    "max_uses": 100,
    "uses_count": 0,
    "status": "active",
    "first_order_only": true,
    "expires_at": "2026-12-31"
  }
}
```

### Validar cupón

```http
GET /api/v1/coupons/BIENVENIDO10/validate HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "valid": true,
  "coupon": { "code": "BIENVENIDO10", "type": "percentage", "value": 10 }
}
```

Errores posibles:
- `422 COUPON_EXPIRED` — `expires_at` en el pasado.
- `422 COUPON_EXHAUSTED` — `uses_count >= max_uses`.
- `404` — código no encontrado.

### Aplicar al carrito

```http
POST /api/v1/cart/apply-coupon HTTP/1.1
Content-Type: application/json

{
  "code": "BIENVENIDO10",
  "subtotal": 50000,
  "client_phone": "+573001234567"
}
```

```http
HTTP/1.1 200 OK
{
  "coupon_id": 88,
  "discount_amount": 5000,
  "total": 45000
}
```

Errores específicos:
- `422 COUPON_NOT_FIRST_ORDER` — `first_order_only=true` y el `client_phone` ya tiene pedidos previos.

---

## Reglas de negocio

- Los cupones con `uses_count > 0` **no pueden ser editados** — sus condiciones quedan congeladas para preservar la trazabilidad.
- Los cupones se marcan automáticamente `exhausted` cuando `uses_count >= max_uses`.
- `first_order_only` consulta el historial de pedidos por `client_phone` (no por user).
- `SoftDeletes`: la eliminación es lógica.
- Una redemption es **inmutable** una vez creada.

---

## Configuración

`config/coupons.php`:

| Clave | Descripción |
|-------|-------------|
| `code_pattern` | Patrón regex aceptado (default `[A-Z0-9]{4,15}`) |
| `default_max_uses` | Default si el creador no especifica |
| `default_expiry_days` | Días por defecto desde creación |

---

## Notas de seguridad

- La validación pública no expone `max_uses` ni `uses_count` totales — solo confirma `valid=true|false`.
- El backend siempre re-verifica las condiciones al canjear, sin confiar en el estado del frontend.
- Los cupones eliminados (`deleted_at` no nulo) devuelven `404` en validación pública.
