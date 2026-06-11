<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\BusinessHourFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario comercial base por día de la semana para una empresa.
 *
 * day_of_week: 0 = domingo, 6 = sábado (convención de Carbon/JS Date).
 * IMPORTANTE: difiere de la convención ISO 8601 (0=lunes) usada en otros contextos del proyecto.
 * Las BusinessHourException del día tienen precedencia sobre este horario base.
 * Si is_enabled=false, el día se considera cerrado independientemente de open_time/close_time.
 *
 * @property int $day_of_week — 0 (domingo) a 6 (sábado)
 * @property string|null $open_time — HH:MM:SS
 * @property string|null $close_time — HH:MM:SS
 */
class BusinessHour extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<BusinessHourFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'day_of_week',
        'open_time',
        'close_time',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @param Builder<static> $query */
    public function scopeForCompany(Builder $query, string $companyNit): void
    {
        $query->where('company_nit', $companyNit);
    }

    /** @param Builder<static> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /** @param Builder<static> $query */
    public function scopeForDay(Builder $query, int $dayOfWeek): void
    {
        $query->where('day_of_week', $dayOfWeek);
    }
}
