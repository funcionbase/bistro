<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot sede↔bodega (#costeo-multibodega).
 *
 * Una bodega es un recurso de empresa asignable a N sedes. Esta tabla es la
 * fuente de verdad de la relación y de cuál bodega es la default de cada sede
 * (la que reciben recetas/compras sin bodega explícita).
 *
 * Reglas:
 *  - Unique (branch_id, warehouse_id): sin asignaciones duplicadas.
 *  - Índice único parcial: una sola fila `is_default=true` por sede.
 *
 * @property string $id — uuid
 * @property string $company_nit
 * @property string $branch_id — uuid
 * @property string $warehouse_id — uuid
 * @property bool $is_default
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BranchWarehouse extends Model
{
    use HasUuids;

    protected $table = 'branch_warehouses';

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'warehouse_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }
}
