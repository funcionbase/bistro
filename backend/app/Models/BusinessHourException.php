<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\BusinessHourExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Excepción al horario comercial base para una fecha específica.
 *
 * Tiene precedencia sobre BusinessHour cuando la fecha del día coincide con exception_date.
 * is_open=false: cierra el establecimiento independientemente de open_time/close_time.
 * is_open=true con horas definidas: permite apertura fuera del horario habitual.
 *
 * @property bool $is_open — false = cerrado todo el día; true = abierto en la ventana open_time-close_time
 * @property string $exception_date — fecha de la excepción (Y-m-d)
 */
class BusinessHourException extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<BusinessHourExceptionFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'exception_date',
        'reason',
        'is_open',
        'open_time',
        'close_time',
    ];

    protected function casts(): array
    {
        return [
            'exception_date' => 'date:Y-m-d',
            'is_open' => 'boolean',
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
    public function scopeForDate(Builder $query, Carbon $date): void
    {
        $query->whereDate('exception_date', $date->toDateString());
    }

    /** @param Builder<static> $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('exception_date', '>=', now()->toDateString());
    }
}
