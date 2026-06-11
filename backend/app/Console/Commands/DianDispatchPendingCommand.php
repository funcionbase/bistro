<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmitDianDocumentJob;
use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Reintenta documentos DIAN en estado `pending` o `error` con
 * backoff exponencial (el job EmitDianDocumentJob es ShouldBeUnique +
 * tries=6 con backoff `[60, 180, 300, 900, 1800, 3600]`).
 */
class DianDispatchPendingCommand extends Command
{
    protected $signature = 'dian:dispatch-pending {--max=50 : Máximo de documentos por corrida}';

    protected $description = 'Reintenta documentos DIAN en estado pending/error con backoff exponencial.';

    public function handle(): int
    {
        $max = (int) $this->option('max');

        $candidates = ElectronicDocument::query()
            ->whereIn('status', ['pending', 'error'])
            ->where('retry_count', '<', 6)
            ->where(function ($q) {
                $q->whereNull('last_retry_at')
                    ->orWhere('last_retry_at', '<=', now()->subMinutes(5));
            })
            ->whereNotNull('order_id')
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
