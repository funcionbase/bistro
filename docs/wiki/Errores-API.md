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
| `USER_INACTIVE_IN_COMPANY` | 403 | `AuthController::selectCompany`, `switchCompany`, `EnsureCompanyAccess` | El admin desactivó la membresía del usuario; el JWT activo se invalida |
| `COMPANY_NOT_MEMBER` | 403 | `AuthController::switchCompany` | El usuario solicitó cambiar a una empresa de la que no es miembro |
| `COMPANY_INACTIVE` | 422 | `AuthController::switchCompany` | La empresa solicitada está en estado distinto a `active` |
| `NO_ACTIVE_COMPANY` | 422 | `AuthController::selectBranch` | El usuario aún no eligió empresa activa |
| `BRANCH_NOT_FOUND` | 404 | `AuthController::selectBranch`, `EnsureBranchAccess`, `AllowConsolidatedBranches`, `ChatController` | Sede inexistente bajo el scope actual |
| `BRANCH_FORBIDDEN` | 403 | `AuthController::selectBranch`, `EnsureBranchAccess` | El rol no tiene acceso a la sede solicitada |
| `BRANCH_COMPANY_MISMATCH` | 403 | `EnsureBranchAccess` | La sede pertenece a otra empresa |
| `BRANCH_ARCHIVED` | 409 | `EnsureBranchAccess` | Sede archivada — operación bloqueada |
| `BRANCH_NOT_ACCESSIBLE` | 403 | `ChatController` | El actor no puede operar sobre la sede destino de un chat |
| `BRANCH_SWITCH_BLOCKED_CASH_OPEN` | 409 | `AuthController::selectBranch` | No se puede cambiar de sede mientras una caja propia está abierta |
| `CONSOLIDATED_FORBIDDEN` | 403 | `AllowConsolidatedBranches` | El permiso `metrics.view_all_branches` no está asignado |
| `BRANCH_FILTER_FORBIDDEN` | 403 | `AllowConsolidatedBranches` | El parámetro `?branch=` no está permitido en el contexto actual |
| `BUSINESS_CAPABILITY_DENIED` | 403 | `EnsureBusinessCapability` | La capability requerida no está habilitada para el `business_type` de la sede |
| `INVITATION_EXPIRED` | 422 | `InvitedEnrollmentController` | Token de invitación venció |
| `INVITATION_ALREADY_ACCEPTED` | 409 | `InvitedEnrollmentController` | El token ya fue consumido |
| `INVITATION_NOT_FOUND` | 404 | `InvitationController` | Token inexistente |
| `MENU_ALREADY_ACTIVE` | 422 | `MenuController::activate` | Otro menú ya está activo en la empresa (solo uno permitido) |
| `COUPON_EXHAUSTED` | 422 | `CouponValidationController` | El cupón superó `max_uses` |
| `COUPON_EXPIRED` | 422 | `CouponValidationController` | `expires_at` en el pasado |
| `COUPON_NOT_FIRST_ORDER` | 422 | `CartCouponController::apply` | Cupón restringido al primer pedido del cliente |
| `INVOICE_LOCKED` | 409 | `BillingController` | Facturas `paid` o `voided` no admiten cambios |
| `DELIVERY_ALREADY_ACTIVE` | 409 | `DeliveryController::store` | El pedido ya tiene un domicilio activo |
| `DELIVERY_NOT_OWNED` | 403 | `DeliveryController` | El repartidor no es dueño de la entrega que intenta operar |
| `ORDER_OTHER_BRANCH` | 409 | `DeliveryController` | La orden pertenece a otra sede activa |
| `ORDER_NOT_FOUND` | 404 | `DeliveryService::selfAssign` | Orden inexistente o fuera de scope al auto-asignar |
| `ORDER_ALREADY_TAKEN` | 409 | `DeliveryService::selfAssign` | Otro repartidor ganó el race del self-assign |
| `DELIVERY_INVALID_STATE` | 409 | `DeliveryService` | Transición de estado de entrega no permitida |
| `DELIVERY_NOT_COMPLETED` | 422 | `DeliveryService::createReceipt` | No se puede emitir comprobante si la entrega no está completada |
| `DELIVERY_HAS_RECEIPT` | 409 | `DeliveryService` | La entrega ya tiene un `payment_receipt` ligado |
| `CHAT_REASSIGN_FORBIDDEN` | 403 | `ChatController` | El actor no puede reasignar el chat al destinatario indicado |
| `DIAN_BLOB_NOT_AVAILABLE` | 404 | `Dian\ElectronicDocumentController` | El XML/PDF del documento DIAN aún no está disponible (en cola o sin generar) |
| `DIAN_CREDIT_NOTE_ALREADY_EXISTS` | 409 | `Dian\ElectronicDocumentController` | Ya existe una nota crédito emitida para el documento origen |
| `TRANSFER_CROSS_BRANCH_USE_DEDICATED_ENDPOINT` | 422 | `InventoryTransferController` | Los traslados entre sedes deben pasar por el endpoint específico (no el genérico) |
| `LAST_ACTIVE_BRANCH` | 409 | `BranchController::archive` | No se puede archivar la última sede activa de la empresa |
| `LAST_ACTIVE_WAREHOUSE` | 409 | `WarehouseController::archive` | No se puede archivar la última bodega activa de la sede |
| `WAREHOUSE_HAS_STOCK` | 409 | `WarehouseController::archive` | La bodega aún tiene stock — vaciarla antes de archivar |
| `USER_NOT_COMPANY_MEMBER` | 404 | `BranchController::attachUser` | El usuario destino no pertenece a la empresa actual |
| `SOURCE_MENU_NOT_FOUND` | 404 | `BranchController::cloneMenu` | El menú origen no existe o está fuera de scope |
| `BUSINESS_TYPE_UNCHANGED` | 422 | `BranchController::changeBusinessType` | El `business_type` solicitado coincide con el actual |

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

`apiFetch` (`bistro/frontend/src/lib/api.ts`) trata los errores así:

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
- El handler global vive en `bistro/backend/bootstrap/app.php` (closure `withExceptions`): cualquier `Throwable` no manejado en rutas que esperan JSON o que matchean `api/*` se serializa como `{"message": "..."}` con mensajes neutros por status (401/403/404/405/419/422/429/5xx). `ValidationException` y `AuthenticationException` siguen pasando al handler nativo para conservar `errors[]` y el redirect a `/auth`. Los códigos de aplicación (`code`) los emiten los controllers y middlewares enumerados arriba; el handler genérico **no** los inyecta.
