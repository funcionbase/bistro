<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Catálogo cerrado de verticales de negocio (restaurant, bakery, cafe, etc.).
 *
 * Cada vertical define:
 *   - default_capabilities: flags booleanos que indican qué módulos del producto
 *     están habilitados por defecto cuando una sede usa este vertical. La sede
 *     puede sobreescribir flags concretos vía `branches.capabilities_override`.
 *   - prep_area_defaults: lista de áreas de preparación sembradas al crear una
 *     sede con este vertical (ej. cocina + barra para `restaurant`).
 *
 * @property string $slug — PK, también usado como FK desde branches.business_type_id
 * @property string $label_es
 * @property string $label_en
 * @property ?string $icon_key — lucide icon name
 * @property array<string, bool> $default_capabilities
 * @property array<int, array{slug: string, label: string, color?: string, icon_key?: ?string}> $prep_area_defaults
 * @property int $display_order
 * @property ?Carbon $archived_at
 */
class BusinessType extends Model
{
    protected $table = 'business_types';

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'label_es',
        'label_en',
        'icon_key',
        'default_capabilities',
        'prep_area_defaults',
        'display_order',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'default_capabilities' => 'array',
            'prep_area_defaults' => 'array',
            'display_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'business_type_id', 'slug');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<BusinessType>  $query */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<BusinessType>  $query */
    public function scopeOrdered($query): void
    {
        $query->orderBy('display_order')->orderBy('slug');
    }
}
