<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmitDianDocumentJob;
use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Reintenta/recupera documentos DIAN con backoff exponencial (el job
 * EmitDianDocumentJob es ShouldBeUnique + tries=6 con backoff
 * `[60, 180, 300, 900, 1800, 3600]`).
 *
 * Estados elegibles:
 *  - `error`: reintento normal (backoff por `last_retry_at`).
 *  - `pending`/`sent` STALE: recuperación de atascados — el proceso murió antes
 *    de procesar la respuesta del provider (`pending`) o el webhook async que
 *    debía resolver el doc nunca llegó (`sent`). Solo se recogen si llevan más
 *    de `dian.stuck_recovery_minutes` sin tocarse, para no pisar emisiones en
 *    vuelo ni webhooks que aún van a llegar. La re-submisión reusa el mismo
 *    consecutivo (idempotente por CUFE/CUDE) — ver DianDispatchService::emit.
 */
class DianDispatchPendingCommand extends Command
{
    protected $signature = 'dian:dispatch-pending {--max=50 : Máximo de documentos por corrida}';

    protected $description = 'Reintenta documentos DIAN en error y recupera atascados (pending/sent stale).';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $thresholdMinutes = (int) config('dian.stuck_recovery_minutes', 15);

        $candidates = ElectronicDocument::query()
            ->where('retry_count', '<', 6)
            ->where(function ($q) {
                $q->whereNull('last_retry_at')
                    ->orWhere('last_retry_at', '<=', now()->subMinutes(5));
            })
            ->whereNotNull('order_id')
            ->where(function ($q) use ($thresholdMinutes) {
                $q->where('status', 'error')
                    ->orWhere(function ($q2) use ($thresholdMinutes) {
                        $q2->whereIn('status', ['pending', 'sent'])
                            ->where('updated_at', '<=', now()->subMinutes($thresholdMinutes));
                    });
            })
            ->orderBy('id')
            ->limit($max)
            ->get();

        $count = 0;
        foreach ($candidates as $doc) {
            EmitDianDocumentJob::dispatch((string) $doc->order_id, (string) $doc->document_type, false);
            $count++;
        }

        $this->info("Encolados {$count} documentos para reintento.");

        return self::SUCCESS;
    }
}
