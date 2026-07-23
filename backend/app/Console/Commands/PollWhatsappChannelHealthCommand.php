<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\PushSubscription;
use App\Services\WebPushDispatcher;
use App\Services\Whatsapp\EvolutionChannelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Poll de salud de los canales de WhatsApp (plan 8-whatsapp.md §6.5).
 *
 * Recorre los canales `connected` con instancia de Evolution, consulta el estado
 * real y alerta ante caída SOSTENIDA. El umbral existe porque un `close` aislado
 * suele ser un parpadeo de red que Baileys reconecta solo: alertar en el primero
 * llenaría el teléfono del dueño de falsos positivos y volvería la alerta ruido.
 *
 * Se agenda cada 5 min con `->onOneServer()->withoutOverlapping(5)`: la app corre
 * en un ASG de N instancias y sin eso cada EC2 dispararía su propia alerta.
 *
 * El contador de fallas vive en caché (store `database`, compartido entre
 * instancias) y no en una columna: es estado operativo efímero, no un hecho del
 * negocio que merezca migración.
 */
class PollWhatsappChannelHealthCommand extends Command
{
    protected $signature = 'whatsapp:poll-channel-health
                            {--company= : NIT específico (default: todos los canales conectados)}';

    protected $description = 'Verifica el estado de los canales de WhatsApp y alerta ante caídas sostenidas';

    public function handle(WebPushDispatcher $push): int
    {
        $threshold = (int) config('evolution.health.failure_threshold', 2);

        $query = CompanyWhatsappAccount::query()
            ->whereNotNull('evo_instance')
            ->where('status', 'connected');

        if ($companyNit = $this->option('company')) {
            $query->where('company_nit', $companyNit);
        }

        $channels = EvolutionChannelService::make();
        $checked = 0;
        $down = 0;
        $alerted = 0;

        foreach ($query->cursor() as $account) {
            $checked++;
            $result = $channels->syncState($account);
            $key = "wa:health:fails:{$account->id}";

            if (($result['ok'] ?? false) && $result['state'] === 'open') {
                Cache::forget($key);

                continue;
            }

            $down++;
            $fails = (int) Cache::get($key, 0) + 1;
            // TTL holgado frente al intervalo de 5 min: si el comando deja de
            // correr, el contador expira solo y no queda pegado en "caído".
            Cache::put($key, $fails, now()->addHours(6));

            if ($fails !== $threshold) {
                // Solo se alerta EN el umbral, no en cada ciclo posterior: si no,
                // un canal caído un fin de semana manda una notificación cada
                // 5 minutos.
                continue;
            }

            $this->notifyChannelDown($account, $push, $result['state'] ?? 'unknown');
            $alerted++;
        }

        $purged = $this->purgeStalePending();

        $this->info("canales={$checked} caídos={$down} alertados={$alerted} pending_purgados={$purged}");

        return self::SUCCESS;
    }

    /**
     * Borra los canales que quedaron a medio conectar hace más de un día
     * (§8.4b punto 5).
     *
     * Un `pending` ocupa el slot del índice único parcial de (empresa|sede). Sin
     * esta purga, el usuario que cerró el modal del QR ayer no puede volver a
     * conectar hoy: el alta le responde 409 para siempre y la única salida sería
     * tocar la base a mano.
     *
     * Va acá y no en un comando nuevo: este ya corre cada 5 min con
     * `->onOneServer()`, que es exactamente la garantía que la purga necesita en
     * un ASG de N instancias.
     */
    private function purgeStalePending(): int
    {
        $ttlHours = (int) config('evolution.health.pending_ttl_hours', 24);

        $stale = CompanyWhatsappAccount::query()
            ->whereNotNull('evo_instance')
            ->whereIn('status', ['pending', 'verifying'])
            ->where('created_at', '<', now()->subHours($ttlHours))
            ->get();

        $channels = EvolutionChannelService::make();
        $purged = 0;

        foreach ($stale as $account) {
            $channels->destroy($account);
            $purged++;

            Log::channel('single')->info('whatsapp.channel.pending_purged', [
                'account_id' => $account->id,
                'company_nit' => $account->company_nit,
                'age_hours' => $ttlHours,
            ]);
        }

        return $purged;
    }

    private function notifyChannelDown(CompanyWhatsappAccount $account, WebPushDispatcher $push, string $state): void
    {
        $label = $account->label ?: ($account->phone_e164 ?: 'WhatsApp');

        // `session_invalidated` lo escribe el webhook al recibir close/401: la
        // credencial está muerta y hay que re-escanear. Cualquier otro estado es
        // un corte del que se puede volver solo.
        $needsRescan = $account->last_error === 'session_invalidated';

        $body = $needsRescan
            ? "El WhatsApp «{$label}» se desconectó y hay que volver a escanear el código QR."
            : "El WhatsApp «{$label}» lleva varios minutos sin conexión. Estamos reintentando.";

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'health_alert',
            'payload' => ['state' => $state, 'needs_rescan' => $needsRescan],
            'created_at' => now(),
        ]);

        Log::channel('single')->warning('whatsapp.channel.down', [
            'account_id' => $account->id,
            'company_nit' => $account->company_nit,
            'state' => $state,
            'needs_rescan' => $needsRescan,
        ]);

        if (! $push->isConfigured()) {
            return;
        }

        PushSubscription::query()
            ->active()
            ->where('company_nit', $account->company_nit)
            ->cursor()
            ->each(function (PushSubscription $subscription) use ($push, $body, $account): void {
                $push->send($subscription, [
                    'title' => 'WhatsApp desconectado',
                    'body' => $body,
                    'url' => '/company/whatsapp',
                    // El tag colapsa duplicados a nivel del sistema operativo: si
                    // el canal vuelve a caer, reemplaza la anterior en vez de
                    // apilar notificaciones.
                    'tag' => "wa-channel-down-{$account->id}",
                ]);
            });
    }
}
