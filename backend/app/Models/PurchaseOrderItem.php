<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Línea de orden de compra. Snapshot del nombre del ingrediente, costo neto,
 * impuesto desglosado y total de línea.
 *
 * Inmutable después de que la PO pase a `received|paid|voided|cancelled` —
 * para corregir post-recepción, generar PurchaseCreditNote.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $ingredient_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_cost
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $line_total
 */
class PurchaseOrderItem extends Model
{
    use BelongsToBranch, HasUuids;

    private const MUTABLE_STATUSES = ['draft'];

    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id',
        'ingredient_id',
        'warehouse_id',
        'description',
        'quantity',
        'unit_cost',
        'tax_rate',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * Bloquea UPDATE/DELETE cuando la PO ya no es editable. La PO en `draft`
     * permite ajustar líneas; cualquier otro estado las congela.
     */
    protected static function booted(): void
    {
        static::updating(function (PurchaseOrderItem $item): void {
            $status = $item->purchaseOrder?->status;
            if ($status !== null && ! in_array($status, self::MUTABLE_STATUSES, true)) {
                throw new RuntimeException("PurchaseOrderItem is immutable while parent PO is in status '{$status}'.");
            }
        });

        static::deleting(function (PurchaseOrderItem $item): void {
            $status = $item->purchaseOrder?->status;
            if ($status !== null && ! in_array($status, self::MUTABLE_STATUSES, true)) {
                throw new RuntimeException("PurchaseOrderItem cannot be deleted while parent PO is in status '{$status}'.");
            }
        });
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
