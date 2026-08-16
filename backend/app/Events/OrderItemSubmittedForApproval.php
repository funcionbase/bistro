<?php

namespace App\Events;

use App\Models\OrderItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado cuando un `OrderItem` pasa a `status = 'pending_approval'` con
 * `submitted_at` recién seteado (es decir, un comensal de mesa-QR envió un
 * pedido nuevo que requiere aprobación de mesero/cajero).
 *
 * Listener principal: `App\Listeners\NotifyPendingApprovalListener`
 * que encola `SendPendingApprovalPushJob` para notificar a los usuarios
 * con permiso `orders.update` activo en la sede de la orden.
 */
class OrderItemSubmittedForApproval
{
    use Dispatchable, SerializesModels;

    public function __construct(public OrderItem $item) {}
}
