<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Nota crédito asociada a una PO ya `received|paid` que se anula.
 *
 * Append-only: el documento contable es inmutable — un error en una NC se
 * resuelve con OTRA nota o un ajuste de inventario explícito, jamás
 * actualizando la fila.
 *
 * @property int $id
 * @property string $company_nit
 * @property int $purchase_order_id
 * @property string $code
 * @property string $reason
 * @property array<int, array<string, mixed>> $items_snapshot
 * @property string $total_reversed
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class PurchaseCreditNote extends Model
{
    use BelongsToBranch, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'purchase_order_id',
        'code',
        'reason',
        'items_snapshot',
        'total_reversed',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'items_snapshot' => 'array',
            'total_reversed' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('PurchaseCreditNote is append-only — register another credit note or adjustment instead of updating.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('PurchaseCreditNote is append-only — DIAN requires retention.');
        });
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
