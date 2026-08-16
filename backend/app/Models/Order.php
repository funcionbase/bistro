<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pedido. Modelo canónico de estados definido en config/orders.php.
 *
 * Máquina de estados:
 *   pending → in_kitchen → ready → in_transit → completed
 * Terminales falla:
 *   failed (entrega fallida) | cancelled (sin pago) | refunded (con devolución) | abandoned (carrito)
 *
 * @property array{id: string, name: string, price: float, cost?: float|null, quantity: int, category: string, notes?: string}[] $items
 * @property string $status — ver config('orders.all')
 * @property string $company_nit — FK a companies.nit
 */
class Order extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * menu_item_id sintético de la línea "Domicilio" (no existe en el menú).
     * La inyecta el backend al crear órdenes delivery (caja y flujo público);
     * nunca puede venir del payload porque los ids se resuelven contra el
     * catálogo del menú activo.
     */
    public const DELIVERY_FEE_ITEM_ID = 'delivery_fee';

    /**
     * Inmutabilidad de `branch_id` post-creación.
     *
     * Si una orden cambia de sede después de creada, los reportes históricos,
     * los cierres de caja, los movimientos de inventario y las auditorías se
     * desincronizan instantáneamente — y no hay forma honesta de reconstruir
     * el estado contable previo. La sede de una orden es un dato origen, no
     * un atributo editable. Para "mover" una orden hay que cancelar la
     * original y crear una nueva — eso queda registrado contablemente.
     *
     * El guard se aplica en `updating` (no `saving`) para que la asignación
     * inicial en `creating` quede intacta; las inserciones masivas y los
     * jobs sin contexto HTTP (que dependen del fallback del trait) siguen
     * funcionando.
     */
    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('branch_id')) {
                throw new \LogicException(sprintf(
                    'orders.branch_id es inmutable post-creación (#192). Order id=%s, branch original=%s, intento=%s.',
                    $order->getKey() ?? 'null',
                    (string) $order->getOriginal('branch_id'),
                    (string) $order->branch_id,
                ));
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'client_uuid',
        'sync_warnings',
        'session_id',
        'client_phone',
        'contact_id',
        'items',
        'status',
        'order_type',
        'table_number',
        'table_session_id',
        'delivery_address',
        'total',
        'cost',
        'coupon_code',
        'discount_amount',
        'ordered_at',
        // Snapshot tributario al momento de crear la orden (inmutable):
        'subtotal',
        'tax_amount',
        'tax_rate',
        'snapshot_default_tax_rate',
        'tax_regime',
        'tax_included_in_price',
        'tip_amount',
        // Origen offline (caja offline-first): hora real de la venta en el
        // dispositivo (cuadre por esta hora) + flag de auditoría.
        'created_at_client',
        'is_offline_origin',
        // Snapshot del adquirente DIAN al momento de emitir.
        // Se congelan en la orden para que mutaciones futuras del Contact
        // del cliente no contaminen el documento emitido.
        'billing_doc_type',
        'billing_doc_number',
        'billing_dv',
        'billing_legal_name',
        'billing_email',
        'billing_phone',
        'billing_address',
        'billing_municipality_code',
        'billing_recipient_type',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total' => 'decimal:2',
            'cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'snapshot_default_tax_rate' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'tax_included_in_price' => 'boolean',
            'ordered_at' => 'datetime',
            'created_at_client' => 'datetime',
            'is_offline_origin' => 'boolean',
            'sync_warnings' => 'array',
        ];
    }

    /**
     * `client_phone` se guarda en el canónico `57XXXXXXXXXX` (con indicativo,
     * sin espacios ni `+`) sin importar cómo lo tipeó el cajero: sin esto se
     * almacenaba crudo (`+57 315 270 1319`), rompiendo el match con el Contact y
     * las notificaciones. Solo formato, no toca montos/estado. Vacío → null.
     */
    protected function clientPhone(): Attribute
    {
        return Attribute::set(function (?string $value): ?string {
            $canonical = PhoneNumber::toColombianCanonical($value);

            return $canonical === '' ? null : $canonical;
        });
    }

    /**
     * Código corto legible derivado del UUID: los dos primeros segmentos en
     * mayúscula (ej. `019E7DA6-3C13`). Identificador de referencia compacto para
     * mostrar al cliente (SMS) y en el tablero, sin exponer el UUID
     * completo. No es secuencial ni memorable, y como en UUIDv7 estos segmentos
     * codifican el timestamp, dos órdenes creadas en el mismo milisegundo
     * comparten código — es una referencia visual, no una clave única (el
     * consecutivo real por sede queda fuera de alcance). Helper único: el
     * frontend lo espeja en `lib/order-code.ts`.
     */
    public function shortCode(): string
    {
        $parts = explode('-', (string) $this->getKey());

        return strtoupper(implode('-', array_slice(array_filter([$parts[0] ?? '', $parts[1] ?? '']), 0, 2)));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /**
     * Cliente identificado de la orden. Nullable: walk-ins anónimos no tienen
     * Contact. `client_phone` queda como denormalizado de fallback cuando el
     * cliente no fue identificado al cobrar.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<PaymentReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    /** @return BelongsTo<CartSession, $this> — sesión de carta que originó la orden (F3). */
    public function cartSession(): BelongsTo
    {
        return $this->belongsTo(CartSession::class);
    }

    /** @return HasOne<Delivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /**
     * Items materializados de la orden. Convive con `items` JSON hasta
     * que todos los readers migren a esta relación.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /** @return HasMany<ElectronicDocument, $this> */
    public function electronicDocuments(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class);
    }

    /**
     * Sesión de mesa con QR a la que pertenece esta orden. Solo aplica
     * cuando la orden nace del flujo público `/t/{qr_token}`.
     *
     * @return BelongsTo<TableSession, $this>
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    // --- Operational lifecycle scopes ---

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeInKitchen(Builder $query): Builder
    {
        return $query->where('status', 'in_kitchen');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    // --- Terminal failure scopes ---

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeRefunded(Builder $query): Builder
    {
        return $query->where('status', 'refunded');
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where('status', 'abandoned');
    }

    // --- Metrics scopes ---

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    /** Active = órdenes operativas (config/orders.operational). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', config('orders.operational'));
    }

    /** Revenue = órdenes que cuentan como ingreso confirmado (config/orders.revenue). */
    public function scopeRevenue(Builder $query): Builder
    {
        return $query->whereIn('status', config('orders.revenue'));
    }

    public function scopeInPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('ordered_at', [$start, $end]);
    }
}
