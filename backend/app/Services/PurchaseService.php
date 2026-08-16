<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Inventory\BranchHasNoWarehouseException;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\PurchaseCreditNote;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierIngredient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Lógica transaccional del módulo de compras a proveedores.
 *
 * Reglas críticas:
 *  - Toda mutación de PO corre dentro de DB::transaction + lockForUpdate sobre
 *    la cabecera (evita doble-recepción / doble-pago concurrente).
 *  - Las transiciones se validan contra `config('purchases.transitions')` —
 *    fuente única de verdad.
 *  - Cálculo de totales SIEMPRE con bcmath (decimal:2 / decimal:3); jamás float.
 *  - `receive()` delega en InventoryService::recordMovement(ENTRY) usando el
 *    costo unitario GROSS (incl. impuesto) — alimenta el WAC de la bodega de
 *    destino (`ingredient_stocks.current_cost`) con lo efectivamente pagado.
 *  - `voidWithCreditNote()` reversa inventario con `adjustment` negativo al
 *    WAC CORRIENTE de la bodega de la recepción (no al original), porque el
 *    costo pudo haber evolucionado entre la recepción y la anulación.
 *  - Si el stock actual no alcanza para reversar una línea, se BLOQUEA la
 *    anulación: el operador debe ajustar manualmente el inventario primero.
 */
