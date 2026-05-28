# Cupones

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

Los cupones (`coupons`) son códigos canjeables que aplican un descuento a un pedido. Soportan:

- Dos tipos (`percentage`, `fixed_amount`).
- Restricciones por uso (`max_uses`), por monto mínimo, por ventana horaria y por días de la semana.
- Scope por sede (todas las sedes / sedes específicas).
- `auto_apply` para aplicarse sin pedir código al cliente.
- Validación pública (sin requerir contexto de empresa, solo JWT del bot/cliente).

---

## Tipos

| Tipo | Significado | Campo `value` |
|------|-------------|---------------|
| `percentage` | Descuento porcentual | Porcentaje (0–100, tope configurable, default 80) |
| `fixed_amount` | Descuento fijo en COP | Monto (decimal:2, tope configurable, default 100 000) |

---

## Modelo

```
coupons
├── id (uuid), company_nit, branch_id
├── scope (all | specific_branches)
├── valid_in_branches (jsonb, lista de branch_id cuando scope='specific_branches')
├── code (único por empresa)
├── type, value (decimal:2)
├── min_order_amount (decimal:2)
├── valid_from, valid_until (datetime)
├── valid_days (jsonb, 0=domingo … 6=sábado)
├── valid_hours_from, valid_hours_to (HH:MM)
├── max_uses, uses_count
├── first_order_only (bool)
├── is_active (bool)
├── is_single_use (bool, por client_phone)
├── locked_to_phone (string|null, restringe a un teléfono específico)
├── auto_apply (bool)
├── source (manual | campaign | system)
├── status: active | inactive | exhausted
├── created_by
├── deleted_at (SoftDeletes)

coupon_redemptions
├── coupon_id, order_id, client_phone
├── discount_amount (decimal:2)
├── redeemed_at
```

---

## Endpoints

Todos bajo `/api/v1` con `jwt + company.access + branch.access` salvo indicación.

### Gestión interna

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/coupons` | `coupons.read,read` |
| `POST` | `/coupons` | `coupons.create,create` |
| `GET` | `/coupons/{id}` | `coupons.read,read` |
| `PUT` | `/coupons/{id}` | `coupons.update,update` |
| `PATCH` | `/coupons/{id}/status` | `coupons.update,update` |
| `DELETE` | `/coupons/{id}` | `coupons.delete,delete` |
| `GET` | `/coupons/{id}/redemptions` | `coupons.read,read` |
| `POST` | `/exports/coupons/pdf` | `coupons.read,read` |

### Validación / aplicación por el OPERADOR (cajero en POS)

Usan JWT de usuario autenticado (#174 P2-3). No requieren `company.access` adicional porque viven dentro del grupo authenticated.

| Método | Ruta | Controller |
|--------|------|------------|
| `GET` | `/coupons/{code}/validate` | `CouponValidationController::validate` |
| `POST` | `/cart/apply-coupon` | `CartCouponController::apply` |
| `POST` | `/cart/active-auto-apply` | `CartCouponController::activeAutoApply` |

### Validación / aplicación por el COMENSAL

El flujo de cliente final (cart público) aplica cupones vía las rutas de carrito (`/api/v1/cart/{jwt}`) con el JWT de carrito (`CartJwtService`). Ver [WhatsApp Bot](WhatsApp-Bot.md) y la sección Cart en docs internos.

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
  "valid_from": "2026-05-01T00:00:00-05:00",
  "valid_until": "2026-12-31T23:59:59-05:00",
  "valid_days": [1,2,3,4,5],
  "valid_hours_from": "11:00",
  "valid_hours_to": "21:00",
  "min_order_amount": 30000,
  "first_order_only": true,
  "scope": "all",
  "auto_apply": false
}
```

