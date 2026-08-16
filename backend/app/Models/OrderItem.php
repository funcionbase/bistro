<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ítem materializado de una orden.
 *
 * FUENTE de líneas de la orden: todos los flujos de escritura (caja, QR,
 * sync offline, append) crean filas acá; `orders.items` JSON es una
 * proyección de lectura que `OrderTotalCalculator` reconstruye en cada
 * recálculo. Una orden puede tener N items con estado independiente (KDS y
 * pago dividido lo requieren).
 *
 * Reglas contables:
 *  - `unit_price` es snapshot del menú al momento de agregar — NUNCA leído
 *    del payload del cliente.
 *  - `paid_at` y `paid_receipt_id` marcan qué items ya están cubiertos por
 *    un `PaymentReceipt`. El saldo pendiente de la mesa se calcula en SQL:
 *    `SUM(unit_price * quantity) WHERE paid_at IS NULL AND status NOT IN ('cancelled')`.
 *  - Cancelaciones se rastrean por `cancellation_reason` ∈ config tables item_statuses.
 *
 * @property string $id
 * @property string $order_id
 * @property string $menu_item_id
 * @property int|null $guest_id
 * @property string $name
 * @property string $unit_price decimal:2
 * @property string|null $unit_cost decimal:2
 * @property string|null $tax_rate decimal:2 — tasa efectiva snapshoteada al crear la línea; null = usar orders.snapshot_default_tax_rate
 * @property int $quantity
 * @property string|null $category
 * @property string|null $notes
 * @property string $status
 * @property string|null $cancellation_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $in_kitchen_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $served_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $paid_at
 * @property string|null $paid_receipt_id
 * @property Carbon|null $refunded_at
 * @property string|null $refund_receipt_id
 */
class OrderItem extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'menu_item_id',
        'guest_id',
        'name',
        'unit_price',
        'unit_cost',
        'tax_rate',
        'quantity',
        'category',
        'notes',
        'status',
        'cancellation_reason',
        'submitted_at',
        'approved_at',
        'in_kitchen_at',
        'ready_at',
        'served_at',
        'cancelled_at',
        'paid_at',
        'paid_receipt_id',
        'refunded_at',
        'refund_receipt_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'quantity' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'in_kitchen_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<TableSessionGuest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(TableSessionGuest::class, 'guest_id');
    }

    /** @return BelongsTo<PaymentReceipt, $this> */
    public function paidReceipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'paid_receipt_id');
    }

    /** Subtotal del item (precio × cantidad). Solo cuenta si no está cancelled. */
    public function subtotal(): string
    {
        if ($this->status === 'cancelled') {
            return '0.00';
        }

        return number_format(
            round(((float) $this->unit_price) * $this->quantity, 2),
            2,
            '.',
            ''
        );
    }

    /** @param Builder<OrderItem> $query */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_approval');
    }

    /** @param Builder<OrderItem> $query */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** @param Builder<OrderItem> $query */
    public function scopeInKitchen(Builder $query): Builder
    {
        return $query->where('status', 'in_kitchen');
    }

    /** @param Builder<OrderItem> $query */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    /** @param Builder<OrderItem> $query */
    public function scopeServed(Builder $query): Builder
    {
        return $query->where('status', 'served');
    }

    /** @param Builder<OrderItem> $query */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    /** Items que aún cuentan al `orders.total` (excluye cancelados). */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /** Items consumidos por el comensal — base para cobrar. */
    public function scopeConsumable(Builder $query): Builder
    {
        return $query->whereIn('status', config('orders.item_statuses.consumable'));
    }

    /** Items aún no pagados. */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('paid_at');
    }

    /**
     * Cancela en bloque los items que sigan abiertos (todo lo que no esté
     * `served` ni `cancelled`) cuando la orden muere (cancelación de orden,
     * rechazo de domicilio, refund total). Los saca del KDS y deja
     * `order_items` consistente con la orden terminal. `$reason` debe estar
     * en la lista cerrada del CHECK `order_items_cancellation_reason_check`
     * (`system` = decisión automática, `refunded` = devolución total).
     */
    public static function cancelOpenItems(string $orderId, string $reason = 'system'): void
    {
        static::query()
            ->where('order_id', $orderId)
            ->whereNotIn('status', ['served', 'cancelled'])
            ->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);
    }

    /**
     * Marca como `served` los items que sigan abiertos en cocina (estados
     * `operational`) cuando la orden avanza a `in_transit`/`completed` —
     * salen del KDS sin alterar `orders.total` (todos los estados tocados
     * son consumables). No toca `pending_approval` ni `cancelled`.
     */
    public static function serveOpenItems(string $orderId): void
    {
        static::query()
            ->where('order_id', $orderId)
            ->whereIn('status', (array) config('orders.item_statuses.operational'))
            ->update([
                'status' => 'served',
                'served_at' => now(),
            ]);
    }
}
