<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Table;
use App\Models\TableSession;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Marca como `expired` las sesiones de mesa con QR que excedieron su
 * `expires_at` sin pago ni cierre manual.
 *
 * Se programa cada 5 min en `routes/console.php` con `onOneServer()` y
 * `withoutOverlapping()`. Es N-instance safe: el cache lock asegura que
 * solo una instancia del ASG procese el batch por tick.
 *
 * No borra registros — soft-close. Los receipts, items y notes asociados
 * se preservan (regla contable: conservación 5-10 años). Un comando
 * separado podrá podar (`DELETE FROM table_sessions WHERE status IN
 * ('closed','expired') AND closed_at < NOW() - 90 days`) pero NO se
 * propaga a receipts.
 */
class PurgeExpiredTableSessionsCommand extends Command
{
    /** @var string */
    protected $signature = 'tables:purge-expired-sessions
        {--dry-run : Lista sin escribir.}
        {--batch=200 : Tamaño del batch por ejecución.}';

    /** @var string */
    protected $description = 'Marca como expired las sesiones de mesa con QR que pasaron su expires_at sin actividad.';

    public function handle(AuditService $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $batch = max(1, (int) $this->option('batch'));

        $now = Carbon::now();

        // NO expirar sesiones con consumo pendiente de pago: `expires_at` solo
        // se renueva con acciones del comensal en su celular (addItem/submit),
        // así que una mesa comiendo tranquila supera el umbral sin estar
        // abandonada. Expirarla liberaba la mesa (otro cliente podía abrir
        // sesión encima), expulsaba a los comensales del menú QR y sacaba la
        // cuenta del panel "mesas por cobrar" de caja (que solo lista sesiones
        // open/locked) dejando órdenes sin pagar que bloqueaban el cierre.
        // Esas sesiones las cierra caja al cobrar (closeSession) o el mesero.
        $terminalFailure = (array) config('orders.terminal_failure');
        $consumableStatuses = (array) config('orders.item_statuses.consumable');

        $candidates = TableSession::query()
            ->whereIn('status', ['open', 'locked'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->whereNotExists(function ($query) use ($terminalFailure, $consumableStatuses) {
                $query->selectRaw('1')
                    ->from('orders')
                    ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                    ->whereColumn('orders.table_session_id', 'table_sessions.id')
                    ->where('orders.status', '!=', 'pending_approval')
                    ->whereNotIn('orders.status', $terminalFailure)
                    ->whereIn('order_items.status', $consumableStatuses)
                    ->whereNull('order_items.paid_at');
            })
            ->limit($batch)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Sin sesiones expiradas.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($candidates as $session) {
            if ($dry) {
                $this->line("  → sería expirada: session#{$session->id} (table_id={$session->table_id}, expires_at={$session->expires_at})");
                $count++;

                continue;
            }

            $session->status = 'expired';
            $session->closed_at = $now;
            $session->save();

            // Scope escape justificado (#192): cron sin contexto HTTP — el
            // BranchScope ni siquiera aplicaría, pero hacemos explícito el
            // escape para que ningún seeder/middleware introducido en el
            // futuro filtre por sede inadvertidamente. El filtro por
            // `table_id` ya garantiza precisión.
            Table::withoutBranchScope()
                ->whereKey($session->table_id)
                ->update(['status' => 'available']);

            $audit->log(
                action: 'table.session.expired',
                user: null,
                auditable: $session,
                data: [
                    'table_id' => $session->table_id,
                    'branch_id' => $session->branch_id,
                    'company_nit' => $session->company_nit,
                    'expires_at' => optional($session->expires_at)?->toIso8601String(),
                ],
            );

            $count++;
        }

        $this->info(sprintf(
            'Sesiones %s: %d%s',
            $dry ? 'que serían marcadas' : 'marcadas como expired',
            $count,
            $dry ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}
