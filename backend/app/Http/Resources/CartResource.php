<?php

namespace App\Http\Resources;

use App\Models\CartSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado completo del carrito visto por el cliente publico.
 *
 * @mixin CartSession
 */
class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->items->map(fn ($item) => [
            'id' => $item->id,
            'menu_item_id' => $item->menu_item_id,
            'name' => $item->name,
            'price' => (float) $item->price,
            'quantity' => $item->quantity,
            'category' => $item->category,
            'notes' => $item->notes,
            'subtotal' => (float) $item->price * $item->quantity,
        ]);

        return [
            'jti' => $this->jwt_jti,
            'company_nit' => $this->company_nit,
            'client_phone' => $this->client_phone,
            'status' => $this->status,
            'expired_at' => $this->expired_at?->toIso8601String(),
            'items' => $items,
            'subtotal' => (float) $items->sum('subtotal'),
        ];
    }
}
