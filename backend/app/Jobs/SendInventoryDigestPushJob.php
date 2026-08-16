<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AlertEvent;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\WebPushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Digest de inventario al primer login del día.
 *
 * Disparado desde `AuthController::selectCompany` cuando:
 *   - El user no recibió digest hoy (cache key `push.inventory.sent.{userId}.{date}`).
 *   - Hay `alert_events` del día activos (`low_stock` / `cost_increase` /
 *     `margin_below` / `item_low_volume`) en la empresa seleccionada.
 *   - El user tiene permiso `reports.read` o `inventory.read`.
 *
 * **N-instance safety (CLAUDE.md §12)**: el caller (AuthController) usa
 * `Cache::add(key, value, ttl)` (atomic) para garantizar one-shot por
 * (user, día) incluso si dos EC2 reciben llamadas concurrentes. El job
 * mismo no usa locks — confía en la atomicidad del cache::add upstream.
 *
 * Una vez enviado, el caller setea la cache key con TTL hasta medianoche —
 * el job NO la setea (single responsibility: dispara y reporta).
 *
 * Si no hay alert_events del día, no se manda nada y no se setea cache.
 */
class SendInventoryDigestPushJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 2;

    /** @var int */
    public $backoff = 60;

    public function __construct(
        public string $userId,
        public string $companyNit,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(WebPushDispatcher $dispatcher, AuditService $audit): void
    {
        if (! $dispatcher->isConfigured()) {
            return;
        }

        if (! (bool) config('notifications.inventory_digest.enabled', true)) {
            return;
        }

        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        if (! WebPushDispatcher::userCanReceiveInventoryDigest($user, $this->companyNit)) {
            return;
        }

        $today = Carbon::today();
        $events = AlertEvent::query()
            ->where('company_nit', $this->companyNit)
            ->whereDate('triggered_at', $today)
            ->whereNull('dismissed_at')
            ->whereIn('type', ['low_stock', 'cost_increase', 'margin_below', 'item_low_volume'])
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $subs = PushSubscription::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('company_nit', $this->companyNit)
            ->get();

        if ($subs->isEmpty()) {
            return;
        }

        $count = $events->count();
        $word = $count === 1 ? 'alerta' : 'alertas';
        $isoDate = $today->toDateString();

        $payload = [
            'title' => 'Alertas de inventario',
            'body' => "{$count} {$word} para revisar",
            'url' => '/dashboard?focus=alerts',
            'tag' => WebPushDispatcher::inventoryDigestTag($isoDate),
            'data' => [
                'type' => 'inventory_digest',
                'count' => $count,
                'date' => $isoDate,
            ],
        ];

        foreach ($subs as $sub) {
            $sent = $dispatcher->send($sub, $payload);
            if ($sent) {
                $audit->log(
                    'notifications.pushed',
                    user: $user,
                    auditable: $sub,
                    data: [
                        'type' => 'inventory_digest',
                        'target_user_id' => $user->id,
                        'alerts_count' => $count,
                        'payload_tag' => $payload['tag'],
                    ],
                );
            }
        }
    }
}
