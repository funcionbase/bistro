<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\WebPushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía notificación push a los usuarios con permiso `orders.update` activo
 * en la sede de la orden, anunciando un item pendiente de aprobación
 * (#149 CA5 — evento `OrderItemSubmittedForApproval`).
 *
 * El job recibe sólo el `orderItemId` para que su serialización a la cola
 * sea barata. La resolución de la orden, mesa, suscripciones y permisos
 * ocurre en `handle()` con datos frescos.
 *
 * Idempotencia: el `tag` de la notificación colapsa duplicados a nivel OS
 * (si el usuario tiene 2 dispositivos y se envían simultáneo, ve 2; si se
 * envían en secuencia para el mismo order, el segundo reemplaza al primero).
 *
 * Errores: el dispatcher loguea cada falla individualmente. Si TODOS los
 * envíos fallan (sin sub activas), el job termina exitoso porque no hay
 * nada que reintentar.
 */
class SendPendingApprovalPushJob implements ShouldQueue
{
    use Queueable;

    /** @var string */
    public $queue = 'notifications';

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $backoff = 30;

    public function __construct(public string $orderItemId) {}

    public function handle(WebPushDispatcher $dispatcher, AuditService $audit): void
    {
        if (! $dispatcher->isConfigured()) {
            return;
        }

        $item = OrderItem::query()->with('order')->find($this->orderItemId);
        if ($item === null || $item->order === null) {
            return;
        }

        if ($item->status !== 'pending_approval') {
            return;
        }

        $order = $item->order;
        $companyNit = $order->company_nit;
        $branchId = $order->branch_id;

        $subs = PushSubscription::query()
            ->active()
            ->where('company_nit', $companyNit)
            ->with('user')
            ->get();

        if ($subs->isEmpty()) {
            return;
        }

        $payload = [
            'title' => $this->buildTitle($order),
            'body' => $this->buildBody($order),
            'url' => '/orders?focus=pending&order='.$order->id,
            'tag' => WebPushDispatcher::pendingApprovalTag($order->id),
            'data' => [
                'type' => 'pending_approval',
                'order_id' => $order->id,
                'order_item_id' => $item->id,
            ],
        ];

        foreach ($subs as $sub) {
            $user = $sub->user;
            if (! $user instanceof User) {
                continue;
            }

            if (! WebPushDispatcher::userCanReceiveOrderUpdate($user, $companyNit, $branchId)) {
                continue;
            }

            $sent = $dispatcher->send($sub, $payload);
            if ($sent) {
                $audit->log(
                    'notifications.pushed',
                    user: $user,
                    auditable: $sub,
                    data: [
                        'type' => 'pending_approval',
                        'target_user_id' => $user->id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'payload_tag' => $payload['tag'],
                    ],
                );
            }
        }
    }

    private function buildTitle(Order $order): string
    {
        $tableNumber = $order->table_number ?? null;
        if (! empty($tableNumber)) {
            return "Mesa {$tableNumber} espera aprobación";
        }

        return "Orden #{$order->id} espera aprobación";
    }

    private function buildBody(Order $order): string
    {
        $pendingCount = $order->items()->where('status', 'pending_approval')->count();
        $word = $pendingCount === 1 ? 'plato' : 'platos';

        return "{$pendingCount} {$word} pendiente·s de aprobar";
    }
}
