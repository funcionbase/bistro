<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendPendingApprovalReminderPushJob;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cron de recordatorios de pending approvals (#149 CA5).
 *
 * Ejecutado por `routes/console.php` cada minuto con `onOneServer()` +
 * `withoutOverlapping(5)` (CLAUDE.md §12).
 *
 * **N-instance safety (CLAUDE.md §12)**: triple defensa contra duplicados
 * en ASG con N instancias EC2:
 *  1. `->onOneServer()` en el Schedule → solo UNA instancia EC2 corre el
 *     comando por tick (lock cross-instance via cache store compartido).
 *  2. `->withoutOverlapping(5)` → si el comando se atasca >1 min, evita
 *     ejecuciones superpuestas en el mismo nodo.
 *  3. `Cache::lock(push.reminder.order_item.{id}, throttle_minutes*60)`
 *     per-item → si por error 2 instancias llegaran al mismo item (no
 *     debería ocurrir con #1), el lock evita encolar 2 jobs.
 *
 * REQUIERE: `CACHE_STORE=database` (postgres con tablas `cache` y
 * `cache_locks`). `file`/`array` rompen la coordinación cross-instance.
 * Stack canónico — el proyecto NO usa Redis/DynamoDB.
 *
 * REQUIERE: `QUEUE_CONNECTION=database`. Los jobs encolados desde acá son
 * procesados por workers en EC2; el driver `database` toma cada job vía
 * `SELECT ... FOR UPDATE SKIP LOCKED` del registro en `jobs`, así uno
 * sólo lo procesa.
 *
 * Lógica:
 *  - Trae `OrderItem` con `status='pending_approval'` y `submitted_at` >
 *    `reminder_after_minutes` (config, default 5).
 *  - Por cada item, intenta tomar un `Cache::lock` per-item con TTL
 *    `reminder_throttle_minutes` (default 5). Si el lock está, salta —
 *    es la dedup del recordatorio.
 *  - Si el lock se obtiene, dispatcha `SendPendingApprovalReminderPushJob`
 *    a la cola `notifications`.
 *
 * El comando NO envía push directo — solo encola. El job hace el trabajo
 * real y se beneficia del retry policy de la cola.
 */
class RemindPendingApprovalsCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:remind-pending-approvals';

    /** @var string */
    protected $description = 'Encola recordatorios push para items pending_approval antiguos (#149).';

    public function handle(): int
    {
        $cooldownMinutes = (int) config('notifications.dispatch.pending_approval_reminder_after_minutes', 5);
        $throttleMinutes = (int) config('notifications.dispatch.pending_approval_reminder_throttle_minutes', 5);

        $cutoff = Carbon::now()->subMinutes($cooldownMinutes);

        $items = OrderItem::query()
            ->where('status', 'pending_approval')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', $cutoff)
            ->orderBy('submitted_at')
            ->limit(200)
            ->get();

        $dispatched = 0;
        $throttled = 0;

        foreach ($items as $item) {
            $lock = Cache::lock("push.reminder.order_item.{$item->id}", $throttleMinutes * 60);
            if (! $lock->get()) {
                $throttled++;

                continue;
            }

            SendPendingApprovalReminderPushJob::dispatch($item->id);
            $dispatched++;
        }

        $this->info("Recordatorios encolados: {$dispatched} · throttled: {$throttled} · candidatos: {$items->count()}");

        return self::SUCCESS;
    }
}
