# DELIVERY_STATUSES — Fuente única de verdad

> **Antes de modificar estados o razones de entrega, lee este archivo.**
> **Después de modificar, actualiza la migración del CHECK constraint +
> `Delivery` model + `DeliveryService` + tipos TS + componentes de UI en
> el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `bistro/backend/database/migrations/2026_05_18_143903_create_delivery_status_logs_table.php:53-56` — CHECK constraint `delivery_status_logs.reason`
- [ ] `bistro/backend/database/migrations/2026_05_18_143902_add_status_change_reason_to_deliveries.php:32-36` — CHECK constraint `deliveries.status_change_reason`
- [ ] `bistro/backend/app/Services/DeliveryService.php:33-37` — constantes `REASON_*`
- [ ] `bistro/backend/app/Models/Delivery.php:69-77` — scopes `pending()`, `completed()`
- [ ] `bistro/backend/app/Models/DeliveryStatusLog.php:27` — PHPDoc `reason`
- [ ] `bistro/frontend/src/types/index.ts:644` — tipo `Delivery.status_change_reason`
- [ ] `bistro/frontend/src/components/deliveries/my-delivery-card.tsx:155-163` — `reasonLabel()` helper
- [ ] `bistro/frontend/src/components/deliveries/reject-reason-sheet.tsx` — sheet para `pedido_rechazado`

---

## Estados de `deliveries.status` (entidad principal)

Una `Delivery` representa la asignación de una orden a un domiciliario. Está acoplado pero no es lo mismo que `orders.status` (la orden puede pasar por `in_transit` y volver a `ready` si el delivery cae a `cancelled`).

| status | Categoría | Label UI | Terminal | Notas |
|---|---|---|---|---|
| `pending` | operativo | Pendiente | no | Asignado, en camino o esperando salida. Default al crearse. |
| `completed` | terminal | Entregado | sí | El domiciliario marcó entregado y el cliente lo recibió. |
| `cancelled` | terminal | Cancelado | sí | Reasignado a otro domiciliario, rechazado por el cliente, o error de usuario revertido. |

Fuente: `app/Models/Delivery.php:69-77` (scopes `pending()`, `completed()`).

### Reglas de transición

1. **`pending → completed`**: domiciliario marca entrega exitosa (`DeliveryStatusController::complete`). Backfilea `orders.status='completed'`.
2. **`pending → cancelled`**: 3 vías:
   - **Reasignación** (`DeliveryService::reassign`): la `Delivery` original pasa a `cancelled` con `reason='reassigned'`, se crea una nueva `pending` para el nuevo domiciliario.
   - **Rechazo del cliente** (`pedido_rechazado`): cuando el cliente no recibe la entrega; orden vuelve a `ready` o pasa a `cancelled` según contexto.
   - **Error revertido** (`error_usuario`): el domiciliario marcó completed por error; revierte el `Delivery` a `cancelled` y crea uno nuevo `pending`.
3. **`completed → cancelled`** (revert): solo dentro de una ventana corta (#119). Genera `DeliveryStatusLog` con `reason='error_usuario'` + nueva `Delivery` pendiente.
4. **`completed → pending`**: NO se permite directamente. La reversión siempre crea entidad nueva (trazabilidad DIAN).

---

## Razones canónicas de cambio de estado

Lista cerrada. Aplica a dos columnas:
- `deliveries.status_change_reason` (último cambio, valor presente en la fila viva)
- `delivery_status_logs.reason` (historia append-only de cada transición)

| reason | Cuándo | Aplica a `deliveries` | Aplica a `delivery_status_logs` |
|---|---|---|---|
| `error_usuario` | Domiciliario marcó `completed` por error y revirtió | ✅ sí | ✅ sí |
| `pedido_rechazado` | El cliente rechazó la entrega al recibirla | ✅ sí | ✅ sí |
| `reassigned` | La entrega se asignó a otro domiciliario | ❌ no (la `Delivery` original queda con `reason=NULL` o no aplica) | ✅ sí |

Fuente:
- `app/Services/DeliveryService.php:33-37` (constantes `REASON_ERROR_USUARIO`, `REASON_PEDIDO_RECHAZADO`, `REASON_REASSIGNED`).
- Migración `2026_05_18_143902_add_status_change_reason_to_deliveries.php:35` (CHECK constraint de `deliveries.status_change_reason` — solo `error_usuario|pedido_rechazado`).
- Migración `2026_05_18_143903_create_delivery_status_logs_table.php:55` (CHECK constraint de `delivery_status_logs.reason` — `error_usuario|pedido_rechazado|reassigned`).

### Por qué `reassigned` no está en `deliveries.status_change_reason`

`reassigned` aplica a la *transición lógica* "entrega A se reemplaza por entrega B". La `Delivery` A queda `cancelled` y su `status_change_reason` puede quedar NULL. La historia se ve completa en `delivery_status_logs` con dos filas: A `pending→cancelled reason=reassigned` y B `none→pending reason=reassigned`.

---

## Impacto RBAC

- **Permiso para crear/reasignar**: `deliveries.create` + `deliveries.update`.
- **Permiso del propio domiciliario**: `deliveries.self_assign` (modo courier — `bistro/backend/constants/COURIER_MODE.md`).
- **Quién marca `completed`**: solo el domiciliario asignado (`Delivery.user_id == jwt.sub`) o admin con `deliveries.update`.
- **Owner bypass**: `role.is_system=true` siempre permite.
- **Auditoría**: cada transición dispara `AuditService::log('delivery.status_changed', ...)` o `'delivery.reassigned'` con `from_status`, `to_status`, `reason`, `actor_id`, `actor_active_branch_id`.

---

## Cómo añadir un estado o razón nuevos

### Nuevo estado de `deliveries.status`

1. Decidir categoría (operativo / terminal).
2. Agregar al modelo `Delivery` (scope si aplica).
3. Actualizar `DeliveryService` (transiciones permitidas).
4. Actualizar tipo TS `DeliveryStatus`.
5. Actualizar componentes (`MyDeliveryCard`, etc.).
6. Documentar transiciones en este `.md`.

### Nueva razón

1. Migración para alterar el CHECK constraint de `delivery_status_logs.reason` (drop + add con todos los valores).
2. Si la razón también puede vivir en `deliveries.status_change_reason`, alterar el CHECK de esa columna también.
3. Constante en `DeliveryService::REASON_*`.
4. Tipo TS (union en `Delivery.status_change_reason`).
5. Etiqueta UI en `my-delivery-card.tsx::reasonLabel()`.
6. Documentar acá.

---

## Divergencias / deuda detectadas (al 2026-05-18)

- ✅ **Resuelto en este PR (#203)**: el catálogo de `delivery_status_logs.reason` (`error_usuario`, `pedido_rechazado`, `reassigned`) vivía solo en backend. El frontend solo consumía `error_usuario` y `pedido_rechazado` (vía `deliveries.status_change_reason`) pero **no exponía `reassigned`** ni labels canónicos. Fix: tipo TS `DeliveryReason` exportado, `deliveryReasons` en Inertia shared props, labels en es-CO compartidas entre `MyDeliveryCard` y futuros consumers.

---

## Referencias cruzadas

- `bistro/backend/app/Services/DeliveryService.php` — orquestador de transiciones.
- `bistro/backend/constants/COURIER_MODE.md` — permiso del domiciliario.
- `bistro/backend/constants/AUDIT_EVENTS.md` — eventos `delivery.*`.
- `docs/wiki/Domiciliarios.md` (#119) — manual narrativo.

> Última revisión: 2026-05-18 (#203)