```http
HTTP/1.1 201 Created
{
  "coupon": {
    "id": "uuid-88",
    "code": "BIENVENIDO10",
    "type": "percentage",
    "value": 10,
    "max_uses": 100,
    "uses_count": 0,
    "status": "active",
    "first_order_only": true,
    "valid_until": "2026-12-31T23:59:59-05:00"
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
- `422 COUPON_EXPIRED` — `valid_until` en el pasado o `valid_from` en el futuro.
- `422 COUPON_EXHAUSTED` — `uses_count >= max_uses` (status pasa a `exhausted`).
- `422 COUPON_OUT_OF_SCHEDULE` — día/hora actual no está en `valid_days` / `valid_hours_*`.
- `422 COUPON_MIN_AMOUNT` — subtotal < `min_order_amount`.
- `422 COUPON_BRANCH_NOT_ALLOWED` — sede activa no está en `valid_in_branches`.
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
  "coupon_id": "uuid-88",
  "discount_amount": 5000,
  "total": 45000
}
```

Errores específicos:
- `422 COUPON_NOT_FIRST_ORDER` — `first_order_only=true` y el `client_phone` ya tiene pedidos previos.
- `422 COUPON_LOCKED_TO_OTHER_PHONE` — `locked_to_phone` no coincide.

### Auto-apply activos

```http
POST /api/v1/cart/active-auto-apply HTTP/1.1
Content-Type: application/json

{ "subtotal": 50000, "client_phone": "+573001234567" }
```

Devuelve los cupones `auto_apply=true` aplicables al carrito actual, sin pedir código.

---

## Reglas de negocio

- Cupones con `uses_count > 0` **no pueden editarse** — sus condiciones quedan congeladas para preservar trazabilidad. El controlador responde 422 si se intenta.
- Se marcan automáticamente `exhausted` cuando `uses_count >= max_uses`.
- `first_order_only` consulta el historial de pedidos por `client_phone` (no por user). Configurable vía `coupons.validation.enable_first_order_check`.
- `is_single_use` impide que el mismo `client_phone` use el cupón más de una vez aunque queden usos en `max_uses`.
- `auto_apply` permite aplicarlo sin pedir código en el flujo de carrito (el cliente nunca lo escribe).
- `scope='specific_branches'` requiere que la sede activa esté en `valid_in_branches`.
- `SoftDeletes`: la eliminación es lógica. Eliminados devuelven 404 en validación pública.
- Una `coupon_redemption` es **inmutable** una vez creada.

---

## Configuración

`application/backend/config/coupons.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `code.min_length` | `4` | Mínimo de caracteres del código |
| `code.max_length` | `20` | Máximo de caracteres del código |
| `validation.max_percentage` | `80` | Tope de `value` cuando `type=percentage` |
| `validation.max_fixed_amount` | `100000` | Tope de `value` cuando `type=fixed_amount` (COP) |
| `validation.enable_first_order_check` | `true` | Habilita la consulta de `first_order_only` contra `orders` |

Overridables vía env (`COUPON_CODE_MIN_LENGTH`, `COUPON_CODE_MAX_LENGTH`, `COUPON_MAX_VALUE_PERCENTAGE`, `COUPON_MAX_FIXED_VALUE`, `COUPON_ENABLE_FIRST_ORDER_VALIDATION`).

---

## Impacto RBAC

- `coupons.read` — listar, ver detalle, ver redemptions.
- `coupons.create` — crear.
- `coupons.update` — editar metadatos / activar / desactivar / `PATCH status`.
- `coupons.delete` — eliminar (soft).
- Validar / aplicar desde POS (cajero): no requiere permiso `coupons.*`, basta JWT de usuario en sede activa.
- Cupones son recurso **per-sede** cuando `scope='specific_branches'`; el `BranchScope` global filtra automáticamente al listar.

---

## Notas de seguridad

- La validación pública no expone `max_uses` ni `uses_count` totales — solo confirma `valid=true|false` + datos mínimos del descuento.
- El backend siempre re-verifica las condiciones al canjear, sin confiar en el estado del frontend.
- Los cupones eliminados (`deleted_at` no nulo) devuelven `404` en validación pública.
- `redemptions` queda auditado con `actor_id`, `client_phone` y `discount_amount` para soportar reportes y auditoría DIAN.
