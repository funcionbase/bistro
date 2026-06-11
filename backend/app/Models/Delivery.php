<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Domicilio asignado a un repartidor para un pedido. Un pedido solo puede tener un domicilio activo.
 *
 * SoftDeletes: los domicilios eliminados se conservan para auditoría; no se muestran en listados.
 * duration_minutes se calcula al llamar markAsDelivered() (diffInMinutes entre assigned_at y delivered_at).
 * La reasignación cancela el domicilio actual y crea uno nuevo con previous_delivery_id apuntando al anterior.
 * Estados: pending | completed | cancelled.
 *
 * @property int|null $duration_minutes — calculado al completar, null hasta entonces
 * @property int|null $previous_delivery_id — FK al domicilio cancelado en una reasignación
 */
class Delivery extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, SoftDeletes;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'order_id',
        'user_id',
        'assigned_at',
        'delivered_at',
        'duration_minutes',
        'status',
        'previous_delivery_id',
        'reason',
        'cancellation_reason',
        'status_change_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    // --- Scopes ---

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeInPeriod(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('assigned_at', [$from, $to]);
    }

    // --- Relations ---

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Delivery, $this> */
    public function previousDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'previous_delivery_id');
    }

    /** @return HasMany<Delivery, $this> */
    public function reassignments(): HasMany
    {
        return $this->hasMany(Delivery::class, 'previous_delivery_id');
    }

    // --- Domain methods ---

    public function markAsDelivered(): void
    {
        $this->delivered_at = now();
        $this->duration_minutes = $this->calculateDuration();
        $this->status = 'completed';
        $this->save();
    }

    public function markAsCancelled(string $reason): void
    {
        $this->status = 'cancelled';
        $this->cancellation_reason = $reason;
        $this->save();
    }

    public function calculateDuration(): int
    {
        $from = $this->assigned_at ?? now();
        $to = $this->delivered_at ?? now();

        return (int) $from->diffInMinutes($to);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
