<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Sesión grupal abierta sobre una mesa física.
 *
 * Una mesa puede tener UNA sola sesión activa (status ∈ open|locked) a la vez;
 * el constraint vive en BD como partial unique index. Mientras está `open`
 * acepta nuevos comensales; al aprobar la primera tanda pasa a `locked` y la
 * mesa se ve como `occupied`. Al pagarse todo cierra (`closed`); si pasa el
 * umbral de inactividad sin pago, el job programado la marca `expired`.
 *
 * @property int $id
 * @property int $table_id
 * @property string $company_nit
 * @property string $branch_id
 * @property Carbon $opened_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $closed_at
 * @property string $status
 * @property bool $accepts_new_guests
 */
class TableSession extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'table_id',
        'company_nit',
        'branch_id',
        'opened_at',
        'expires_at',
        'closed_at',
        'status',
        'accepts_new_guests',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'accepts_new_guests' => 'boolean',
        ];
    }

    /** @return BelongsTo<Table, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** @return HasMany<TableSessionGuest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(TableSessionGuest::class);
    }

    /**
     * Orden buffer de la sesión — la que recibe items recién agregados o
     * submitidos por el comensal y espera aprobación del mesero. Hay
     * MÁXIMO una buffer activa por sesión (status=pending_approval).
     *
     * Al aprobar una tanda, sus items se mueven a una orden NUEVA
     * (status=pending). Ver `orders()` para listar todas.
     *
     * @return HasOne<Order, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class)->where('status', 'pending_approval');
    }

    /**
     * TODAS las órdenes de la sesión: la buffer (si existe) + las órdenes
     * derivadas (una por tanda aprobada por el mesero).
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    public function isActive(): bool
    {
        return in_array($this->status, config('tables.active_statuses'), true);
    }

    /** @param Builder<TableSession> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', config('tables.active_statuses'));
    }

    /** @param Builder<TableSession> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** @param Builder<TableSession> $query */
    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('status', 'locked');
    }
}
