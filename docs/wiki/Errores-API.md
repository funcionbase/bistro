# Errores API

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Formato estándar de error

Todos los errores se devuelven como JSON con esta forma base:

```json
{
  "message": "Texto legible para el usuario.",
  "code": "OPCIONAL_CODIGO_DE_APP",
  "errors": {
    "campo": ["Mensaje de validación 1", "Mensaje 2"]
  }
}
```

- `message` siempre está presente.
- `code` aparece cuando el backend devuelve un identificador estable (útil para que el frontend tome decisiones).
- `errors` aparece solo en respuestas `422` de `FormRequest`.

---

## Códigos HTTP

| Código | Significado | Cuándo lo emite el backend |
|--------|-------------|---------------------------|
| `200` | OK | Operación exitosa con cuerpo |
| `201` | Created | Recurso creado |
| `204` | No Content | Operación exitosa sin cuerpo |
| `400` | Bad Request | Petición malformada (rara vez) |
| `401` | Unauthorized | JWT inválido, expirado, ausente, en blacklist o sesión revocada |
| `403` | Forbidden | RBAC no concede el permiso o membresía inactiva |
| `404` | Not Found | Recurso inexistente o fuera del scope de la empresa activa |
| `409` | Conflict | Estado incompatible (p. ej. duplicado, ítem ya existe) |
| `422` | Unprocessable Entity | Validación de `FormRequest` falló |
| `429` | Too Many Requests | Rate limit excedido |
| `500` | Server Error | Error no controlado |

---

## Códigos de aplicación (en `code`)

| Código | Status | Origen | Significado |
|--------|--------|--------|-------------|
| `USER_INACTIVE_IN_COMPANY` | 403 | `selectCompany`, `switchCompany`, `EnsureCompanyAccess` | El admin desactivó la membresía del usuario; el JWT activo se invalida |
| `COMPANY_NOT_MEMBER` | 403 | `switchCompany` | El usuario solicitó cambiar a una empresa de la que no es miembro |
| `COMPANY_INACTIVE` | 422 | `switchCompany` | La empresa solicitada está en estado distinto a `active` |
| `INVITATION_EXPIRED` | 422 | `InvitedEnrollmentController` | Token de invitación venció |
| `INVITATION_ALREADY_ACCEPTED` | 409 | `InvitedEnrollmentController` | El token ya fue consumido |
| `INVITATION_NOT_FOUND` | 404 | `InvitationController` | Token inexistente |
| `MENU_ALREADY_ACTIVE` | 422 | `MenuController::activate` | Otro menú ya está activo en la empresa (solo uno permitido) |
| `COUPON_EXHAUSTED` | 422 | `CouponValidationController` | El cupón superó `max_uses` |
| `COUPON_EXPIRED` | 422 | `CouponValidationController` | `expires_at` en el pasado |
| `COUPON_NOT_FIRST_ORDER` | 422 | `CartCouponController::apply` | Cupón restringido al primer pedido del cliente |
| `INVOICE_LOCKED` | 409 | `BillingController` | Facturas `paid` o `voided` no admiten cambios |
| `DELIVERY_ALREADY_ACTIVE` | 409 | `DeliveryController::store` | El pedido ya tiene un domicilio activo |

---

## Errores de validación (`422`)

Generados por `FormRequest`. Ejemplo:

```http
POST /api/v1/coupons HTTP/1.1
Content-Type: application/json

{ "code": "AB", "type": "percent" }
```

```http
HTTP/1.1 422 Unprocessable Entity
{
  "message": "El código debe tener al menos 3 caracteres.",
  "errors": {
    "code": ["El código debe tener al menos 3 caracteres."],
    "type": ["El tipo seleccionado es inválido."],
    "value": ["El valor es obligatorio."]
  }
}
```

---

## Errores de autenticación

```json
// 401 — JWT ausente o inválido
{ "message": "Sesión inválida o expirada." }
```

```json
// 401 — Sesión revocada por admin (frontend la detecta y redirige a /)
{ "message": "Tu sesión ha sido revocada por un administrador." }
```

```json
// 403 — RBAC niega permiso
{ "message": "No tienes permiso para realizar esta acción.", "required": "menu.create" }
```

---

## Comportamiento del frontend

`apiFetch` (`resources/js/lib/api.ts`) trata los errores así:

| Caso | Acción |
|------|--------|
| Header `X-Refresh-Token` presente | Llama `setToken(refreshedToken)` y continúa |
| `401` con mensaje que contiene "revoc" | Limpia token y `router.visit('/')` |
| `422` con `errors` | El consumidor mapea a su estado de errores de campo |
| Cualquier otro | El consumidor decide; el SPA usualmente muestra `message` en un toast |

---

## Notas

- Los errores **nunca** revelan información de otra empresa. Si un usuario consulta un recurso fuera de su `active_company_nit`, el backend devuelve `404` (no `403`) para no filtrar existencia.
- Los rate limits aplican por IP (OAuth) o por usuario (login). Cuando se exceden, devuelven `429` con `Retry-After`.
- Los errores `500` se registran en logs (`storage/logs/laravel.log`) con el trace completo. En producción, `APP_DEBUG=false` oculta el trace al cliente.
