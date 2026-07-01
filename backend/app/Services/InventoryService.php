<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\UnitConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lógica transaccional del módulo de inventario multibodega (#120).
 *
 * Reglas:
 *  - Cada `recordMovement` opera sobre una bodega (origen). Bloquea con
 *    `lockForUpdate` la fila de `ingredient_stocks` del par (ingrediente,
 *    bodega) y actualiza su `quantity` sumando `signed_quantity`.
 *  - El `current_cost` (WAC) vive **por bodega** en `ingredient_stocks`
 *    (#costeo-multibodega). En cada `entry` se recalcula sobre el stock de ESA
 *    bodega — cada bodega tiene su propio costo promedio, lo que habilita el
 *    costeo por sede (una receta costea desde la bodega de su línea).
 *  - `transfer($from, $to, $ingredient, $qty)`: dos movimientos atómicos
 *    en una sola transacción: el origen registra `transfer` con qty
 *    negativa y `dest_warehouse_id = $to`; el destino registra `transfer`
 *    con qty positiva y `dest_warehouse_id = $from`. La transferencia
 *    **traslada valor**: la bodega destino mezcla su WAC con el costo entrante
 *    (= WAC de la bodega origen); el WAC del origen no cambia.
 *  - Toda mutación dispara AuditLog 'inventory.movement.recorded'.
 *
 * Tipos válidos: entry (+) | adjustment (±) | sale_consumption (−) |
 * waste (−) | transfer (origen − / destino +, atómico).
 */
class InventoryService
{
    public const TYPE_ENTRY = 'entry';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_SALE_CONSUMPTION = 'sale_consumption';

    public const TYPE_WASTE = 'waste';

    public const TYPE_TRANSFER = 'transfer';

    public const VALID_TYPES = [
        self::TYPE_ENTRY,
        self::TYPE_ADJUSTMENT,
        self::TYPE_SALE_CONSUMPTION,
        self::TYPE_WASTE,
        self::TYPE_TRANSFER,
    ];

    public const VALID_UNITS = ['kg', 'g', 'l', 'ml', 'un'];

    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Registra un movimiento contra una bodega específica y actualiza:
     *  - ingredient_stocks.quantity de (ingredient, warehouse).
     *  - ingredient_stocks.current_cost (WAC de la bodega) solo cuando type='entry'.
     *
     * Para transferencias usar `transfer()` — no este método.
     *
     * @param  string  $signedQuantity  Cantidad firmada acorde al tipo
     *                                  (entry+, waste/sale_consumption−, adjustment ±).
     * @param  string|null  $unitCost  Solo se usa en `entry`; obligatorio en ese caso.
     */
    public function recordMovement(
        Ingredient $ingredient,
        Warehouse $warehouse,
        string $type,
        string $signedQuantity,
        ?string $unitCost,
        ?string $reference,
        ?User $actor,
        bool $allowNegativeStock = false,
        ?string $branchId = null,
    ): IngredientMovement {
        if ($type === self::TYPE_TRANSFER) {
            throw ValidationException::withMessages([
                'type' => 'Usa transfer() para movimientos de tipo transfer.',
            ]);
        }

        if (! in_array($type, self::VALID_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipo de movimiento inválido.',
            ]);
        }

        if ($type === self::TYPE_ENTRY && ($unitCost === null || (float) $unitCost <= 0)) {
            throw ValidationException::withMessages([
                'unit_cost' => 'El costo unitario es obligatorio y debe ser positivo en una entrada.',
            ]);
        }

        $this->assertWarehouseBelongsToIngredientCompany($ingredient, $warehouse);

        $qty = (float) $signedQuantity;
        if ($qty == 0.0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad no puede ser cero.',
            ]);
        }

        $this->assertSignMatchesType($type, $qty);

        return DB::transaction(function () use ($ingredient, $warehouse, $type, $signedQuantity, $unitCost, $reference, $actor, $allowNegativeStock, $branchId) {
            $fresh = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();

            if ($fresh->archived_at !== null) {
                throw ValidationException::withMessages([
                    'ingredient' => 'El insumo está archivado — restaúralo antes de registrar movimientos.',
                ]);
            }

            $stock = $this->lockOrCreateStock($fresh->id, $warehouse->id);

            $previousQuantity = (string) $stock->quantity;
            $previousCost = (string) $stock->current_cost;

            $newQuantity = bcadd($previousQuantity, $signedQuantity, 3);

            if (bccomp($newQuantity, '0', 3) < 0 && ! $allowNegativeStock) {
                throw ValidationException::withMessages([
                    'quantity' => 'El movimiento dejaría el stock de la bodega en negativo (actual: '.$previousQuantity.').',
                ]);
            }

            // WAC por bodega: solo se recalcula en entry, sobre el stock de
            // ESTA bodega (no el agregado). Cada bodega lleva su propio costo
            // promedio — base del costeo por sede.
            $newCost = $previousCost;
            if ($type === self::TYPE_ENTRY) {
                $newCost = $this->weightedAverageCost(
                    previousStock: $previousQuantity,
                    previousCost: $previousCost,
                    entryQty: $signedQuantity,
                    entryUnitCost: (string) $unitCost,
                );
            }

            $movement = IngredientMovement::create([
                'company_nit' => $fresh->company_nit,
                'branch_id' => $this->resolveMovementBranchId($warehouse, $branchId),
                'warehouse_id' => $warehouse->id,
                'dest_warehouse_id' => null,
                'ingredient_id' => $fresh->id,
                'type' => $type,
                'quantity' => $signedQuantity,
                'unit_cost' => $type === self::TYPE_ENTRY ? $unitCost : null,
                'reference' => $reference,
                'actor_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $stock->quantity = $newQuantity;
            if ($type === self::TYPE_ENTRY) {
                $stock->current_cost = $newCost;
            }
            $stock->updated_at = now();
            $stock->save();

            $this->auditService->log('inventory.movement.recorded', $actor, $movement, [
                'ingredient_id' => $fresh->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'quantity' => $signedQuantity,
                'unit_cost' => $unitCost,
                'reference' => $reference,
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $newQuantity,
                'previous_cost' => $previousCost,
                'new_cost' => $newCost,
            ]);

            $movement->setRelation('ingredient', $fresh);

            return $movement;
        });
    }

    /**
     * Transfiere stock entre dos bodegas de la misma empresa.
     *
     * (#costeo-multibodega) Las bodegas son company-scoped y pueden servir a N
     * sedes, así que se permite transferir entre cualquier par de bodegas de la
     * empresa (antes se exigía misma sede).
     *
     * Atómico: dos movimientos `transfer` con el mismo `reference`. Traslada
     * valor: la bodega destino mezcla su WAC con el costo entrante (= WAC de la
     * bodega origen); el WAC del origen no cambia (sólo pierde cantidad).
     *
     * Validaciones:
     *  - Origen y destino de la misma empresa (vía company del insumo).
     *  - Origen != destino.
     *  - Cantidad > 0.
     *  - Stock disponible en origen >= cantidad (no se permite negativo en transfer).
     *  - Ninguna bodega archivada.
     */
    public function transfer(
        Warehouse $from,
        Warehouse $to,
        Ingredient $ingredient,
        string $quantity,
        ?string $reference,
        ?User $actor,
        ?string $branchId = null,
    ): array {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'La bodega de origen y destino no pueden ser la misma.',
            ]);
        }

        if ($from->company_nit !== $to->company_nit) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Las transferencias deben ser entre bodegas de la misma empresa.',
            ]);
        }

        if ($from->isArchived() || $to->isArchived()) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'No se puede transferir desde/hacia una bodega archivada.',
            ]);
        }

        $this->assertWarehouseBelongsToIngredientCompany($ingredient, $from);
        $this->assertWarehouseBelongsToIngredientCompany($ingredient, $to);

        $qty = (float) $quantity;
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad a transferir debe ser positiva.',
            ]);
        }

        return DB::transaction(function () use ($from, $to, $ingredient, $quantity, $reference, $actor, $branchId) {
            $fresh = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();

            if ($fresh->archived_at !== null) {
                throw ValidationException::withMessages([
                    'ingredient' => 'El insumo está archivado.',
                ]);
            }

            // Lock determinista (id ASC) para evitar deadlocks si dos hilos
            // transfieren entre las mismas dos bodegas en sentido opuesto.
            $ids = [$from->id, $to->id];
            sort($ids);

            $stockFrom = $this->lockOrCreateStock($fresh->id, $from->id);
            $stockTo = $this->lockOrCreateStock($fresh->id, $to->id);

            $fromBefore = (string) $stockFrom->quantity;
            $toBefore = (string) $stockTo->quantity;
            $fromCost = (string) $stockFrom->current_cost;
            $toCostBefore = (string) $stockTo->current_cost;

            $newFrom = bcsub($fromBefore, $quantity, 3);
            $newTo = bcadd($toBefore, $quantity, 3);

            if (bccomp($newFrom, '0', 3) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stock insuficiente en la bodega origen (actual: '.$fromBefore.').',
                ]);
            }

            // La bodega destino absorbe valor al WAC del origen: mezcla su costo
            // promedio con la cantidad entrante. El origen conserva su WAC.
            $newToCost = $this->weightedAverageCost(
                previousStock: $toBefore,
                previousCost: $toCostBefore,
                entryQty: $quantity,
                entryUnitCost: $fromCost,
            );

            $ref = $reference ?? ('TRF-'.now()->format('YmdHis').'-'.$fresh->id);

            $movementOut = IngredientMovement::create([
                'company_nit' => $fresh->company_nit,
                'branch_id' => $this->resolveMovementBranchId($from, $branchId),
                'warehouse_id' => $from->id,
                'dest_warehouse_id' => $to->id,
                'ingredient_id' => $fresh->id,
                'type' => self::TYPE_TRANSFER,
                'quantity' => '-'.ltrim($quantity, '-'),
                'unit_cost' => null,
                'reference' => $ref,
                'actor_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $movementIn = IngredientMovement::create([
                'company_nit' => $fresh->company_nit,
                'branch_id' => $this->resolveMovementBranchId($to, $branchId),
                'warehouse_id' => $to->id,
                'dest_warehouse_id' => $from->id,
                'ingredient_id' => $fresh->id,
                'type' => self::TYPE_TRANSFER,
                'quantity' => ltrim($quantity, '-'),
                'unit_cost' => null,
                'reference' => $ref,
                'actor_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $stockFrom->quantity = $newFrom;
            $stockFrom->updated_at = now();
            $stockFrom->save();

            $stockTo->quantity = $newTo;
            $stockTo->current_cost = $newToCost;
            $stockTo->updated_at = now();
            $stockTo->save();

            $this->auditService->log('inventory.transfer.executed', $actor, $movementOut, [
                'ingredient_id' => $fresh->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => $quantity,
                'reference' => $ref,
                'from_before' => $fromBefore,
                'from_after' => $newFrom,
                'to_before' => $toBefore,
                'to_after' => $newTo,
                'from_unit_cost' => $fromCost,
                'to_cost_before' => $toCostBefore,
                'to_cost_after' => $newToCost,
            ]);

            return [$movementOut, $movementIn];
        });
    }

    /**
     * Devuelve el stock actual de un insumo:
     *  - Si se pasa $warehouse: cantidad en esa bodega.
     *  - Sin $warehouse: cantidad agregada en todas las bodegas activas del insumo.
     */
    public function currentStock(Ingredient $ingredient, ?Warehouse $warehouse = null): string
    {
        if ($warehouse !== null) {
            $stock = IngredientStock::query()
                ->where('ingredient_id', $ingredient->id)
                ->where('warehouse_id', $warehouse->id)
                ->first();

            return $stock ? (string) $stock->quantity : '0.000';
        }

        return $this->totalStock($ingredient->id);
    }

    /**
     * Valorización del inventario: SUM(stock × WAC de la bodega) por insumo.
     *
     * El WAC vive por bodega (`ingredient_stocks.current_cost`), así que el
     * valor se calcula por fila de stock y se agrega por insumo. El `cost`
     * mostrado es el WAC ponderado entre bodegas (value / stock).
     *
     * Si se pasa $warehouseId, solo agrega la bodega indicada. Sin filtro,
     * agrega todas las bodegas activas de la empresa.
     *
     * @return array{total: string, by_ingredient: list<array{id:string,name:string,stock:string,unit:string,cost:string,value:string}>}
     */
    public function valuation(string $companyNit, ?string $warehouseId = null): array
    {
        $query = DB::table('ingredients as i')
            ->join('ingredient_stocks as s', 's.ingredient_id', '=', 'i.id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('i.company_nit', $companyNit)
            ->whereNull('i.archived_at')
            ->whereNull('w.archived_at')
            ->select(
                'i.id',
                'i.name',
                'i.unit',
                DB::raw('SUM(s.quantity) as stock'),
                DB::raw('SUM(s.quantity * s.current_cost) as value'),
            )
            ->groupBy('i.id', 'i.name', 'i.unit')
            ->orderBy('i.name');

        if ($warehouseId !== null) {
            $query->where('s.warehouse_id', $warehouseId);
        }

        $rows = $query->get();

        $total = '0.00';
        $byIngredient = [];
        foreach ($rows as $row) {
            $stock = bcadd((string) $row->stock, '0', 3);
            $value = bcadd((string) $row->value, '0', 2);
            // WAC ponderado entre bodegas para mostrar (no para reportes — el
            // valor agregado ya sale de SUM(quantity * current_cost) en SQL).
            $cost = bccomp($stock, '0', 3) > 0 ? bcdiv($value, $stock, 2) : '0.00';
            $total = bcadd($total, $value, 2);
            $byIngredient[] = [
                // ingredients.id es uuid → cast string, no int.
                'id' => (string) $row->id,
                'name' => $row->name,
                'stock' => $stock,
                'unit' => $row->unit,
                'cost' => $cost,
                'value' => $value,
            ];
        }

        return [
            'total' => $total,
            'by_ingredient' => $byIngredient,
        ];
    }

    /**
     * Encuentra o crea (lockForUpdate) el ingredient_stock para (ingredient, warehouse).
     * `firstOrCreate` con quantity=0 evita carreras al insertar el primer movimiento.
     */
    private function lockOrCreateStock(string $ingredientId, string $warehouseId): IngredientStock
    {
        IngredientStock::query()->firstOrCreate(
            ['ingredient_id' => $ingredientId, 'warehouse_id' => $warehouseId],
            ['quantity' => 0, 'min_stock' => 0, 'updated_at' => now()],
        );

        return IngredientStock::query()
            ->where('ingredient_id', $ingredientId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Total agregado del insumo en todas sus bodegas activas (decimal:3).
     */
    private function totalStock(string $ingredientId): string
    {
        $sum = (string) DB::table('ingredient_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.ingredient_id', $ingredientId)
            ->whereNull('w.archived_at')
            ->sum('s.quantity');

        return bcadd($sum, '0', 3);
    }

    /**
     * (#costeo-multibodega) Resuelve la sede a registrar en el movimiento.
     *
     * Las bodegas son company-scoped (sin branch_id propio). El movimiento sí
     * conserva `branch_id` (atribución contable de la operación). Se usa la
     * sede provista por el caller si la bodega está asignada a ella; si no, la
     * sede de la asignación default/primera de la bodega. Lanza si la bodega no
     * está asignada a ninguna sede (no se puede atribuir el movimiento).
     */
    private function resolveMovementBranchId(Warehouse $warehouse, ?string $preferredBranchId): string
    {
        if ($preferredBranchId !== null
            && $warehouse->branchAssignments()->where('branch_id', $preferredBranchId)->exists()
        ) {
            return $preferredBranchId;
        }

        $assignment = $warehouse->branchAssignments()->orderByDesc('is_default')->first();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'La bodega no está asignada a ninguna sede; asígnala antes de registrar movimientos.',
            ]);
        }

        return $assignment->branch_id;
    }

    private function assertWarehouseBelongsToIngredientCompany(Ingredient $ingredient, Warehouse $warehouse): void
    {
        if ($warehouse->company_nit !== $ingredient->company_nit) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'La bodega no pertenece a la empresa del insumo.',
            ]);
        }
    }

    private function assertSignMatchesType(string $type, float $qty): void
    {
        $isPositive = $qty > 0;

        $expected = match ($type) {
            self::TYPE_ENTRY => true,
            self::TYPE_WASTE, self::TYPE_SALE_CONSUMPTION => false,
            default => null,
        };

        if ($expected === null) {
            return;
        }

        if ($expected !== $isPositive) {
            throw ValidationException::withMessages([
                'quantity' => sprintf(
                    'La cantidad debe ser %s para movimientos de tipo %s.',
                    $expected ? 'positiva' : 'negativa',
                    $type,
                ),
            ]);
        }
    }

    /**
     * Descuenta inventario por consumo de cocina a partir de las recetas activas
     * de cada ítem vendido. Idempotencia garantizada por el caller vía
     * `order.inventory_consumed_at`. Items sin receta se omiten con audit.
     * Nunca bloquea el flujo de venta por inventario desactualizado
     * (`allowNegativeStock=true`).
     *
     * @param  array<int, array<string, mixed>>  $items  snapshot de order.items[]
     * @return list<string> nombres de insumos que quedaron en stock negativo
     */
    public function consumeForOrder(Order $order, array $items, ?User $actor, string $referencePrefix): array
    {
        $negativeStockWarnings = [];
        $aggregated = [];
        foreach ($items as $line) {
            $itemId = $line['id'] ?? null;
            $qty = (int) ($line['quantity'] ?? 0);
            if (! $itemId || $qty <= 0) {
                continue;
            }
            $aggregated[$itemId] = ($aggregated[$itemId] ?? 0) + $qty;
        }

        if (empty($aggregated)) {
            return [];
        }

        $recipes = Recipe::withoutBranchScope()
            ->forCompany($order->company_nit)
            ->where('branch_id', $order->branch_id)
            ->active()
            ->whereIn('menu_item_id', array_keys($aggregated))
            ->with(['ingredient', 'warehouse'])
            ->get()
            ->groupBy('menu_item_id');

        $reference = $referencePrefix.':order='.$order->id;

        foreach ($aggregated as $itemId => $orderedQty) {
            $itemRecipes = $recipes->get($itemId);
            if (! $itemRecipes || $itemRecipes->isEmpty()) {
                $this->auditService->log('inventory.recipe.missing', $actor, $order, [
                    'order_id' => $order->id,
                    'menu_item_id' => $itemId,
                    'quantity' => $orderedQty,
                ]);

                continue;
            }

            foreach ($itemRecipes as $recipe) {
                /** @var Ingredient|null $ingredient */
                $ingredient = $recipe->ingredient;
                if (! $ingredient || $ingredient->archived_at !== null) {
                    $this->auditService->log('inventory.recipe.ingredient_unavailable', $actor, $order, [
                        'order_id' => $order->id,
                        'menu_item_id' => $itemId,
                        'recipe_id' => $recipe->id,
                        'ingredient_id' => $recipe->ingredient_id,
                    ]);

                    continue;
                }

                /** @var Warehouse|null $warehouse */
                $warehouse = $recipe->warehouse;
                if (! $warehouse || $warehouse->isArchived()) {
                    $this->auditService->log('inventory.recipe.warehouse_unavailable', $actor, $order, [
                        'order_id' => $order->id,
                        'menu_item_id' => $itemId,
                        'recipe_id' => $recipe->id,
                        'warehouse_id' => $recipe->warehouse_id,
                    ]);

                    continue;
                }

                $perUnit = UnitConverter::convert((string) $recipe->quantity, $recipe->unit, $ingredient->unit);
                $consumed = bcmul($perUnit, (string) $orderedQty, 3);
                if (bccomp($consumed, '0', 3) <= 0) {
                    continue;
                }

                $stockBefore = IngredientStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('quantity');
                $previousQuantity = $stockBefore !== null ? (string) $stockBefore : '0.000';

                $this->recordMovement(
                    ingredient: $ingredient,
                    warehouse: $warehouse,
                    type: self::TYPE_SALE_CONSUMPTION,
                    signedQuantity: '-'.$consumed,
                    unitCost: null,
                    reference: $reference,
                    actor: $actor,
                    allowNegativeStock: true,
                    branchId: $order->branch_id,
                );

                $newQuantity = bcsub($previousQuantity, $consumed, 3);
                if (bccomp($newQuantity, '0', 3) < 0) {
                    $this->auditService->log('inventory.sale_consumption.negative_stock', $actor, $order, [
                        'order_id' => $order->id,
                        'menu_item_id' => $itemId,
                        'ingredient_id' => $ingredient->id,
                        'warehouse_id' => $warehouse->id,
                        'previous_quantity' => $previousQuantity,
                        'consumed' => $consumed,
                        'new_quantity' => $newQuantity,
                    ]);
                    $negativeStockWarnings[] = $ingredient->name;
                }
            }
        }

        return $negativeStockWarnings;
    }

    private function weightedAverageCost(
        string $previousStock,
        string $previousCost,
        string $entryQty,
        string $entryUnitCost,
    ): string {
        if (bccomp($previousStock, '0', 3) <= 0) {
            return bcadd($entryUnitCost, '0', 2);
        }

        $previousValue = bcmul($previousStock, $previousCost, 6);
        $entryValue = bcmul($entryQty, $entryUnitCost, 6);
        $totalValue = bcadd($previousValue, $entryValue, 6);
        $totalQty = bcadd($previousStock, $entryQty, 6);

        if (bccomp($totalQty, '0', 6) <= 0) {
            return bcadd($entryUnitCost, '0', 2);
        }

        return bcdiv($totalValue, $totalQty, 2);
    }
}
