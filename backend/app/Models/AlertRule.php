<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Regla de alerta de margen/costos.
 *
 * En v1 hay UNA fila por (company_nit, type) — sin reglas custom libres. El
 * AlertSeedService garantiza que las 4 reglas (margin_below, cost_increase,
 * item_low_volume, low_stock) existan para cada empresa con defaults
 * razonables; el dueño las activa/desactiva y ajusta threshold/period_days
 * desde /company/preferences.
 *
 * threshold se almacena como decimal(12,4) para soportar tanto porcentajes
 * (0.30 = 30%) como valores absolutos (días, % de incremento) — cada
 * evaluator interpreta el valor según su semántica.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $type
 * @property string $threshold
 * @property int $period_days
 * @property bool $enabled
 * @property bool $notify_dashboard
 * @property bool $notify_whatsapp
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AlertRule extends Model
{
    use HasUuids;

    public const TYPE_MARGIN_BELOW = 'margin_below';

    public const TYPE_COST_INCREASE = 'cost_increase';

    public const TYPE_ITEM_LOW_VOLUME = 'item_low_volume';

    public const TYPE_LOW_STOCK = 'low_stock';

    public const TYPES = [
        self::TYPE_MARGIN_BELOW,
        self::TYPE_COST_INCREASE,
        self::TYPE_ITEM_LOW_VOLUME,
        self::TYPE_LOW_STOCK,
    ];

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'type',
        'threshold',
        'period_days',
        'enabled',
        'notify_dashboard',
        'notify_whatsapp',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:4',
            'period_days' => 'integer',
            'enabled' => 'boolean',
            'notify_dashboard' => 'boolean',
            'notify_whatsapp' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
