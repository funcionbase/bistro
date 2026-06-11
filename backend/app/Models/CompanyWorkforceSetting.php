<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración 1:1 con companies para el módulo de jornada laboral.
 *
 *  - max_weekly_hours: jornada máxima semanal (default 48h ley vigente,
 *    anticipa ley 2101 → 42h ajustable sin migración).
 *  - min_days_off_per_week: descanso mínimo configurable.
 *  - hours_warning_mode: warn | block | off; controla qué pasa cuando
 *    una asignación supera el límite.
 *
 * @property string $company_nit
 * @property int $max_weekly_hours
 * @property int $min_days_off_per_week
 * @property string $hours_warning_mode
 */
class CompanyWorkforceSetting extends Model
{
    protected $primaryKey = 'company_nit';

    /** @var string */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'max_weekly_hours',
        'min_days_off_per_week',
        'hours_warning_mode',
    ];

    protected function casts(): array
    {
        return [
            'max_weekly_hours' => 'integer',
            'min_days_off_per_week' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }
}
