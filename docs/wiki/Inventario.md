# Inventario

> Estado: Estable (multibodega entregada en #120)
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Módulo de inventario de insumos por sede con soporte de **multibodega** (#120).
Cada sede (`branches`) puede tener N **bodegas** (`warehouses`) — cocina,
barra, congelador, almacén seco, etc. Cada insumo (`ingredients`) lleva
stock independiente por bodega en la tabla `ingredient_stocks`. La fuente
de verdad para el saldo es la sucesión append-only de
`ingredient_movements`; el campo `quantity` de `ingredient_stocks` es la
materialización transaccional (cache) que mantiene el `InventoryService`.

El costo unitario corriente del insumo (`ingredients.current_cost`) se
calcula como **WAC global** del insumo (no por bodega) — promedio
ponderado actualizado únicamente al registrar movimientos de tipo
`entry`.

El módulo está gateado a nivel de sede por la capability vertical
`inventory` (#237): sedes con `inventory:false` en el override de su
vertical devuelven `403 BUSINESS_CAPABILITY_DENIED` antes de evaluar
permisos RBAC.

---

## Modelo de datos

| Tabla | Columnas clave | Notas |
|-------|----------------|-------|
| `warehouses` | `id uuid` (PK), `company_nit`, `branch_id`, `name`, `slug`, `type`, `is_default bool`, `archived_at` | UNIQUE `(company_nit, branch_id, slug)`. Índice parcial único: una sola `is_default=true` por sede activa. Enum cerrado `type` ∈ `{main, kitchen, bar, cold_storage, dry_storage}`. |
| `ingredients` | `id uuid` (PK), `company_nit`, `branch_id`, `name`, `category`, `unit`, `current_cost decimal(12,2)`, `archived_at` | UNIQUE `(company_nit, branch_id, name)`. Enum cerrado `unit` ∈ `{kg, g, l, ml, un}`. `current_cost` es WAC global del insumo. **No** lleva `current_stock` ni `min_stock` (se movieron a `ingredient_stocks` en #120). |
| `ingredient_stocks` | `ingredient_id`, `warehouse_id`, `quantity decimal(12,3)`, `min_stock decimal(12,3)`, `updated_at` | UNIQUE `(ingredient_id, warehouse_id)`. CHECK `min_stock >= 0`. Materializa el saldo y el mínimo por bodega. |
| `ingredient_movements` | `id uuid`, `company_nit`, `branch_id`, `ingredient_id`, `warehouse_id`, `dest_warehouse_id`, `type`, `quantity decimal(12,3)` (firmada), `unit_cost decimal(12,2)?`, `reference`, `actor_id`, `created_at` | Append-only. `dest_warehouse_id` solo permitido cuando `type='transfer'` (CHECK explícito). Índice `(warehouse_id, created_at)`. |
| `warehouse_stock_snapshots` | `warehouse_id`, `ingredient_id`, `snapshot_date`, `quantity`, `unit_cost`, `line_value`, `created_at` | UNIQUE `(warehouse_id, ingredient_id, snapshot_date)`. Snapshots diarios alineados con `menu_item_cost_history`. Generados por `inventory:snapshot-daily`. |
| `recipes` | `id uuid`, `company_nit`, `branch_id`, `menu_id`, `menu_item_id`, `ingredient_id`, `warehouse_id`, `quantity decimal(12,3)`, `unit`, `archived_at` | BOM por ítem de menú. Una línea activa por `(company_nit, menu_item_id, ingredient_id)` (índice único parcial). Cada línea declara desde qué bodega se descuenta el insumo. |
| `menu_item_cost_history` | `menu_id`, `menu_item_id`, `snapshot_date`, `computed_cost`, `source` | Append-only. Snapshot diario del costo computado de cada ítem (`source` ∈ `{recipe, manual}`). |

### Cantidades vs valor

Las cantidades de inventario son **físicas** (`decimal(12,3)` — kg, l,
gramos en recetas). Las columnas de costo son **contables**
(`decimal(12,2)` — COP, 2 decimales). Nunca se mezclan escalas.

### Stock total por sede

```sql
SELECT SUM(s.quantity)
FROM ingredient_stocks s
JOIN warehouses w ON w.id = s.warehouse_id
WHERE w.branch_id = ?
  AND s.ingredient_id = ?
  AND w.archived_at IS NULL;
```

---

## Permisos RBAC

| Slug | Grupo | Aplica a |
|------|-------|----------|
| `inventory.read` | Inventario | Listar insumos, valorización, movimientos, historial. |
| `inventory.create` | Inventario | Alta de insumos y registrar entradas / mermas. |
| `inventory.update` | Inventario | Editar metadatos, restaurar archivados, ajustes manuales, transferencias entre bodegas. |
| `inventory.delete` | Inventario | Archivar (soft-delete) insumos del catálogo. |
| `inventory.transfer_cross_branch` | Inventario | Transferencias **entre sedes** (no entre bodegas de la misma sede). Endpoint dedicado, asignable manualmente. |
| `warehouses.manage` | Inventario | Crear, editar y archivar bodegas. Permiso unificado: cubre read+create+update+delete en `/company/warehouses`. |

Defaults: `owner` y `admin` reciben los seis permisos por seeder.
`inventory.transfer_cross_branch` se conserva como permiso sensible
asignable manualmente.

Capability vertical `inventory` requerida en la sede activa
(`business.capability:inventory`) — gate transversal previo a evaluar
RBAC.

---

## Endpoints

### Bodegas (`/api/v1/company/warehouses`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/company/warehouses?branch_id=&include_archived=1` | `warehouses.manage,read` | Listado por sede. Sin `branch_id` devuelve todas las bodegas de la empresa. |
| `POST` | `/api/v1/company/warehouses` | `warehouses.manage,create` | Body: `branch_id`, `name`, `slug?`, `type`, `is_default?`. Marcar `is_default=true` degrada el default anterior dentro de la sede (atómico). |
| `PATCH` | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage,update` | Update parcial. |
| `DELETE` | `/api/v1/company/warehouses/{warehouse}` | `warehouses.manage,delete` | Soft-archive. Rechaza con `422 WAREHOUSE_HAS_STOCK` si algún `ingredient_stocks.quantity > 0`. Rechaza con `422 LAST_ACTIVE_WAREHOUSE` si es la única bodega activa de la sede. |

### Insumos (`/api/v1/inventory/ingredients`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/inventory/ingredients?warehouse_id=&low_stock=1&q=&category=&archived=0` | `inventory.read` | Paginado. `low_stock=1` deja solo insumos con `quantity < min_stock` en alguna bodega. Devuelve `meta.low_stock_count` y catálogo distinto de categorías. |
| `POST` | `/api/v1/inventory/ingredients` | `inventory.create` | Body: `name`, `unit`, `category?`, `initial_stock?`, `initial_cost?`, `warehouse_id?` (default = bodega default de la sede). Si llegan `initial_stock` + `initial_cost`, dispara `recordMovement(ENTRY)` y queda como existencia inicial. |
| `GET` | `/api/v1/inventory/ingredients/{id}` | `inventory.read` | Detalle con `stocks[]` por bodega. |
| `PATCH` | `/api/v1/inventory/ingredients/{id}` | `inventory.update` | `min_stock` se persiste en la bodega default. |
| `DELETE` | `/api/v1/inventory/ingredients/{id}` | `inventory.delete` | Soft-archive (`archived_at`). |
| `POST` | `/api/v1/inventory/ingredients/{id}/restore` | `inventory.update` | Restaura archivado. |
| `GET` | `/api/v1/inventory/valuation?warehouse_id=` | `inventory.read` | Valor del inventario (`SUM(quantity * current_cost)`). |

### Movimientos (`/api/v1/inventory/ingredients/{id}/movements`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `.../movements` | `inventory.read` | Historial paginado, orden descendente. |
| `POST` | `.../movements/entry` | `inventory.create` | Body: `quantity > 0`, `unit_cost > 0`, `reference?`, `warehouse_id?`. Recalcula WAC global del insumo. |
| `POST` | `.../movements/waste` | `inventory.create` | Body: `quantity > 0` (se persiste como negativo), `reference` (obligatorio: motivo de la merma). |
| `POST` | `.../movements/adjustment` | `inventory.update` | Body: `quantity` (firmada ±), `reference` (obligatorio: motivo). |

### Transferencias (`/api/v1/inventory/transfers`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `POST` | `/api/v1/inventory/transfers` | `inventory.update` | Body: `ingredient_id`, `from_warehouse_id`, `to_warehouse_id`, `quantity`, `notes?`. Atómico en `DB::transaction` con `lockForUpdate` sobre ambos `ingredient_stocks`. Rechaza `422 INSUFFICIENT_STOCK` si el origen no alcanza. Crea un solo movimiento `type='transfer'` con `dest_warehouse_id` poblado. **Solo entre bodegas de la misma sede**; cross-branch usa endpoint dedicado (fuera de scope v1). |

### Histórico (`/api/v1/inventory/history/valuation`)

| Método | Ruta | Permiso | Notas |
|--------|------|---------|-------|
| `GET` | `/api/v1/inventory/history/valuation?from=&to=&warehouse_id=` | `inventory.read` | Serie temporal del valor del inventario a partir de `warehouse_stock_snapshots`. Para días sin snapshot reconstruye desde movimientos. |

---

## Flujos funcionales

### Tipos de movimiento

| Tipo | Signo `quantity` | `unit_cost` | Efectos |
|------|------------------|-------------|---------|
| `entry` | positivo | obligatorio | Suma stock en bodega. Recalcula WAC global. Reseta `supplier_ingredients.last_unit_cost` si vino de compra. |
| `waste` | negativo | nulo | Descuenta stock por merma. `reference` obligatoria. |
| `adjustment` | firmada ± | nulo | Ajuste manual por conteo físico. `reference` obligatoria. |
| `sale_consumption` | negativo | nulo | Generado automáticamente por `OrderController` al promover una orden a `in_kitchen` si existe receta y la bandera de auto-consumo está activa. |
| `transfer` | negativo en origen / positivo en destino | nulo | Una sola fila con `dest_warehouse_id` poblado; el `InventoryService` ajusta ambos `ingredient_stocks` en la misma transacción. |

### Recetas y consumo automático

`recipes` define el BOM de cada ítem del menú. Al promover una orden a
`status='in_kitchen'`, `OrderController::updateStatus` invoca
`consumeInventoryForItems`, que:

1. Carga todas las recetas activas de los `menu_item_id` de la orden.
2. Para cada `(ingredient, warehouse)` declarado en la receta, registra
   un movimiento `sale_consumption` con la cantidad proporcional.
3. Marca `orders.inventory_consumed_at` para garantizar idempotencia —
   re-promociones no descuentan dos veces.
4. Si falta receta para algún ítem, audita
   `inventory.recipe.missing` (informativo, no bloquea).
5. Si el insumo o la bodega no están disponibles, audita
   `inventory.recipe.ingredient_unavailable`.

El reverso ocurre al cancelar una orden ya en cocina: el servicio
revierte los `sale_consumption` con `adjustment` positivos referenciando
la orden.

### Recálculo del WAC

Al recibir un `entry` con costo `unit_cost`:

```
WAC_nuevo = (stock_anterior * costo_anterior + cantidad_entrada * unit_cost)
            / (stock_anterior + cantidad_entrada)
```

El cálculo es a nivel **insumo** (no bodega): preserva margen histórico
al cambiar precios y mantiene `current_cost` único para los reportes de
food cost y menu engineering (#113 / #114).

### Alertas de stock bajo

`low_stock` es uno de los cuatro evaluators del módulo de alertas
accionables (#124, §12.quater de `FUNCIONALIDADES_APP.md`):

- Severidad `critical` si `quantity = 0`, `warning` si `quantity <= min_stock`.
- Evaluado diario por `alerts:evaluate` (cron 05:00 Bogotá tras el
  snapshot de food cost).
- Deep-link desde el feed del dashboard a `/inventory?low_stock=1`.

`min_stock` vive por bodega (`ingredient_stocks.min_stock`); el flag
`is_low_stock` del listado se enciende si **cualquier** bodega activa
está por debajo de su mínimo.

### Onboarding y bodega default

`CompanyEnrollmentController::store` y `BranchController::store` crean
una bodega `'Principal'` (`type=main`, `is_default=true`) junto con la
sede. Sin ella, los movimientos sin `warehouse_id` explícito devuelven
`422 NO_DEFAULT_WAREHOUSE`.

### Política de archivado

- Una bodega solo puede archivarse si todos sus
  `ingredient_stocks.quantity = 0`. Se exige transferir antes.
- No puede archivarse la única bodega activa de una sede.
- Archivar un insumo (`archived_at`) lo oculta del listado por default
  (`active` scope) pero conserva sus movimientos y snapshots — DIAN
  exige trazabilidad histórica.

---

## Componentes frontend

| Página | Path | Notas |
|--------|------|-------|
| `/inventory` | `resources/js/pages/inventory/index.tsx` | Tabla de insumos con selector de bodega en el header, badges `is_low_stock`, filtros `q`/`category`/`archived`. Drawer de movimientos por insumo. Botón "Transferir" abre modal validando bodegas de la misma sede. |
| `/company/warehouses` | `resources/js/pages/company/warehouses/index.tsx` | CRUD de bodegas por sede. Cards agrupadas por sede con badges `Principal` y `Archivada`. Modal crear/editar con `type` (`Select`), `is_default` (`Checkbox`), `slug` (`SanitizedInput`). Toggle "Ver archivadas". |

Componentes auxiliares: `useInventory` (hook compartido entre insumos y
movimientos), `WarehouseSelect`, `WarehouseBadge`, `StockBadge`,
`StatTile` para KPIs (valor total, insumos en stock bajo, número de
bodegas).

Tokens del DS: `border-warning` / `border-critical` para badges de stock
bajo; nunca hex hardcoded.

---

## Eventos de auditoría

Emitidos por `WarehouseController`, `IngredientController`,
`IngredientMovementController`, `InventoryTransferController` e
`InventoryService`:

- `warehouse.created` — `{warehouse_id, branch_id, slug, type}`.
- `warehouse.updated` — `{warehouse_id, before, after}`.
- `warehouse.archived` — `{warehouse_id, slug}`.
- `inventory.ingredient.created` / `.updated` / `.archived` / `.restored`.
- `inventory.movement` — `{ingredient_id, warehouse_id, type, quantity, unit_cost?}`. Emitido en todo `recordMovement`.
- `inventory.transfer` — `{from_warehouse_id, to_warehouse_id, ingredient_id, quantity, unit_cost}`.
- `inventory.recipe.missing` — orden sin receta para algún ítem (no bloqueante).
- `inventory.recipe.ingredient_unavailable` — insumo o bodega no disponibles en consumo automático.

`AuditService::log` agrega `branch_id` y `actor_active_branch_id`
automáticamente desde el request.

---

## Edge cases y empty states

- **Empresa sin bodegas en la sede activa**: el listado de `/inventory`
  responde con `EmptyState` "Aún no hay bodegas en esta sede" y CTA a
  `/company/warehouses`.
- **Insumo sin movimientos**: aparece con `quantity = 0` en su bodega
  inicial (fila placeholder en `ingredient_stocks`). El histórico
  muestra el estado vacío.
- **Transferencia con `from_warehouse_id == to_warehouse_id`**:
  `422 INVALID_TRANSFER`.
- **Transferencia cross-branch**: rechazada por `InventoryTransferController`
  con `422 CROSS_BRANCH_TRANSFER_REQUIRES_DEDICATED_FLOW` — el permiso
  `inventory.transfer_cross_branch` existe en el catálogo pero su
  endpoint dedicado quedó fuera de scope en v1.
- **Bodega archivada referenciada en receta**: la receta sigue activa
  pero el consumo automático audita `ingredient_unavailable` y la línea
  queda sin descontar — operador debe corregir la receta o restaurar la
  bodega.
- **Capability `inventory:false`**: todas las rutas devuelven
  `403 BUSINESS_CAPABILITY_DENIED`. Sedes que migran su vertical pueden
  perder acceso al módulo.
- **Sede recién creada sin bodega default**: rara — solo ocurre si el
  seeder de bodega default falla. `IngredientMovementController` exige
  `warehouse_id` explícito en ese caso.

---

## Cross-references

- Backend: `app/Http/Controllers/Company/WarehouseController.php`,
  `app/Http/Controllers/Api/IngredientController.php`,
  `app/Http/Controllers/Api/IngredientMovementController.php`,
  `app/Http/Controllers/Api/InventoryTransferController.php`,
  `app/Http/Controllers/Api/InventoryHistoryController.php`,
  `app/Services/InventoryService.php`,
  `app/Services/WarehouseStockHistoryService.php`,
  `app/Console/Commands/InventorySnapshotDaily.php`,
  `app/Models/{Warehouse,Ingredient,IngredientStock,IngredientMovement,Recipe,WarehouseStockSnapshot}.php`.
- Migrations: `0001_01_01_001000_create_inventory_block.php`,
  `0001_01_01_001050_create_warehouses_block.php`.
- Frontend: `resources/js/pages/inventory/index.tsx`,
  `resources/js/pages/company/warehouses/index.tsx`,
  `hooks/use-inventory.ts`.
- Wiki: `Compras.md`, `Proveedores.md`, `Empresas.md`,
  `Usuarios-Roles-Permisos.md`, sección `12.quinquies` y `12.quater` de
  `FUNCIONALIDADES_APP.md`.
