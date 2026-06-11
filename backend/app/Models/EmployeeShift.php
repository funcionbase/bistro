<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Turno asignado a un colaborador en una sede.
 *
 * starts_at/ends_at son timestamps completos: soportan turnos partidos
 * (varias filas mismo día) y cruce de medianoche (ends_at > starts_at
 * pero en día calendario distinto). Soft-cancel mantiene fila para
 * trazabilidad de horas planificadas vs. ejecutadas.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $branch_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status — scheduled | cancelled
 * @property ?string $cancellation_reason — sick | personal | emergency | vinculation_state | other
 * @property ?string $cancellation_note
 * @property ?int $cancelled_by_user_id
 * @property ?Carbon $cancelled_at
 * @property ?int $created_by_user_id
 */
class EmployeeShift extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'branch_id',
        'starts_at',
        'ends_at',
        'status',
        'cancellation_reason',
        'cancellation_note',
        'cancelled_by_user_id',
        'cancelled_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @param  Builder<EmployeeShift>  $query */
    public function scopeScheduled($query): void
    {
        $query->where('status', 'scheduled');
    }

    /** @param  Builder<EmployeeShift>  $query */
    public function scopeBetween($query, Carbon $from, Carbon $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