class PurchaseService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_VOIDED = 'voided';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Genera el siguiente código consecutivo por empresa: PO-000001, PO-000002…
     * Robusto frente a códigos manuales no numéricos: ignora los que no parsean.
     */
    public function nextCode(string $companyNit, ?string $prefix = null): string
    {
        $prefix = $prefix ?? (string) config('purchases.code_prefix', 'PO-');

        $last = PurchaseOrder::forCompany($companyNit)
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $next = 1;
        if ($last !== null) {
            $tail = (int) preg_replace('/\D/', '', substr($last, strlen($prefix)));
            if ($tail > 0) {
                $next = $tail + 1;
            }
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function nextCreditNoteCode(string $companyNit): string
    {
        $prefix = (string) config('purchases.credit_note_prefix', 'NC-');

        $last = PurchaseCreditNote::query()
            ->where('company_nit', $companyNit)
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $next = 1;
        if ($last !== null) {
            $tail = (int) preg_replace('/\D/', '', substr($last, strlen($prefix)));
            if ($tail > 0) {
                $next = $tail + 1;
            }
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Crea una PO en estado `draft` con sus líneas. Cada línea exige:
     *   - ingredient_id válido y de la misma empresa.
     *   - quantity > 0, unit_cost >= 0, tax_rate >= 0.
     *
     * @param  array<int, array{ingredient_id:int, quantity:string|float, unit_cost:string|float, tax_rate?:string|float}>  $items
     * @param  array{expected_date?:string|null, notes?:string|null}  $meta
     */
    public function createDraft(
        string $companyNit,
        Supplier $supplier,
        array $items,
        array $meta,
        ?User $actor,
    ): PurchaseOrder {
        if ($supplier->company_nit !== $companyNit) {
            throw ValidationException::withMessages(['supplier_id' => 'Proveedor no pertenece a la empresa activa.']);
        }
        if ($supplier->archived_at !== null) {
            throw ValidationException::withMessages(['supplier_id' => 'Proveedor archivado — restáuralo antes de comprar.']);
        }
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'La orden debe tener al menos una línea.']);
        }

        return DB::transaction(function () use ($companyNit, $supplier, $items, $meta, $actor) {
            $po = PurchaseOrder::create([
                'company_nit' => $companyNit,
                // Multi-sede: la PO hereda la sede del proveedor (los proveedores
                // viven por sede). Si en el futuro se decide proveedores cross-sede,
                // este origen debe revisarse.
                'branch_id' => $supplier->branch_id,
                'supplier_id' => $supplier->id,
                'code' => $this->nextCode($companyNit),
                'status' => self::STATUS_DRAFT,
                'expected_date' => $meta['expected_date'] ?? null,
                'subtotal' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
                'notes' => $meta['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->replaceItems($po, $items, $companyNit);

            $this->auditService->log('purchases.po.created', $actor, $po, [
                'code' => $po->code,
                'supplier_id' => $supplier->id,
                'total' => (string) $po->total,
                'items_count' => count($items),
            ]);

            return $po->fresh(['items', 'supplier']);
        });
    }

    /**
     * Reemplaza líneas + actualiza meta de una PO `draft`. Cualquier otro
     * estado se rechaza (las líneas son inmutables tras `pending`).
     *
     * @param  array<int, array{ingredient_id:int, quantity:string|float, unit_cost:string|float, tax_rate?:string|float}>|null  $items
     * @param  array{expected_date?:string|null, notes?:string|null}  $meta
     */
    public function updateDraft(PurchaseOrder $po, ?array $items, array $meta, ?User $actor): PurchaseOrder
    {
        if ($po->status !== self::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => "Solo se pueden editar órdenes en estado 'draft'."]);
        }

        return DB::transaction(function () use ($po, $items, $meta, $actor) {
            $fresh = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('expected_date', $meta)) {
                $fresh->expected_date = $meta['expected_date'];
            }
            if (array_key_exists('notes', $meta)) {
                $fresh->notes = $meta['notes'];
            }
            $fresh->save();

            if ($items !== null) {
                $this->replaceItems($fresh, $items, $fresh->company_nit);
            }

            $this->auditService->log('purchases.po.updated', $actor, $fresh, [
                'code' => $fresh->code,
                'total' => (string) $fresh->total,
            ]);

            return $fresh->fresh(['items', 'supplier']);
        });
    }

    /** draft → pending */
    public function submit(PurchaseOrder $po, ?User $actor): PurchaseOrder
    {
        return $this->transition($po, self::STATUS_PENDING, function (PurchaseOrder $fresh) use ($actor) {
            if ($fresh->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'No se puede confirmar una orden sin líneas.']);
            }
            $fresh->status = self::STATUS_PENDING;
            $fresh->save();

            $this->auditService->log('purchases.po.submitted', $actor, $fresh, ['code' => $fresh->code]);

            return $fresh;
        });
    }

    /**
     * pending → received. Por cada línea, registra `IngredientMovement` tipo
     * ENTRY usando el costo unitario GROSS (incl. impuesto), alimentando WAC.
     * Actualiza `supplier_ingredients.last_unit_cost` con el NETO.
     */
    public function receive(PurchaseOrder $po, ?User $actor): PurchaseOrder
    {
        return $this->transition($po, self::STATUS_RECEIVED, function (PurchaseOrder $fresh) use ($actor) {
            $items = $fresh->items()->with(['ingredient', 'warehouse'])->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'No se puede recibir una orden sin líneas.']);
            }

            foreach ($items as $item) {
                $ingredient = $item->ingredient;
                if ($ingredient === null) {
                    throw ValidationException::withMessages(['items' => "Línea {$item->id} sin insumo asociado."]);
                }

                $warehouse = $item->warehouse;
                if ($warehouse === null || $warehouse->isArchived()) {
                    throw ValidationException::withMessages([
                        'items' => "Línea {$item->id}: bodega de destino archivada o no encontrada.",
                    ]);
                }

                $grossUnitCost = $this->grossUnitCost($item);

                $this->inventory->recordMovement(
                    ingredient: $ingredient,
                    warehouse: $warehouse,
                    type: InventoryService::TYPE_ENTRY,
                    signedQuantity: (string) $item->quantity,
                    unitCost: $grossUnitCost,
                    reference: $fresh->code,
                    actor: $actor,
                    branchId: $fresh->branch_id,
                );

                SupplierIngredient::updateOrCreate(
                    ['supplier_id' => $fresh->supplier_id, 'ingredient_id' => $ingredient->id],
                    [
                        'branch_id' => $fresh->branch_id,
                        'last_unit_cost' => (string) $item->unit_cost,
                        'last_purchased_at' => now(),
                    ],
                );
            }

            $fresh->status = self::STATUS_RECEIVED;
            $fresh->received_date = now();
            $fresh->received_by = $actor?->id;
            $fresh->save();

            $this->auditService->log('purchases.po.received', $actor, $fresh, [
                'code' => $fresh->code,
                'lines' => $items->count(),
                'total' => (string) $fresh->total,
            ]);

            return $fresh;
        });
    }

    /** received → paid */
    public function markPaid(PurchaseOrder $po, string $method, ?string $reference, ?User $actor): PurchaseOrder
    {
        if (! in_array($method, (array) config('purchases.payment_methods'), true)) {
            throw ValidationException::withMessages(['payment_method' => 'Método de pago inválido.']);
        }
        if ($method !== 'cash' && empty($reference)) {
            throw ValidationException::withMessages(['payment_reference' => 'La referencia es obligatoria para pagos con tarjeta o transferencia.']);
        }

        return $this->transition($po, self::STATUS_PAID, function (PurchaseOrder $fresh) use ($method, $reference, $actor) {
            $fresh->status = self::STATUS_PAID;
            $fresh->payment_method = $method;
            $fresh->payment_reference = $reference;
            $fresh->paid_date = now();
            $fresh->paid_by = $actor?->id;
            $fresh->save();

            $this->auditService->log('purchases.po.paid', $actor, $fresh, [
                'code' => $fresh->code,
                'method' => $method,
                'reference' => $reference,
                'total' => (string) $fresh->total,
            ]);

            return $fresh;
        });
    }

    /** draft|pending → cancelled (sin afectar inventario) */
    public function cancel(PurchaseOrder $po, ?string $reason, ?User $actor): PurchaseOrder
    {
        return $this->transition($po, self::STATUS_CANCELLED, function (PurchaseOrder $fresh) use ($reason, $actor) {
            $fresh->status = self::STATUS_CANCELLED;
            $fresh->save();

            $this->auditService->log('purchases.po.cancelled', $actor, $fresh, [
                'code' => $fresh->code,
                'reason' => $reason,
            ]);

            return $fresh;
        });
    }

    /**
     * received|paid → voided. Crea PurchaseCreditNote, genera adjustments
     * negativos en inventario al COSTO CORRIENTE del insumo. Si la PO estaba
     * `paid`, levanta `pending_supplier_refund` (UI alerta hasta registrar
     * reintegro manualmente).
     */
    public function voidWithCreditNote(PurchaseOrder $po, string $reason, ?User $actor): PurchaseOrder
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo de la nota crédito es obligatorio.']);
        }

        return $this->transition($po, self::STATUS_VOIDED, function (PurchaseOrder $fresh) use ($reason, $actor) {
            $items = $fresh->items()->with(['ingredient', 'warehouse'])->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'No se puede anular una orden sin líneas.']);
            }

            $wasPaid = $fresh->status === self::STATUS_PAID;
            $code = $this->nextCreditNoteCode($fresh->company_nit);

            // Pre-validación: bloquear si alguna línea dejaría stock negativo
            // EN LA BODEGA donde se recibió originalmente (cada item tiene
            // su warehouse_id). El reverso afecta sólo esa bodega.
            $snapshot = [];
            $totalReversed = '0.00';
            foreach ($items as $item) {
                $ingredient = Ingredient::whereKey($item->ingredient_id)->lockForUpdate()->firstOrFail();

                $currentBodegaStock = (string) (IngredientStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('warehouse_id', $item->warehouse_id)
                    ->value('quantity') ?? '0.000');

                $newStock = bcsub($currentBodegaStock, (string) $item->quantity, 3);
                if (bccomp($newStock, '0', 3) < 0) {
                    throw ValidationException::withMessages([
                        'items' => "Existencias insuficientes del insumo '{$ingredient->name}' en la bodega de la recepción (actual: {$currentBodegaStock}, requerido: {$item->quantity}). Ajusta el inventario o transfiere existencias antes de anular.",
                    ]);
                }

                // WAC por bodega (#costeo-multibodega): el reverso se valora al
                // costo CORRIENTE de la bodega donde entró la línea, no a un
                // costo global del insumo (que ya no existe).
                $reversalCost = (string) (IngredientStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('warehouse_id', $item->warehouse_id)
                    ->value('current_cost') ?? '0.00');
                $lineValue = bcmul((string) $item->quantity, $reversalCost, 2);
                $totalReversed = bcadd($totalReversed, $lineValue, 2);

                $snapshot[] = [
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'warehouse_id' => $item->warehouse_id,
                    'quantity' => (string) $item->quantity,
                    'reversal_unit_cost' => $reversalCost,
                    'line_value' => $lineValue,
                ];
            }

            // Crear NC primero (referencia para los adjustments).
            $note = PurchaseCreditNote::create([
                'company_nit' => $fresh->company_nit,
                'branch_id' => $fresh->branch_id,
                'purchase_order_id' => $fresh->id,
                'code' => $code,
                'reason' => $reason,
                'items_snapshot' => $snapshot,
                'total_reversed' => $totalReversed,
                'created_by' => $actor?->id,
                'created_at' => now(),
            ]);

            // Reverso de inventario línea a línea, contra la MISMA bodega donde
            // la línea entró originalmente (cada PurchaseOrderItem.warehouse_id).
            foreach ($items as $item) {
                $ingredient = Ingredient::whereKey($item->ingredient_id)->firstOrFail();
                $warehouse = $item->warehouse;
                if ($warehouse === null || $warehouse->isArchived()) {
                    throw ValidationException::withMessages([
                        'items' => "Línea {$item->id}: bodega de la recepción original archivada o no encontrada. Restaúrala o transfiere el stock antes de anular.",
                    ]);
                }
                $this->inventory->recordMovement(
                    ingredient: $ingredient,
                    warehouse: $warehouse,
                    type: InventoryService::TYPE_ADJUSTMENT,
                    signedQuantity: '-'.((string) $item->quantity),
                    unitCost: null,
                    reference: $code.' (anulación '.$fresh->code.')',
                    actor: $actor,
                    branchId: $fresh->branch_id,
                );
            }

            $fresh->status = self::STATUS_VOIDED;
            $fresh->voided_at = now();
            $fresh->voided_by = $actor?->id;
            if ($wasPaid) {
                $fresh->pending_supplier_refund = true;
            }
            $fresh->save();

            $this->auditService->log('purchases.po.voided', $actor, $fresh, [
                'code' => $fresh->code,
                'credit_note_code' => $code,
                'credit_note_id' => $note->id,
                'reason' => $reason,
                'total_reversed' => $totalReversed,
                'pending_supplier_refund' => $fresh->pending_supplier_refund,
            ]);

            return $fresh;
        });
    }

    /**
     * Marca como saldada la devolución del proveedor (cuando se anuló una PO
     * `paid`). No registra el ingreso de caja — queda al módulo de cash.
     */
    public function settleSupplierRefund(PurchaseOrder $po, ?string $reference, ?User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reference, $actor) {
            $fresh = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->pending_supplier_refund) {
                throw ValidationException::withMessages(['status' => 'La orden no tiene un reintegro pendiente.']);
            }

            $fresh->pending_supplier_refund = false;
            $fresh->save();

            $this->auditService->log('purchases.po.refund_settled', $actor, $fresh, [
                'code' => $fresh->code,
                'reference' => $reference,
            ]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------------

    /**
     * Envuelve una transición de estado: lock + validación contra el mapa
     * `config('purchases.transitions')` + ejecución del callback.
     *
     * @param  callable(PurchaseOrder): PurchaseOrder  $apply
     */
    private function transition(PurchaseOrder $po, string $target, callable $apply): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $target, $apply) {
            $fresh = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            $allowed = (array) (config('purchases.transitions')[$fresh->status] ?? []);
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "Transición no permitida: '{$fresh->status}' → '{$target}'.",
                ]);
            }

            return $apply($fresh)->fresh(['items', 'supplier']);
        });
    }

    /**
     * Recalcula líneas (delete + insert dentro de transaction) y persiste
     * subtotal/tax/total en la cabecera. Usa bcmath para todas las cuentas.
     *
     * Cada línea lleva `warehouse_id` que indica la bodega de destino al
     * recibir la compra. Si no se provee, se usa la bodega default
     * de la sede de la PO.
     *
     * @param  array<int, array{ingredient_id:int, warehouse_id?:string, quantity:string|float, unit_cost:string|float, tax_rate?:string|float}>  $items
     */
    private function replaceItems(PurchaseOrder $po, array $items, string $companyNit): void
    {
        if ($po->status !== self::STATUS_DRAFT) {
            throw new RuntimeException('replaceItems only allowed on draft PO.');
        }

        DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->delete();

        $defaultWarehouseId = $this->defaultWarehouseId($companyNit, $po->branch_id);

        $subtotal = '0.00';
        $taxTotal = '0.00';
        $grandTotal = '0.00';

        foreach ($items as $idx => $raw) {
            $ingredient = Ingredient::whereKey($raw['ingredient_id'])->first();
            if ($ingredient === null || $ingredient->company_nit !== $companyNit) {
                throw ValidationException::withMessages(["items.{$idx}.ingredient_id" => 'Insumo no pertenece a la empresa.']);
            }
            if ($ingredient->archived_at !== null) {
                throw ValidationException::withMessages(["items.{$idx}.ingredient_id" => "Insumo '{$ingredient->name}' está archivado."]);
            }

            $qty = (string) $raw['quantity'];
            $unitCost = (string) $raw['unit_cost'];
            $taxRate = (string) ($raw['tax_rate'] ?? '0');

            if ((float) $qty <= 0) {
                throw ValidationException::withMessages(["items.{$idx}.quantity" => 'La cantidad debe ser mayor a cero.']);
            }
            if ((float) $unitCost < 0) {
                throw ValidationException::withMessages(["items.{$idx}.unit_cost" => 'El costo unitario no puede ser negativo.']);
            }
            if ((float) $taxRate < 0) {
                throw ValidationException::withMessages(["items.{$idx}.tax_rate" => 'La tasa de impuesto no puede ser negativa.']);
            }

            $warehouseId = (string) ($raw['warehouse_id'] ?? $defaultWarehouseId);
            $this->assertWarehouseAssignedToBranch($companyNit, $po->branch_id, $warehouseId, $idx);

            $lineSubtotal = bcmul($qty, $unitCost, 2);
            $lineTax = bcdiv(bcmul($lineSubtotal, $taxRate, 4), '100', 2);
            $lineTotal = bcadd($lineSubtotal, $lineTax, 2);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'branch_id' => $po->branch_id,
                'ingredient_id' => $ingredient->id,
                'warehouse_id' => $warehouseId,
                'description' => $ingredient->name,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ]);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $taxTotal = bcadd($taxTotal, $lineTax, 2);
            $grandTotal = bcadd($grandTotal, $lineTotal, 2);
        }

        $po->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $taxTotal,
            'total' => $grandTotal,
        ])->save();
    }

    /**
     * Costo unitario GROSS (con impuesto incluido) para alimentar el WAC.
     * gross = line_total / quantity. Truncado a 2 decimales — coincide con
     * lo persistido en `purchase_order_items.line_total`.
     */
    private function grossUnitCost(PurchaseOrderItem $item): string
    {
        $qty = (string) $item->quantity;
        if (bccomp($qty, '0', 3) <= 0) {
            return '0.00';
        }

        return bcdiv((string) $item->line_total, $qty, 2);
    }

    /**
     * Devuelve el warehouse_id default de la sede dada (vía pivot
     * branch_warehouses). Bloqueo duro BRANCH_HAS_NO_WAREHOUSE si la sede no
     * tiene bodega asignada — recibir compras sin bodega destino no es válido
     * (#costeo-multibodega).
     */
    private function defaultWarehouseId(string $companyNit, string $branchId): string
    {
        $warehouse = DB::table('warehouses as w')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->where('w.company_nit', $companyNit)
            ->where('bw.branch_id', $branchId)
            ->whereNull('w.archived_at')
            ->orderByDesc('bw.is_default')
            ->orderBy('w.name')
            ->first(['w.id']);

        if ($warehouse === null) {
            throw new BranchHasNoWarehouseException($branchId);
        }

        return (string) $warehouse->id;
    }

    /**
     * (#costeo-multibodega) La bodega es company-scoped; valida que esté
     * asignada a la sede de la orden (pivot branch_warehouses).
     */
    private function assertWarehouseAssignedToBranch(string $companyNit, string $branchId, string $warehouseId, int $idx): void
    {
        $exists = DB::table('warehouses as w')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->where('w.company_nit', $companyNit)
            ->where('bw.branch_id', $branchId)
            ->where('w.id', $warehouseId)
            ->whereNull('w.archived_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                "items.{$idx}.warehouse_id" => 'La bodega no existe o no está asignada a la sede de la orden.',
            ]);
        }
    }
}
