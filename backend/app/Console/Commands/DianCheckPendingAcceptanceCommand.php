<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Revisa documentos DIAN en `sent` (sin webhook) por más de N
 * minutos y registra warning. En provider real, sumaríamos un poll al
 * provider (`GET /documents/{trackId}/status`) para sincronizar. Hoy solo
 * loguea — el mock async ya garantiza llegada del webhook en <30s.
 */
class DianCheckPendingAcceptanceCommand extends Command
{
    protected $signature = 'dian:check-pending-acceptance {--stale-minutes=30}';

    protected $description = 'Marca como atención los documentos sent sin webhook DIAN tras N minutos.';

    public function handle(): int
    {
        $stale = (int) $this->option('stale-minutes');
        $cutoff = now()->subMinutes($stale);

        $count = ElectronicDocument::query()
            ->where('status', 'sent')
            ->where('sent_at', '<=', $cutoff)
            ->count();

        if ($count > 0) {
            $this->warn("{$count} documentos DIAN llevan más de {$stale}min en `sent` sin webhook.");
            // En provider real: encolar PollProviderStatusJob por cada uno.
        }

        return self::SUCCESS;
    }
}
