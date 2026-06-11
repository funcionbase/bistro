<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\Whatsapp\WhatsappInboundMessageHandler;
use Illuminate\Console\Command;

/**
 * Reprocesa eventos de webhook de WhatsApp pendientes o con error.
 *
 * Casos de uso:
 *   - El handler reviento por un bug y quedaron eventos con `error` no null.
 *   - La firma fallaba por configuracion mala y quedaron eventos `signature_valid=false`.
 *   - Hubo un outage y queremos correr a mano sobre lo que SI llego.
 *
 * El handler ya es idempotente (chat_messages.unique chat_id+meta_message_id),
 * asi que reprocesar el mismo evento dos veces no duplica.
 *
 * Uso:
 *   php artisan whatsapp:replay-events
 *   php artisan whatsapp:replay-events --include-invalid-signature
 *   php artisan whatsapp:replay-events --since="2026-05-01"
 *   php artisan whatsapp:replay-events --id=42
 */
class ReplayWhatsappEvents extends Command
{
    protected $signature = 'whatsapp:replay-events
                            {--id= : Reprocesa un evento especifico por id}
                            {--include-invalid-signature : Tambien reprocesa los marcados como firma invalida}
                            {--since= : Fecha minima ISO (received_at >= since)}
                            {--limit=500 : Maximo de eventos a procesar}';

    protected $description = 'Reprocesa eventos de WhatsApp pendientes o con error';

    public function handle(WhatsappInboundMessageHandler $handler): int
    {
        $query = WebhookEvent::query()->forProvider('meta_whatsapp');

        if ($id = $this->option('id')) {
            $query->where('id', (string) $id);
        } else {
            $query->unprocessed();

            if (! $this->option('include-invalid-signature')) {
                $query->where('signature_valid', true);
            }

            if ($since = $this->option('since')) {
                $query->where('received_at', '>=', $since);
            }
        }

        $events = $query->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($events->isEmpty()) {
            $this->info('No hay eventos para reprocesar.');

            return self::SUCCESS;
        }

        $this->info("Reprocesando {$events->count()} eventos...");

        $ok = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                $stats = $handler->handle($event->payload ?? []);
                $event->forceFill([
                    'processed_at' => now(),
                    'attempts' => $event->attempts + 1,
                    'error' => null,
                ])->save();

                $this->line(" #{$event->id} → ok ".json_encode($stats));
                $ok++;
            } catch (\Throwable $e) {
                $event->forceFill([
                    'error' => mb_substr($e->getMessage(), 0, 65000),
                    'attempts' => $event->attempts + 1,
                ])->save();

                $this->error(" #{$event->id} → fallo: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. ok={$ok} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
