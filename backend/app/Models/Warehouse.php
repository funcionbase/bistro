<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bodega: recurso de inventario de la EMPRESA, asignable a N sedes.
 *
 * (#costeo-multibodega) Antes la bodega pertenecía a una sede
 * (`warehouses.branch_id`). Ahora es company-scoped y la relación sede↔bodega
 * vive en el pivot `branch_warehouses` (modelo {@see BranchWarehouse}). Una
 * misma bodega física puede servir a varias sedes; cada sede declara cuál de
 * sus bodegas asignadas es la default.
 *
 * Reglas:
 *  - `slug` único por empresa (`company_nit, slug`).
 *  - Una sola bodega `is_default=true` por sede — invariante en el pivot
 *    (índice único parcial `branch_warehouses_one_default_per_branch`).
 *  - `archived_at` es soft-archive — la bodega queda inactiva pero sus
 *    movimientos y stocks históricos se preservan.
 *  - Una bodega no puede archivarse si tiene `quantity > 0` en algún
 *    `ingredient_stocks` activo (validación en controller/service).
 *  - Transferencias cross-bodega permitidas dentro de la misma empresa
 *    (las bodegas ya no están ligadas a una sede).
 *
 * @property string $id — uuid
 * @property string $company_nit
 * @property string $name
 * @property string $slug — único por (company_nit)
 * @property string $type — main|kitchen|bar|cold_storage|dry_storage
 * @property bool $is_default — informativo a nivel empresa; la default operativa es por sede (pivot)
 * @property ?Carbon $archived_at
 */
class Warehouse extends Model
{
    use HasUuids;

    public const TYPE_MAIN = 'main';

    public const TYPE_KITCHEN = 'kitchen';

    public const TYPE_BAR = 'bar';

    public const TYPE_COLD_STORAGE = 'cold_storage';

    public const TYPE_DRY_STORAGE = 'dry_storage';

    public const VALID_TYPES = [
        self::TYPE_MAIN,
        self::TYPE_KITCHEN,
        self::TYPE_BAR,
        self::TYPE_COLD_STORAGE,
        self::TYPE_DRY_STORAGE,
    ];

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'slug',
        'type',
        'is_default',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /**
     * Sedes a las que está asignada esta bodega (vía pivot branch_warehouses).
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_warehouses', 'warehouse_id', 'branch_id')
            ->withPivot(['id', 'company_nit', 'is_default'])
            ->withTimestamps();
    }

    /** @return HasMany<BranchWarehouse, $this> */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchWarehouse::class, 'warehouse_id', 'id');
    }

    /** @return HasMany<IngredientStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class, 'warehouse_id');
    }

    /** @return HasMany<IngredientMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(IngredientMovement::class, 'warehouse_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Bodega default operativa de una sede (vía pivot). Devuelve la marcada
     * `is_default=true` y activa; si no hay default, la primera bodega activa
     * asignada (orden por nombre). null si la sede no tiene ninguna bodega
     * activa asignada.
     */
    public static function defaultForBranch(string $branchId): ?self
    {
        return self::query()
            ->whereNull('warehouses.archived_at')
            ->join('branch_warehouses', 'branch_warehouses.warehouse_id', '=', 'warehouses.id')
            ->where('branch_warehouses.branch_id', $branchId)
            ->orderByDesc('branch_warehouses.is_default')
            ->orderBy('warehouses.name')
            ->select('warehouses.*')
            ->first();
    }

    /**
     * Garantiza que la sede tenga una bodega default y la devuelve, aplicando
     * la regla D3 de auto-asignación:
     *
     *  1. Si la sede ya tiene una bodega activa asignada → la default.
     *  2. Si no, y la empresa tiene **exactamente una** bodega activa → se le
     *     asigna esa bodega como default de la sede.
     *  3. Si no, y la empresa **no tiene ninguna** bodega → siembra una
     *     "Bodega principal" company-scoped y la asigna como default (bootstrap
     *     de empresa nueva / onboarding).
     *  4. Si la empresa tiene 2+ bodegas y la sede no tiene ninguna asignada →
     *     devuelve null: la asignación es manual (bloqueo duro
     *     BRANCH_HAS_NO_WAREHOUSE en runtime).
     *
     * Idempotente y N-instance safe: el insert del pivot colisiona en el unique
     * (branch_id, warehouse_id) ante carreras; el seed usa firstOrCreate sobre
     * el slug único por empresa.
     */
    public static function ensureDefaultForBranch(string $companyNit, string $branchId): ?self
    {
        $existing = self::defaultForBranch($branchId);
        if ($existing !== null) {
            return $existing;
        }

        $activeWarehouses = self::query()
            ->where('company_nit', $companyNit)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        // Caso 2: exactamente una bodega → auto-asignar como default.
        if ($activeWarehouses->count() === 1) {
            $warehouse = $activeWarehouses->first();
            self::assignToBranch($companyNit, $branchId, $warehouse->id, isDefault: true);

            return $warehouse;
        }

        // Caso 3: empresa sin bodegas → bootstrap de la primera.
        if ($activeWarehouses->isEmpty()) {
            $warehouse = self::query()->firstOrCreate(
                ['company_nit' => $companyNit, 'slug' => 'principal'],
                ['name' => 'Bodega principal', 'type' => self::TYPE_MAIN, 'is_default' => true],
            );

            if ($warehouse->archived_at !== null) {
                $warehouse->forceFill(['archived_at' => null])->save();
            }

            self::assignToBranch($companyNit, $branchId, $warehouse->id, isDefault: true);

            return $warehouse;
        }

        // Caso 4: 2+ bodegas y sede sin asignación → manual.
        return null;
    }

    /**
     * Asigna una bodega a una sede en el pivot (idempotente). Si `isDefault`,
     * desmarca cualquier otra default de esa sede y marca esta. Devuelve el id
     * de la fila de pivot.
     */
    public static function assignToBranch(string $companyNit, string $branchId, string $warehouseId, bool $isDefault = false): string
    {
        return DB::transaction(function () use ($companyNit, $branchId, $warehouseId, $isDefault): string {
            if ($isDefault) {
                BranchWarehouse::query()
                    ->where('branch_id', $branchId)
                    ->where('warehouse_id', '!=', $warehouseId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $pivot = BranchWarehouse::query()
                ->where('branch_id', $branchId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if ($pivot === null) {
                $pivot = BranchWarehouse::create([
                    'id' => (string) Str::uuid7(),
                    'company_nit' => $companyNit,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'is_default' => $isDefault,
                ]);
            } elseif ($isDefault && ! $pivot->is_default) {
                $pivot->forceFill(['is_default' => true])->save();
            }

            return $pivot->id;
        });
    }

    /** @param  Builder<Warehouse>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Bodegas activas asignadas a una sede (vía pivot).
     *
     * @param  Builder<Warehouse>  $query
     */
    public function scopeForBranch(Builder $query, string $branchId): Builder
    {
        return $query->whereHas('branchAssignments', fn (Builder $q) => $q->where('branch_id', $branchId));
    }
}
