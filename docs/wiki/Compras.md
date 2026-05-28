# Compras

> Estado: Estable (entregado en #118, extendido por multibodega #120)
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Módulo de **órdenes de compra** a proveedores. Una orden de compra
(`purchase_orders`, PO) es un documento contable que atraviesa una
máquina de estados forward-only y, al recibirse, **mueve inventario**
delegando en `InventoryService`. La anulación post-recepción no edita
la PO original: emite una **nota crédito** (`purchase_credit_notes`) con
snapshot inmutable de las líneas reversadas y descuenta el stock con
movimientos `adjustment` negativos al costo corriente del insumo.

Las compras conviven con proveedores (ver `Proveedores.md`) e insumos
(ver `Inventario.md`). El módulo está gateado por la capability vertical
`inventory` en la sede activa y por permisos granulares por acción.

Toda la lógica transaccional vive en `App\Services\PurchaseService`. El
controlador hace HTTP, validación delegada y serialización.

---

## Modelo de datos

| Tabla | Columnas clave | Notas |
|-------|----------------|-------|
| `purchase_orders` | `id uuid`, `company_nit`, `branch_id`, `supplier_id`, `code`, `status`, `expected_date`, `received_date`, `paid_date`, `subtotal decimal(12,2)`, `tax_amount decimal(12,2)`, `total decimal(12,2)`, `payment_method`, `payment_reference`, `pending_supplier_refund bool`, `notes`, `created_by`, `received_by`, `paid_by`, `voided_by`, `voided_at` | UNIQUE `(company_nit, code)`. Enum `status` ∈ `{draft, pending, received, paid, cancelled, voided}`. Enum `payment_method` ∈ `{cash, card, transfer}`. CHECK no-negativos en montos. PO inmutable después de `received|paid|voided` (boot guard del modelo). |
| `purchase_order_items` | `id uuid`, `purchase_order_id`, `branch_id`, `ingredient_id`, `warehouse_id?`, `description`, `quantity decimal(12,3)`, `unit_cost decimal(12,2)`, `tax_rate decimal(5,2)`, `tax_amount decimal(12,2)`, `line_total decimal(12,2)` | `line_total = quantity * unit_cost + tax_amount` (calculado en `PurchaseService`). `warehouse_id` agrega destino por línea (#120) — por defecto la bodega default de la sede. CHECK `quantity > 0`. |
| `purchase_credit_notes` | `id uuid`, `company_nit`, `branch_id`, `purchase_order_id`, `code`, `reason`, `items_snapshot jsonb`, `total_reversed decimal(12,2)`, `created_by`, `created_at` | UNIQUE `(company_nit, code)`. Append-only. `items_snapshot` captura las líneas tal cual al momento de anular. |
| `purchase_order_attachments` | `id uuid`, `purchase_order_id`, `branch_id`, `type`, `path`, `original_name`, `mime`, `size_bytes`, `uploaded_by`, `deleted_at` | Soft-delete para conservación DIAN (5/10 años). Enum `type` ∈ `{invoice, delivery_note, payment_proof, other}`. |
| `supplier_ingredients` | `supplier_id`, `ingredient_id`, `last_unit_cost decimal(12,2)`, `last_purchased_at` | UNIQUE `(supplier_id, ingredient_id)`. Cachea último costo neto (sin impuestos). Actualizado por `PurchaseService::receive`. |

### Convenciones contables

- Todas las columnas monetarias en `decimal(12,2)`; cantidades físicas
  en `decimal(12,3)`. Sin `float`/`double`.
- Cálculos con `bcmath` (`BcMath\Number` o helper interno). Cero
  aritmética en `float`.
- Toda mutación de PO corre dentro de `DB::transaction` con
  `lockForUpdate` sobre la cabecera — previene doble-recepción y
  doble-pago concurrentes.
- Las transiciones se validan contra `config('purchases.transitions')`
  — fuente única de verdad.

---

## Permisos RBAC

| Slug | Grupo | Cubre |
|------|-------|-------|
| `purchases.read` | Compras | Listar y ver POs, adjuntos y notas crédito. |
| `purchases.create` | Compras | Crear borrador de PO. |
| `purchases.update` | Compras | Editar draft, transicionar `draft→pending` (submit), cancelar `draft|pending`, gestionar adjuntos. |
| `purchases.receive` | Compras | Marcar `pending→received` (mueve inventario). |
| `purchases.pay` | Compras | Marcar `received→paid` y liquidar reembolsos pendientes. |
| `purchases.delete` | Compras | Anular `received|paid → voided` (emite nota crédito). |

Defaults: `owner` y `admin` reciben todos los slugs por seeder. Los
permisos se dividen para permitir que un cajero confirme recepción
(`purchases.receive`) sin poder anular (`purchases.delete`) o pagar
(`purchases.pay`).

Capability vertical `inventory` requerida en la sede activa
(`business.capability:inventory`). Middleware previo: `branch.access`.

---

## Endpoints

### Órdenes de compra (`/api/v1/purchases`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/purchases?status=&supplier_id=&from=&to=&pending_refund=1&q=` | `purchases.read,read` | Paginado por `created_at desc`. Filtros: estado, proveedor, rango de fechas, búsqueda por `code`. |
| `POST` | `/api/v1/purchases` | `purchases.create,create` | Body: `supplier_id`, `items[]` (`ingredient_id`, `quantity`, `unit_cost`, `tax_rate?`, `description?`), `expected_date?`, `notes?`. Crea PO en `draft` con código autogenerado `PO-NNNNNN`. |
| `GET` | `/api/v1/purchases/{id}` | `purchases.read,read` | Detalle con `items`, `attachments`, `credit_notes`, `supplier`. |
| `PATCH` | `/api/v1/purchases/{id}` | `purchases.update,update` | Solo edita drafts. Recalcula totales. |
| `POST` | `/api/v1/purchases/{id}/submit` | `purchases.update,update` | `draft → pending`. Audita `purchases.submitted`. |
| `POST` | `/api/v1/purchases/{id}/receive` | `purchases.receive,update` | `pending → received`. **Mueve inventario** (ver flujo). Setea `received_date` y `received_by`. |
| `POST` | `/api/v1/purchases/{id}/pay` | `purchases.pay,update` | `received → paid`. Body: `payment_method`, `payment_reference?`. Setea `paid_date` y `paid_by`. |
| `POST` | `/api/v1/purchases/{id}/cancel` | `purchases.update,update` | `draft|pending → cancelled`. Body: `reason?`. **No** se permite tras recepción. |
| `POST` | `/api/v1/purchases/{id}/void` | `purchases.delete,delete` | `received|paid → voided`. Body: `reason` (obligatorio). Emite nota crédito y reverso de inventario. |
| `POST` | `/api/v1/purchases/{id}/settle-refund` | `purchases.pay,update` | Limpia bandera `pending_supplier_refund`. Body: `reference?`. |

### Adjuntos

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/purchases/{id}/attachments` | `purchases.read,read` | Lista adjuntos no eliminados. |
| `POST` | `/api/v1/purchases/{id}/attachments` | `purchases.update,update` | `multipart/form-data` con `file`, `type` (`invoice|delivery_note|payment_proof|other`). Disco `s3` (FILESYSTEM_DISK), nunca `local`. |
| `GET` | `/api/v1/purchases/{id}/attachments/{attachmentId}/download` | `purchases.read,read` | URL firmada / stream. |
| `DELETE` | `/api/v1/purchases/{id}/attachments/{attachmentId}` | `purchases.update,update` | Soft-delete (`deleted_at`). |

---

## Flujos funcionales

### Estados y transiciones

```
draft ──submit──> pending ──receive──> received ──pay──> paid
  │                  │                   │                 │
  │                  │                   └──void──> voided <┘
  └─cancel──> cancelled <─cancel─┘            (solo tras received|paid)
```

Estados terminales: `paid`, `voided`, `cancelled`. Toda transición
inválida devuelve `422 INVALID_PURCHASE_TRANSITION`.

### Recepción (`receive`)

`PurchaseService::receive` ejecuta dentro de `DB::transaction` +
`lockForUpdate(purchase_orders)`:

1. Valida transición (`pending → received`).
2. Por cada `purchase_order_item`:
   - Resuelve `warehouse_id` (línea o default de la sede).
   - Llama `InventoryService::recordMovement(TYPE_ENTRY)` con
     `quantity` y `unit_cost` **gross** (incluye impuesto unitario) —
     `current_cost` del insumo refleja lo efectivamente pagado por
     unidad.
   - Actualiza `supplier_ingredients.last_unit_cost` con el costo
     **neto** (sin impuesto) y `last_purchased_at`.
3. Setea `received_date = now()` y `received_by = actor`.
4. Audita `purchases.received` con `{po_id, supplier_id, total, items_count}`.

### Pago (`pay`)

`markPaid` valida que el `payment_method` esté en la lista cerrada
`{cash, card, transfer}`. Para `card` y `transfer` exige
`payment_reference`. Para `cash` la referencia es opcional pero queda
registrada en auditoría con `actor_id`. Setea `paid_date` y `paid_by`.

### Cancelación (`cancel`)

Solo permitida en `draft` o `pending`. **No revierte inventario** porque
nunca se movió. Setea `status='cancelled'` y persiste `reason` en
auditoría. Eventos: `purchases.cancelled`.

### Anulación con nota crédito (`void`)

Para `received` o `paid` se exige `void`:

1. Genera código consecutivo `NC-NNNNNN`.
2. Snapshot inmutable de los items en `purchase_credit_notes.items_snapshot`.
3. Por cada línea, llama `InventoryService::recordMovement(TYPE_ADJUSTMENT)`
   con cantidad **negativa al costo corriente del insumo** (no al
   original) — el WAC pudo haber evolucionado entre la recepción y la
   anulación.
4. Si el stock corriente no alcanza para reversar una línea
   (`current_stock < quantity_to_reverse`), aborta con
   `422 INSUFFICIENT_STOCK_FOR_VOID`. El operador debe ajustar
   manualmente antes de reintentar.
5. Setea `status='voided'`, `voided_at`, `voided_by`.
6. Si la PO estaba `paid`, marca `pending_supplier_refund=true` para
   que el operador registre el reembolso recibido del proveedor con
   `settle-refund` cuando llegue.

Las NC son inmutables. No se editan ni se eliminan. DIAN exige
conservación.

### Inmutabilidad post-recepción

El modelo `PurchaseOrder` instala un `boot` guard que rechaza
actualizaciones de `items` y de campos contables (`subtotal`,
`tax_amount`, `total`) cuando `status ∈ {received, paid, voided}`.
Únicas mutaciones permitidas tras recepción: `received_date`,
`paid_date`, `voided_at` y banderas asociadas — controladas por el
service.

---

## Reglas contables

Checklist canónico (alineado con §13 de `CLAUDE.md` y
`application/constants/ACCOUNTING_RULES.md`):

1. **`decimal(12,2)` para dinero / `decimal(12,3)` para cantidades** —
   sin excepción.
2. **`DB::transaction` + `lockForUpdate`** en `create`, `update`,
   `submit`, `receive`, `pay`, `cancel`, `void`, `settleRefund`.
3. **Append-only** para inventario y créditos: cero `UPDATE` a
   `ingredient_movements` o `purchase_credit_notes`.
4. **Anulación = nuevo asiento**, jamás `DELETE` físico.
5. **Conservación DIAN**: PO + items + credit_notes + attachments se
   conservan 5/10 años. Attachments con `deleted_at` no se vacían — el
   filesystem path queda hasta que el job de purga (fuera de scope v1)
   los elimine tras el periodo de retención.
6. **`AuditService::log` en cada transición** con metadata
   reconstructible: actor, montos, referencias.

---

## Componentes frontend

| Página | Path | Notas |
|--------|------|-------|
| `/purchases` | `resources/js/pages/purchases/index.tsx` | Listado con filtros (estado, proveedor, rango), badges de estado con tokens semánticos (`badge-warning` para `pending`, `badge-success` para `paid`, `badge-critical` para `voided`). Drawer de detalle con `items`, `attachments`, `credit_notes` y acciones contextuales según `status` y permisos del actor. |

Componentes auxiliares:

- `PurchaseStatusBadge` — mapeo de estado a token de color.
- `PurchaseItemsEditor` — tabla editable de líneas (`ingredient_id`,
  `quantity`, `unit_cost`, `warehouse_id`) con validación cliente
  espejando `decimal:2` / `decimal:3` y `maxLength` exacto.
- `ReceivePurchaseDialog` — confirmación con selector de bodega por
  línea (default = bodega default de la sede).
- `VoidPurchaseDialog` — exige `reason` (`SanitizedInput` con
  `plain_text_long`) y muestra preview de la nota crédito generada.

Tokens del DS: cero hex hardcoded. Estados financieros usan
`var(--color-status-*)`.

---

## Eventos de auditoría

Emitidos por `PurchaseService` y `PurchaseAttachmentService`:

- `purchases.draft_created` — alta de borrador.
- `purchases.draft_updated` — edición de borrador.
- `purchases.submitted` — `draft → pending`.
- `purchases.received` — `pending → received`. Metadata: `items_count`,
  `total`, `warehouse_ids`.
- `purchases.paid` — `received → paid`. Metadata: `payment_method`,
  `payment_reference`.
- `purchases.cancelled` — `draft|pending → cancelled`. Metadata:
  `reason`, `previous_status`.
- `purchases.voided` — emisión de nota crédito + reverso de inventario.
  Metadata: `credit_note_code`, `total_reversed`, `reason`.
- `purchases.refund_settled` — limpieza de `pending_supplier_refund`.
- `purchases.attachment.uploaded` / `.deleted`.

`AuditService` agrega `branch_id` y `actor_active_branch_id` desde el
request.

---

## Edge cases y empty states

- **Proveedor archivado**: `createDraft` rechaza con
  `422 SUPPLIER_ARCHIVED — Proveedor archivado, restáuralo antes de comprar`.
- **Ingrediente de otra empresa**: imposible — `BranchScope` global y
  validación explícita en service. Devuelve `422`.
- **Cantidad o costo inválidos**: `quantity > 0`, `unit_cost >= 0`,
  `tax_rate >= 0` (CHECK SQL + validación Form Request).
- **Anulación con stock insuficiente**: `422 INSUFFICIENT_STOCK_FOR_VOID`,
  bloquea hasta que el operador ajuste manualmente.
- **Transición inválida**: `422 INVALID_PURCHASE_TRANSITION` con
  `current_status` y `target_status` en el payload.
- **PO sin líneas**: `submit` rechaza con `422 EMPTY_PURCHASE_ORDER`.
- **Capability `inventory:false`**: `403 BUSINESS_CAPABILITY_DENIED`
  antes de evaluar permisos RBAC.
- **`code` duplicado**: imposible — UNIQUE `(company_nit, code)` +
  serialización en service vía `nextCode()`.
- **Listado vacío**: `EmptyState` "Aún no hay órdenes de compra" con
  CTA "Crear orden" si el actor tiene `purchases.create`.

---

## Cross-references

- Backend: `app/Http/Controllers/Api/PurchaseOrderController.php`,
  `app/Http/Controllers/Api/PurchaseAttachmentController.php`,
  `app/Services/PurchaseService.php`,
  `app/Services/PurchaseAttachmentService.php`,
  `app/Models/{PurchaseOrder,PurchaseOrderItem,PurchaseCreditNote,PurchaseOrderAttachment,SupplierIngredient}.php`,
  `config/purchases.php`.
- Migrations: `0001_01_01_001000_create_inventory_block.php`,
  `0001_01_01_001050_create_warehouses_block.php` (agrega `warehouse_id` a items).
- Frontend: `resources/js/pages/purchases/index.tsx`.
- Wiki: `Inventario.md`, `Proveedores.md`,
  `Facturación-Electrónica-DIAN.md`, sección §13 de `CLAUDE.md`.
