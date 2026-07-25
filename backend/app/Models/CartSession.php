<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Database\Factories\CartSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesión de carrito de compra del bot de WhatsApp. Sin SoftDeletes; sin updated_at.
 *
 * jwt_jti: identificador único de la sesión (UUID del CartJwt). Tiene índice único en BD.
 * Una sesión abandonada se usa para calcular la tasa de abandono de carrito.
 * El campo created_at se usa como timestamp de inicio de sesión para métricas de período.
 *
 * @property string $jwt_jti — UUID único de la sesión de carrito (del claim jti del CartJWT,
 *                           o token público del link corto /menus?cart={uuid} enviado desde /chats)
 * @property string $status — active | abandoned | converted
 * @property ?string $chat_id — chat que originó el link (para precargar el pedido en la conversación)
 * @property ?string $order_id — orden creada al convertir la sesión
 */
class CartSession extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<CartSessionFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'jwt_jti',
        'company_nit',
        'branch_id',
        'client_phone',
        'status',
        'expired_at',
        'chat_id',
        'order_id',
        'viewed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'created_at' => 'datetime',
            'viewed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Todas las órdenes creadas desde esta sesión de carta (multi-orden F3).
     * `order_id` conserva solo la última convertida.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where('status', 'abandoned');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeInPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
