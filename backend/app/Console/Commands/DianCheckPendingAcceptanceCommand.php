<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ElectronicDocument;
use App\Services\AuditService;
use Illuminate\Console\Command;

/**
 * Proceso de validación de la emisión DIAN: confirma que los documentos se
 * están generando correctamente y detecta lo que `dian:dispatch-pending` (cada
 * 5min, recupera `pending`/`sent` atascados tras `dian.stuck_recovery_minutes`
 * ≈15min y reintenta `error` con backoff) NO puede resolver solo. Corre 15min
 * después de esa ventana de recuperación (`--stale-minutes=30` > 15) para
 * darle oportunidad de actuar antes de escalar.
 *
 * Dos categorías, ambas persistidas en `audit_logs` (no solo consola —
 * traceable/reportable, alineado con la cultura de auditoría DIAN del
 * proyecto en vez de un warning efímero que nadie lee):
 *
 *  1. `dian.document.validation_stuck` — `pending`/`sent` más viejos que el
 *     umbral de recuperación: la recuperación automática ya tuvo su turno y
 *     no lo resolvió (provider caído, webhook perdido, etc.).
 *  2. `dian.document.validation_retries_exhausted` — `retry_count >= 6` sin
 *     llegar a `accepted`. GAP real: `dian:dispatch-pending` filtra
 *     `retry_count < 6`, así que estos documentos quedan excluidos para
 *     siempre de la recuperación automática — sin esta validación, nadie se
 *     entera de que jamás se van a generar solos.
 *
 * En provider real, sumaríamos un poll activo al provider
 * (`GET /documents/{trackId}/status`) antes de escalar — hoy el mock async
 * garantiza el webhook en <30s, así que un doc que sigue `sent` pasado el
 * umbral es genuinamente anómalo.
 */
class DianCheckPendingAcceptanceCommand extends Command
{
    protected $signature = 'dian:check-pending-acceptance {--stale-minutes=30}';

    protected $description = 'Valida que la emisión DIAN se complete correctamente: audita documentos atascados post-recuperación y con reintentos agotados.';

    public function handle(AuditService $audit): int
    {
        if (! config('dian.emission_enabled', false)) {
            $this->info('Emisión DIAN deshabilitada (DIAN_EMISSION_ENABLED=false) — nada que validar.');

            return self::SUCCESS;
        }

        $stale = (int) $this->option('stale-minutes');
        $cutoff = now()->subMinutes($stale);
        $issues = 0;

        $stuck = ElectronicDocument::query()
            ->whereIn('status', ['pending', 'sent'])
            ->where('updated_at', '<=', $cutoff)
            ->get();

        foreach ($stuck as $document) {
            $audit->log('dian.document.validation_stuck', null, $document, [
                'status' => $document->status,
                'order_id' => $document->order_id,
                'full_number' => $document->full_number,
                'stale_minutes' => $stale,
                'last_touched_at' => $document->updated_at?->toIso8601String(),
            ]);
            $this->error("Documento id={$document->id} full_number={$document->full_number} atascado en '{$document->status}' hace más de {$stale}min — la recuperación automática no lo resolvió.");
            $issues++;
        }

        $exhausted = ElectronicDocument::query()
            ->where('retry_count', '>=', 6)
            ->where('status', '!=', 'accepted')
            ->get();

        foreach ($exhausted as $document) {
            $audit->log('dian.document.validation_retries_exhausted', null, $document, [
                'status' => $document->status,
                'order_id' => $document->order_id,
                'full_number' => $document->full_number,
                'retry_count' => $document->retry_count,
                'rejection_reason' => $document->rejection_reason,
            ]);
            $this->error("Documento id={$document->id} full_number={$document->full_number} agotó reintentos (retry_count={$document->retry_count}) en estado '{$document->status}' — requiere atención manual.");
            $issues++;
        }

        if ($issues === 0) {
            $this->info('Validación OK: no hay documentos DIAN atascados ni con reintentos agotados.');
        } else {
            $this->warn("{$issues} documento(s) DIAN requieren atención — ver audit_logs (action LIKE 'dian.document.validation_%').");
        }

        return self::SUCCESS;
    }
}
