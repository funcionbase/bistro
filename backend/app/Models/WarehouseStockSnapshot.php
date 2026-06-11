<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Snapshot diario del stock por (warehouse, ingredient) para series temporales.
 *
 * Poblado por `inventory:snapshot-daily` (cron 03:30) o por lazy backfill al
 * primer query del día desde WarehouseStockHistoryService.
 *
 * Append-only: no se actualiza una fila — se hace `INSERT … ON CONFLICT DO
 * UPDATE` cuando se regenera idempotentemente. `line_value = quantity * unit_cost`
 * para evitar recalcular en reportes.
 *
 * Patrón alineado con `menu_item_cost_history`.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $branch_id — uuid
 * @property string $warehouse_id — uuid
 * @property int $ingredient_id
 * @property Carbon $snapshot_date
 * @property string $quantity — decimal:3
 * @property string $unit_cost — decimal:2 (WAC del insumo al momento)
 * @property string $line_value — decimal:2
 * @property Carbon $created_at
 */
class WarehouseStockSnapshot extends Model
{
    use BelongsToBranch, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'warehouse_id',
        'ingredient_id',
        'snapshot_date',
        'quantity',
        'unit_cost',
        'line_value',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_value' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @param  Builder<WarehouseStockSnapshot>  $query */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()]);
    }
}
