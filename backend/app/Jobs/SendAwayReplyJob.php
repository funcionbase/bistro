<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\BusinessHoursService;
use App\Services\CompanySettingsService;
use App\Services\Whatsapp\WhatsappOutboundMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Respuesta automática fuera de horario, sin n8n (§8.4b punto 10).
 *
 * `BusinessHoursService` y el setting `bot_away_message` ya existen: esto es un
 * check de horario más un envío. Le da una respuesta útil al 100 % de los
 * clientes, incluidos los que nunca van a tener un flujo de automatización.
 *
 * Gates, en orden:
 *  - `bot_paused`: solo sin automatización activa. Cuando F6 prenda un flujo
 *    real (`bot_paused=false`), ese flujo maneja el fuera de horario y este no
 *    duplica.
 *  - `whatsapp_away_reply_enabled`: off por defecto. Una empresa que nunca tocó
 *    automatización no empieza a auto-responder sola.
 *  - fuera de horario de LA SEDE del chat.
 *  - una sola vez por chat y día: `Cache::add` es atómico sobre el store
 *    compartido, así que con N instancias no se manda dos veces.
 *
 * NO toca `pending_reply_since`: la respuesta es automática, el cliente sigue
 * esperando a un humano y el chat debe quedar en "sin responder".
 */
class SendAwayReplyJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 2;

    /** @var int */
    public $backoff = 30;

    public function __construct(public string $chatId)
    {
        $this->onQueue('notifications');
    }

    public function handle(BusinessHoursService $hours, CompanySettingsService $settings): void
    {
        // Sin JWT en la cola: la sede sale del chat y se pasa explícita al
        // service de horarios (sin ella tomaría una sede arbitraria).
        $chat = Chat::withoutBranchScope()->find($this->chatId);

        if ($chat === null || ! $chat->bot_paused) {
            return;
        }

        $nit = (string) $chat->company_nit;

        if (! (bool) $settings->get($nit, 'whatsapp_away_reply_enabled', false)) {
            return;
        }

        if ($hours->isOpenNow($nit, null, $chat->branch_id)) {
            return;
        }

        $body = trim((string) $settings->get($nit, 'bot_away_message', ''));

        if ($body === '') {
            return;
        }

        // Una sola vez por chat y día (zona de la app). TTL de un día: la próxima
        // ventana de cierre vuelve a habilitar el aviso.
        if (! Cache::add("wa:away:{$chat->id}:".now()->format('Y-m-d'), 1, now()->addDay())) {
            return;
        }

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'bot',
            'sent_by_user_id' => null,
            'body' => $body,
            'sent_at' => now(),
        ]);

        // El preview y el orden secundario de la bandeja siguen el último
        // mensaje; el primario (`pending_reply_since`) queda intacto.
        $chat->forceFill(['last_message_at' => now()])->save();

        if ($chat->source === 'whatsapp') {
            WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
        }
    }
}
