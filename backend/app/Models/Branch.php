<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sede física de una empresa. Una empresa (NIT) puede tener N sedes.
 *
 * @property string $id — uuid
 * @property string $company_nit
 * @property string $name
 * @property string $slug — único por empresa
 * @property ?string $address
 * @property ?string $city
 * @property bool $is_default — informativo
 * @property ?string $business_type_id — slug del vertical (FK business_types.slug)
 * @property ?array<string, bool> $capabilities_override — sobreescritura puntual sobre default_capabilities del vertical
 * @property ?Carbon $archived_at — soft archive
 */
class Branch extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'slug',
        'address',
        'city',
        'business_type_id',
        'capabilities_override',
        'is_default',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'capabilities_override' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<BusinessType, $this> */
    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id', 'slug');
    }

    /** @return HasMany<PrepArea, $this> */
    public function prepAreas(): HasMany
    {
        return $this->hasMany(PrepArea::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_users', 'branch_id', 'user_id')
            ->withPivot(['granted_by_user_id', 'granted_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'branch_id', 'id');
    }

    /**
     * Bodegas asignadas a esta sede (vía pivot branch_warehouses).
     * (#costeo-multibodega) Una bodega es company-scoped y puede servir N sedes.
     *
     * @return BelongsToMany<Warehouse, $this>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouses', 'branch_id', 'warehouse_id')
            ->withPivot(['id', 'company_nit', 'is_default'])
            ->withTimestamps();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Sede por defecto de una empresa para flujos sin contexto de sede explícito
     * (menú público sin ?branch_id, bots, crons). Prioriza is_default y luego
     * orden alfabético; solo sedes activas (no archivadas). null si la empresa
     * no tiene sedes activas.
     */
    public static function resolveDefault(string $companyNit): ?self
    {
        return static::query()
            ->where('company_nit', $companyNit)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /** @param  Builder<Branch>  $query */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }
}
