# Plan — Bugs de estado entre Cocina (KDS) y Tablero

> Estado: plan aprobado 2026-07-24. La implementación es un paso posterior que requiere su propia confirmación (`.claude/workflow.md` §1).

## Contexto

El KDS opera sobre `order_items.status` (`approved → in_kitchen → ready → served`) y el Tablero (`/orders/board`) sobre `orders.status` (`pending → in_kitchen → ready → in_transit → completed`). El backend ya sincroniza en ambos sentidos: `KdsTicketService::maybePromoteOrderStatus` (item → orden) y `OrderController::syncItemsToOrderStatus` (orden → items).

Regla de producto confirmada: los estados del KDS son solo de preparación/entrega y deben reflejarse únicamente en las columnas "En cocina" o "Para entrega" del Tablero; el Tablero maneja el ciclo general de la orden y conserva su override (drag que empuja items). "Pendiente" con tickets "Por entrar" es correcto.

Hay 3 caminos que rompen esa coherencia (verificados por lectura directa del código):

1. **Cancelar un plato no re-evalúa la orden.** `TableWaiterService::rejectItem` (:215), `cancelItemInKitchen` (:270) y `resolveCancellationRequest` (:365) cancelan el item y recalculan totales pero nunca llaman la promoción. Orden con plato A `ready` + plato B `in_kitchen`: cancelar B deja la orden clavada en "En cocina" mientras el KDS muestra todo "Listo".
2. **Agregar platos a una orden "Para entrega" no la regresa a cocina.** `OrderController::appendItems` (:772-937) solo bloquea terminales: una orden `ready` recibe items nuevos `approved` (visibles en KDS) pero sigue en "Para entrega" — la columna miente. Decisión confirmada: regresión automática `ready → in_kitchen`, auditada.
3. **`DeliveryService::rejectDelivery` (:315) cancela la orden sin cerrar sus items.** `OrderController::cancel` sí lo hace (:1187-1194); acá los `order_items` quedan `approved/in_kitchen/ready` huérfanos (invisibles en KDS por el filtro de orden terminal, pero datos sucios para métricas/SLA/consumable).

## Cambios

### Fix 1 — Re-evaluar la orden al cancelar items

**Archivos**: `backend/app/Services/KdsTicketService.php`, `backend/app/Services/TableWaiterService.php`.

- Hacer público `KdsTicketService::maybePromoteOrderStatus` (hoy privado, :207). Añadir guard: si la orden no tiene NINGÚN item consumible restante, no promover (hoy un set vacío promovería a `ready`).
- Inyectar `KdsTicketService` en `TableWaiterService` y llamar la promoción tras el commit de la transacción de cancelación (mismo patrón que `markInKitchen`/`markReady`: fuera de la txn) en `rejectItem`, `cancelItemInKitchen` y `resolveCancellationRequest` (rama approved).
- El SMS "ready" que dispara la promoción es correcto (la orden quedó realmente lista) e idempotente (`order_sms_notifications` con `insertOrIgnore`).

### Fix 2 — Regresión `ready → in_kitchen` al agregar platos

**Archivo**: `backend/app/Http/Controllers/Api/OrderController.php` (`appendItems`), posiblemente `backend/app/Services/TableWaiterService.php`.

- Dentro de la txn de `appendItems` (ya tiene `lockForUpdate`): si `orders.status === 'ready'`, setear `in_kitchen` + `AuditService::log('order.status_changed', from ready, to in_kitchen, reason items_appended)`. PHPDoc que documente la excepción deliberada al forward-only (regresión operativa auditada; no toca estados terminales ni revenue, sin impacto contable).
- Sin re-consumo de inventario (el delta ya se descuenta en :912-914) y sin SMS (no re-notificar "en cocina" después de "listo").
- En implementación, grep de otros caminos que agreguen items consumibles a una orden existente (aprobación de items QR en `TableWaiterService`, sync offline) y aplicar la misma regresión donde una orden `ready` pueda recibir items `approved`.
- `pending` no se toca (decisión confirmada: "Pendiente" con tickets "Por entrar" es válido). `in_transit`/`completed` no aplican (append es solo mesa, terminales bloqueados).

### Fix 3 — Cerrar items al cancelar por domicilio

**Archivos**: `backend/app/Services/DeliveryService.php`, `backend/app/Models/OrderItem.php`, `backend/app/Http/Controllers/Api/OrderController.php`.

- Extraer helper en `OrderItem` (p. ej. `cancelOpenKitchenItems(string $orderId): void` — update masivo de items en `config('orders.item_statuses.operational')` a `cancelled` + `cancellation_reason='system'` + `cancelled_at`), reutilizando el patrón exacto de `OrderController::cancel` :1187-1194, y usarlo en ambos sitios.
- Llamarlo en `rejectDelivery` dentro de su txn. Verificar en implementación los demás puntos de `DeliveryService` que ponen `orders.status='cancelled'` (el grep marca :310-315; revisar `cancelDelivery` :334+) y aplicar el mismo cierre.

### Cierre

- `vendor/bin/pint --dirty --format agent`.
- Bump patch en `backend/composer.json` (solo backend; frontend no se toca).
- Actualizar `docs/wiki/BACKEND_FILES.md` y `docs/wiki/FUNCIONALIDADES_APP.md` (sincronización KDS↔Tablero: los 3 caminos nuevos).
- Commits separados por bug: `fix(orders): ...` (Fix 1), `fix(orders): ...` (Fix 2), `fix(delivery): ...` (Fix 3).

## Fuera de alcance (decisiones confirmadas / deuda anotada)

- Override del Tablero se mantiene (drag puede saltar cocina y forzar items — diseño deliberado).
- "Pendiente" con tickets "Por entrar" se mantiene (inventario se sigue descontando al entrar a cocina).
- Duplicación front/back de `kanban_rank` (`board.tsx:104-116` vs `config/orders.php:66-77`) y espejo manual `order-status.ts`: deuda existente ya señalada en comentarios, no se toca acá.
- Staleness de 30s entre pantallas (polling): mitigado por refetch on-focus, no se toca.

## Verificación (proyecto sin tests — tinker + Boost `database-query` + UI)

1. **Fix 1**: orden mesa con 2 platos → `mark-in-kitchen` ambos → `mark-ready` plato A → cancelar plato B como mesero → `orders.status` debe quedar `ready` y la orden aparecer en "Para entrega". Variante: cancelar TODOS los platos → la orden NO debe promoverse.
2. **Fix 2**: orden `ready` → `appendItems` → `orders.status='in_kitchen'`, item nuevo `approved` en KDS, sin doble descuento de inventario, audit registrado; marcar el nuevo plato listo → orden vuelve a `ready`.
3. **Fix 3**: domicilio con items en cocina → `rejectDelivery` → orden `cancelled` y todos sus items `cancelled` (query a `order_items`).
4. **Regresión**: flujo normal KDS (entró/listo/entregado) y drag del Tablero (incluido salto a completado con pago) siguen intactos.
