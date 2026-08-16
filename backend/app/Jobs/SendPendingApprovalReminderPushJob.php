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
use Illuminate\Support\Carbon;

/**
 * Recordatorio push para items con `status='pending_approval'` y
 * `submitted_at` antiguo.
 *
 * Disparado por `notifications:remind-pending-approvals` (cron each minute).
 * Reutiliza el mismo tag que el push inicial para que el OS lo reemplace
 * en lugar de apilarlo — el usuario ve "Mesa 4 espera aprobación (8 min)"
 * actualizándose, no notificaciones nuevas cada minuto.
 *
 * **N-instance safety (CLAUDE.md §12)**: este job corre vía
 * `QUEUE_CONNECTION=database` (Laravel database queue sobre postgres —
 * stack canónico, no Redis/SQS). El cron que lo despacha usa
 * `onOneServer()` + cache lock per-item; los múltiples workers EC2 nunca
 * reciben el mismo `orderItemId` porque `SELECT ... FOR UPDATE SKIP LOCKED`
 * del driver `database` garantiza exclusividad.
 *
 * El cuerpo del payload incluye los minutos transcurridos para comunicar
 * urgencia escalada.
 */
class SendPendingApprovalReminderPushJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 2;

    /** @var int */
    public $backoff = 30;

    public function __construct(public string $orderItemId)
    {
        $this->onQueue('notifications');
    }

    public function handle(WebPushDispatcher $dispatcher, AuditService $audit): void
    {
        if (! $dispatcher->isConfigured()) {
            return;
        }

        $item = OrderItem::query()->with('order')->find($this->orderItemId);
        if ($item === null || $item->order === null) {
            return;
        }

        if ($item->status !== 'pending_approval' || $item->submitted_at === null) {
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

        $minutes = (int) max(1, $item->submitted_at->diffInMinutes(Carbon::now()));

        $payload = [
            'title' => $this->buildTitle($order, $minutes),
            'body' => "Hace {$minutes} min sin atender",
            'url' => '/orders?focus=pending&order='.$order->id,
            'tag' => WebPushDispatcher::pendingApprovalTag($order->id),
            'data' => [
                'type' => 'pending_approval_reminder',
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'minutes_pending' => $minutes,
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
                        'type' => 'pending_approval_reminder',
                        'target_user_id' => $user->id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'minutes_pending' => $minutes,
                        'payload_tag' => $payload['tag'],
                    ],
                );
            }
        }
    }

    private function buildTitle(Order $order, int $minutes): string
    {
        $tableNumber = $order->table_number ?? null;
        if (! empty($tableNumber)) {
            return "Mesa {$tableNumber} sigue esperando ({$minutes} min)";
        }

        return "Orden #{$order->id} sigue pendiente ({$minutes} min)";
    }
}
