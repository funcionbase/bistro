<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de un CartSession (carrito de WhatsApp). Persiste lo que el cliente
 * ha agregado al carrito antes de confirmar la orden.
 *
 * @property int $cart_session_id
 * @property string $menu_item_id — id del item dentro del menu activo
 * @property float $price — precio unitario al momento de agregar (snapshot)
 * @property int $quantity
 */
class CartItem extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'cart_session_id',
        'menu_item_id',
        'name',
        'price',
        'quantity',
        'category',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<CartSession, $this> */
    public function cartSession(): BelongsTo
    {
        return $this->belongsTo(CartSession::class);
    }
}
