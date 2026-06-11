<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Estación de cocina (#115).
 *
 * Una empresa tiene N sedes; cada sede tiene M estaciones (caliente / fría /
 * barra / fritos por defecto). Las categorías del menú se mapean a
 * estaciones mediante el campo `kds_station_id` dentro de
 * `restaurant_menus.structure.categories[]`.
 *
 * Aislamiento:
 *  - BranchScope global filtra por sede activa del request.
 *  - `company_nit` se hereda del request via JWT (no fillable directo desde HTTP).
 *
 * Soft-archive: `archived_at` no rompe relaciones con tokens existentes; el
 * UI exige revocar tokens antes de archivar.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $branch_id — uuid
 * @property string $slug
 * @property string $name
 * @property string $color — formato #RRGGBB
 * @property int $sla_warn_minutes
 * @property int $sla_alert_minutes
 * @property bool $is_default
 * @property ?Carbon $archived_at
 */
class KdsStation extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'slug',
        'name',
        'color',
        'sla_warn_minutes',
        'sla_alert_minutes',
        'is_default',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sla_warn_minutes' => 'integer',
            'sla_alert_minutes' => 'integer',
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<KdsDeviceToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(KdsDeviceToken::class, 'station_id', 'id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<KdsStation>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Definición canónica de estaciones default por sede (#115).
     *
     * Lista de tuplas (slug, name, color, sla_warn, sla_alert, is_default).
     * El primer item con is_default=true es el fallback para categorías de
     * menú sin `kds_station_id` mapeado.
     *
     * @return list<array{slug:string,name:string,color:string,sla_warn:int,sla_alert:int,is_default:bool}>
     */
    public static function defaultDefinitions(): array
    {
        return [
            ['slug' => 'caliente', 'name' => 'Caliente', 'color' => '#EF4444', 'sla_warn' => 8, 'sla_alert' => 15, 'is_default' => true],
            ['slug' => 'fria', 'name' => 'Fría', 'color' => '#22C55E', 'sla_warn' => 5, 'sla_alert' => 10, 'is_default' => false],
            ['slug' => 'barra', 'name' => 'Barra', 'color' => '#3B82F6', 'sla_warn' => 4, 'sla_alert' => 8, 'is_default' => false],
            ['slug' => 'fritos', 'name' => 'Fritos', 'color' => '#F59E0B', 'sla_warn' => 6, 'sla_alert' => 12, 'is_default' => false],
        ];
    }

    /**
     * Siembra las 4 estaciones canónicas para una sede recién creada.
     *
     * Idempotente: usa `firstOrCreate` por `(company_nit, branch_id, slug)`.
     * Llamado desde:
     *  - `CompanyEnrollmentController` (sede inicial de la empresa).
     *  - `BranchController::store` (sedes adicionales).
     *  - `RestauranteFlexySeeder` y `KdsStationSeeder` (demo + onboarding QA).
     *
     * Se invoca DENTRO de la misma `DB::transaction` del caller — si falla la
     * creación de la sede, las estaciones se revierten automáticamente.
     */
    public static function seedDefaultsForBranch(string $companyNit, string $branchId): void
    {
        foreach (self::defaultDefinitions() as $def) {
            static::query()->firstOrCreate(
                [
                    'company_nit' => $companyNit,
                    'branch_id' => $branchId,
                    'slug' => $def['slug'],
                ],
                [
                    'name' => $def['name'],
                    'color' => $def['color'],
                    'sla_warn_minutes' => $def['sla_warn'],
                    'sla_alert_minutes' => $def['sla_alert'],
                    'is_default' => $def['is_default'],
                ],
            );
        }
    }
}
