# Proveedores

> Estado: Estable (entregado en #118)
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Catálogo de **proveedores** (`suppliers`) por empresa y sede.
Identidad mínima del proveedor de insumos (NIT/CC, contacto,
condiciones de pago). Cada PO (ver `Compras.md`) referencia un
proveedor activo del catálogo. Los proveedores no se eliminan: el
endpoint `DELETE` ejecuta **soft-archive** (`archived_at`) para
preservar la trazabilidad histórica de POs y notas crédito asociadas
(conservación DIAN: 5/10 años).

Multi-sede: cada sede mantiene su propio catálogo de proveedores
(`branch_id NOT NULL`). El mismo NIT puede aparecer en varias sedes de
la misma empresa con `document_number` repetido — el índice único
parcial sobre `document_number` es **por empresa**, no por sede.

El módulo está gateado por la capability vertical `inventory` en la
sede activa.

---

## Modelo de datos

| Tabla | Columnas clave | Notas |
|-------|----------------|-------|
| `suppliers` | `id uuid`, `company_nit`, `branch_id`, `name (varchar 150)`, `document_type`, `document_number (varchar 32)`, `contact_name (varchar 120)`, `email (varchar 150)`, `phone (varchar 32)`, `address (varchar 255)`, `payment_terms_days unsigned smallint`, `notes text`, `archived_at`, timestamps | UNIQUE PARCIAL `(company_nit, document_number) WHERE document_number IS NOT NULL`. CHECK `payment_terms_days >= 0`. Enum `document_type` ∈ `{NIT, CC, CE, PAS, OTRO}` (nullable). |
| `supplier_ingredients` | `id uuid`, `branch_id`, `supplier_id`, `ingredient_id`, `last_unit_cost decimal(12,2)`, `last_purchased_at` | UNIQUE `(supplier_id, ingredient_id)`. Cache del último costo neto (sin impuestos) por par proveedor-insumo. Actualizado por `PurchaseService::receive`. |

### Campos

| Campo | Tipo | Reglas |
|-------|------|--------|
| `name` | `varchar(150)` | Obligatorio. Saneado con `SafePlainText(maxBytes: 150)` en el FormRequest. |
| `document_type` | enum | Opcional. Si `document_number` se provee, el tipo se vuelve obligatorio en validación. |
| `document_number` | `varchar(32)` | Opcional. Único por empresa cuando se provee. Saneado con `SafePlainText` + regex de validación. |
| `contact_name`, `email`, `phone`, `address`, `notes` | nullable | Saneados en `prepareForValidation` con el trait `SanitizesInput` (`plain_text_short` / `plain_text_long` según el caso). |
| `payment_terms_days` | `smallint` | Días de crédito acordados (0 = contado). Aparece en el detalle de la PO como referencia operativa — no genera cuentas por pagar automáticas en v1. |

---

## Permisos RBAC

| Slug | Grupo | Cubre |
|------|-------|-------|
| `suppliers.read` | Compras | Listar y ver detalle. |
| `suppliers.create` | Compras | Alta de proveedores. |
| `suppliers.update` | Compras | Editar metadatos y restaurar archivados. |
| `suppliers.delete` | Compras | Archivar (soft-delete). |

Defaults: `owner` y `admin` reciben los cuatro slugs por seeder.

Capability vertical `inventory` requerida en la sede activa
(`business.capability:inventory`). Middleware previo: `branch.access`.

El catálogo de proveedores se considera dato operativo de sede — no es
sensible cross-branch y no requiere `metrics.view_all_branches`.

---

## Endpoints

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/suppliers?q=&archived=0&per_page=50` | `suppliers.read,read` | Paginado por `name asc`. Búsqueda `q` matchea `name`, `document_number` o `contact_name` con `ilike`. Por defecto excluye archivados; `archived=1` filtra a archivados. |
| `POST` | `/api/v1/suppliers` | `suppliers.create,create` | Body: `name`, `document_type?`, `document_number?`, `contact_name?`, `email?`, `phone?`, `address?`, `payment_terms_days?`, `notes?`. Devuelve `201` con el recurso serializado. |
| `GET` | `/api/v1/suppliers/{id}` | `suppliers.read,read` | Detalle. Incluye `archived_at` cuando aplica. |
| `PATCH` | `/api/v1/suppliers/{id}` | `suppliers.update,update` | Update parcial. |
| `DELETE` | `/api/v1/suppliers/{id}` | `suppliers.delete,delete` | Soft-archive — setea `archived_at = now()`. Devuelve el recurso archivado. |
| `POST` | `/api/v1/suppliers/{id}/restore` | `suppliers.update,update` | Restaura archivado — setea `archived_at = null`. Devuelve el recurso restaurado. |

Validación: `StoreSupplierRequest` y `UpdateSupplierRequest` con
`SanitizesInput` y rules `SafePlainText` por campo. `email` valida
formato RFC y `phone` se normaliza (solo dígitos + sufijos comunes).

---

## Flujos funcionales

### Alta

1. UI carga `/suppliers`, click "Nuevo proveedor".
2. Modal con formulario; sanitización cliente espejando los `maxBytes`
   del backend.
3. `POST /api/v1/suppliers`.
4. Si el `document_number` choca contra otro proveedor activo o
   archivado de la misma empresa, devuelve `422` con `errors.document_number`
   "Ya existe un proveedor con ese documento" — el operador puede
   buscarlo y restaurarlo si estaba archivado.

### Vinculación con compras

El proveedor se referencia en `purchase_orders.supplier_id`. Reglas:

- Solo se aceptan proveedores **activos** (`archived_at IS NULL`) al
  crear borrador (`PurchaseService::createDraft` valida explícitamente
  y rechaza con `422 SUPPLIER_ARCHIVED`).
- POs históricas que ya referencian a un proveedor archivado siguen
  visibles y exportables — la FK usa `restrictOnDelete` para impedir
  el borrado físico aunque el operador intente forzar.
- `supplier_ingredients` mantiene el último costo neto pagado por cada
  par `(supplier, ingredient)`. Útil para sugerir precios al armar
  nuevas POs (componente `PurchaseItemsEditor` consulta este cache).

### Soft-archive y restauración

- `destroy` (DELETE) no purga datos; setea `archived_at` y deja la fila
  intacta. Las relaciones (`purchase_orders`, `supplier_ingredients`)
  permanecen.
- `restore` revierte `archived_at` a `null`. No regenera
  `supplier_ingredients` ni reabre POs.
- Listado por default filtra por `archived_at IS NULL`. Toggle
  "Ver archivados" en la UI invierte el filtro.

### Conservación documental

Aunque `suppliers` no es un documento DIAN, sus POs sí. Por eso el
archivado es siempre suave. Hay tres caminos terminales prohibidos:

- `DELETE FROM suppliers WHERE id = ?` — bloqueado por FK
  `restrictOnDelete` desde `purchase_orders.supplier_id`.
- Re-uso del `document_number` para un proveedor distinto — el índice
  único parcial impide colisiones, incluso contra proveedores archivados.
- Edición destructiva del NIT histórico — auditoría preserva el `before`
  en `purchases.supplier.updated`.

---

## Componentes frontend

| Página | Path | Notas |
|--------|------|-------|
| `/suppliers` | `resources/js/pages/suppliers/index.tsx` | Listado con `FilterBar` (búsqueda, toggle `archived`), tabla con `name`, `document_number`, `contact_name`, `payment_terms_days`, badge `Archivado`. Acciones por fila: editar, archivar / restaurar. Modal crear/editar con `SanitizedInput` por campo. |

Componentes auxiliares: `SupplierSelect` (usado en
`PurchaseItemsEditor`), `SupplierBadge`, `ConfirmDialog` para archivar
con copy "Esta acción no elimina las compras asociadas".

Tokens del DS: cero hex hardcoded. Badge "Archivado" usa
`bg-muted text-muted-foreground`.

---

## Eventos de auditoría

Emitidos por `SupplierController`:

- `purchases.supplier.created` — alta. Metadata: `name`, `document_number`.
- `purchases.supplier.updated` — edición. Metadata: `before`, `after`
  con los campos modificados (`name`, `document_number`, `email`,
  `phone`, `payment_terms_days`).
- `purchases.supplier.archived` — soft-archive. Metadata: `name`.
- `purchases.supplier.restored` — restauración. Metadata: `name`.

`AuditService::log` agrega `branch_id` y `actor_active_branch_id` desde
el request — útil para rastrear quién y desde qué sede creó al
proveedor.

---

## Edge cases y empty states

- **Documento duplicado**: `422` con mensaje en `errors.document_number`
  y código `SUPPLIER_DOCUMENT_TAKEN`. El frontend ofrece buscar el
  existente.
- **Archivar proveedor con PO en `pending`**: permitido. La PO sigue
  visible pero futuras compras no podrán seleccionar al proveedor hasta
  restaurarlo. (Decisión consciente: no bloquear archivado por POs
  pendientes — el operador puede preferir cerrar relación con un
  proveedor inactivo.)
- **Restaurar proveedor con `document_number` repetido por otro nuevo**:
  imposible — el índice único parcial dispara `422` en el `POST` que
  crea el segundo, antes de archivar el primero.
- **Búsqueda sin resultados**: `EmptyState` "No encontramos proveedores
  con ese filtro" con botón "Limpiar filtros".
- **Empresa nueva sin proveedores**: `EmptyState` "Aún no tienes
  proveedores" con CTA "Crear proveedor" (visible solo si el actor
  tiene `suppliers.create`).
- **Capability `inventory:false`**: `403 BUSINESS_CAPABILITY_DENIED`
  antes de evaluar permisos RBAC.
- **`payment_terms_days` negativo**: rechazado por CHECK SQL y por la
  rule `min:0` en el FormRequest.
- **Email malformado**: `422` con `errors.email`. El campo es
  opcional pero, si llega, debe ser válido.

---

## Cross-references

- Backend: `app/Http/Controllers/Api/SupplierController.php`,
  `app/Http/Requests/Suppliers/{StoreSupplierRequest,UpdateSupplierRequest}.php`,
  `app/Models/{Supplier,SupplierIngredient}.php`,
  `app/Services/PurchaseService.php` (consume `supplier_ingredients`).
- Migrations: `0001_01_01_001000_create_inventory_block.php`
  (tablas `suppliers` y `supplier_ingredients`).
- Frontend: `resources/js/pages/suppliers/index.tsx`,
  `components/suppliers/supplier-select.tsx`.
- Wiki: `Compras.md`, `Inventario.md`, `Usuarios-Roles-Permisos.md`,
  `SECURITY_INPUT_HANDLING.md` (sanitización de campos de texto libre).
