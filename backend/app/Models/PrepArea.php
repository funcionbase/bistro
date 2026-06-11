<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Área de preparación dentro de una sede. Cada sede define las suyas (cocina,
 * barra, panadería, repostería, plancha, freidora, etc.).
 *
 * Las pantallas KDS (`kds_stations`) y los menu items se filtran por estas
 * áreas. Por ahora `menu_items` referencia el slug del área en el JSON de
 * `restaurant_menus.items[].prep_area_id` para mantener el MVP simple — si en el
 * futuro se necesita reporting cruzado, se normalizará a tabla aparte.
 *
 * @property string $id — uuid
 * @property string $branch_id
 * @property string $slug — único por sede (ej. 'kitchen', 'bar')
 * @property string $label
 * @property string $color — hex
 * @property ?string $icon_key
 * @property int $display_order
 * @property ?Carbon $archived_at
 */
class PrepArea extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'slug',
        'label',
        'color',
        'icon_key',
        'display_order',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<PrepArea>  $query */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<PrepArea>  $query */
    public function scopeOrdered($query): void
    {
        $query->orderBy('display_order')->orderBy('label');
    }
}
