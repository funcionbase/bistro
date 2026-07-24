<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca como `abandoned` los pedidos públicos (QR de sede, sin sesión de
 * mesa) que llevan más de `config('orders.abandon_after_hours')` en
 * `pending_approval` sin que el staff los aprobara. Alimenta la métrica de
 * carritos perdidos (los reportes ya cuentan `abandoned` — antes ningún
 * código lo asignaba y siempre daba 0) y evita órdenes zombis. Cancela sus
 * items con `cancellation_reason=system`.
 *
 * Los buffers de sesión de mesa NO se tocan: su ciclo de vida lo maneja la
 * sesión (`tables:purge-expired-sessions` / `closeEmpty`) y marcarlos
 * inflaría la métrica con tandas ya aprobadas.
 */
class MarkAbandonedOrdersCommand extends Command
{
    protected $signature = 'orders:mark-abandoned {--batch=200 : Máximo de órdenes por ejecución}';

    protected $description = 'Marca como abandoned los pedidos públicos pending_approval vencidos';

    public function handle(AuditService $audit): int
    {
        $cutoff = now()->subHours((int) config('orders.abandon_after_hours', 24));
        $batch = max(1, (int) $this->option('batch'));

        // Scope escape explícito: cron sin contexto HTTP (mismo criterio que
        // tables:purge-expired-sessions).
        $candidates = Order::withoutGlobalScopes()
            ->where('status', 'pending_approval')
            ->whereNull('table_session_id')
            ->where('ordered_at', '<', $cutoff)
            ->orderBy('ordered_at')
            ->limit($batch)
            ->get(['id']);

        $count = 0;
        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate, $audit, &$count) {
                /** @var Order|null $locked */
                $locked = Order::withoutGlobalScopes()->whereKey($candidate->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== 'pending_approval') {
                    return;
                }

                $locked->status = 'abandoned';
                $locked->save();

                OrderItem::cancelOpenItems($locked->id);

                $audit->log('order.abandoned', user: null, auditable: $locked, data: [
                    'order_id' => $locked->id,
                    'order_type' => $locked->order_type,
                    'ordered_at' => $locked->ordered_at?->toIso8601String(),
                    'total' => (string) $locked->total,
                ]);

                $count++;
            });
        }

        $this->info("Órdenes marcadas como abandoned: {$count}");

        return self::SUCCESS;
    }
}
