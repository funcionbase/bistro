<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ThrottlesBotChatWrites;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Rules\SafePlainText;
use App\Services\Chat\ChatAuditLogger;
use App\Services\Whatsapp\AutomationDispatcher;
use App\Services\Whatsapp\EvolutionClient;
use App\Services\Whatsapp\WhatsappOutboundMessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * F6 — el bot externo RESPONDE y controla una conversación (§9.3, §9.6, §9.8).
 *
 * Es la superficie más sensible del contrato con n8n: escribe en la conversación
 * de un cliente final y dispara un envío real por WhatsApp. n8n NUNCA ve
 * credenciales de WhatsApp ni sabe en qué servidor Evolution vive el canal — todo
 * pasa por bistro.
 *
 * Aislamiento (§9.6 controles #2, #4): la empresa y la sede se derivan del TOKEN
 * del flujo (inyectados por `bot.token`), nunca del body. El chat se resuelve con
 * `Chat::forCompany($botCompanyNit)->findOrFail()` — un reply sobre un chat de
 * otra empresa devuelve 404 (no 403: no confirma existencia ajena).
 */
class ExternalChatReplyController extends Controller
{
    use ThrottlesBotChatWrites;

    public function __construct(private readonly ChatAuditLogger $auditLogger) {}

    public function reply(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('bot_company_nit');
        $branchId = $request->attributes->get('bot_branch_id');   // null = flujo de empresa / legado
        $flowId = $request->attributes->get('bot_flow_id');       // null = legado

        // Control #5: payload. Sin media en v1, sin HTML, sin control chars.
        $validated = $request->validate([
            'chat_id' => ['required', 'uuid'],
            'body' => ['required', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        // Control #4: resolución del chat SIEMPRE scopeada a la empresa del token.
        // Si el flujo es de sede (branch_id no nulo), además se exige la sede: el
        // flujo de la Sede Norte no puede tocar chats de la Sede Sur (§7.5.1).
        $chat = Chat::forCompany($companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($validated['chat_id']);

        // Control #6: estado del canal. Si no hay canal o está caído, se responde
        // 409 accionable en vez de fallar silencioso aguas abajo.
        $sender = WhatsappOutboundMessageSender::forCurrentEnvironment();
        $channel = $sender->resolveChannel($chat);
        if ($channel === null || ! $channel->isConnected()) {
            return response()->json([
                'message' => 'El canal de WhatsApp no está conectado.',
                'code' => 'channel_disconnected',
            ], 409);
        }

        // Control #7: respeto al operador. Si un humano tomó la conversación, el
        // bot no habla encima.
        if ($chat->bot_paused) {
            return response()->json([
                'message' => 'La conversación fue tomada por un operador.',
                'code' => 'chat_taken_by_operator',
            ], 409);
        }

        // Control #10: idempotencia. Un retry de n8n con el mismo key devuelve el
        // mensaje ya creado, sin re-enviar. Se chequea ANTES del rate limit para
        // no consumir cupo con reintentos. Scope: el flujo (o la empresa en legado).
        $idemScope = $flowId ?? $companyNit;
        $idemCacheKey = isset($validated['idempotency_key'])
            ? "bot-reply:{$idemScope}:{$validated['idempotency_key']}"
            : null;
        if ($idemCacheKey !== null && ($existingId = Cache::get($idemCacheKey)) !== null) {
            $existing = ChatMessage::find($existingId);
            if ($existing !== null) {
                return response()->json([
                    'message_id' => $existing->id,
                    'chat_id' => $chat->id,
                    'status' => $existing->status ?? 'sent',
                ], 200);
            }
        }

        // Controles #8 y #9: rate limit por conversación (~10/min) y por empresa
        // (60/min). Es la defensa concreta contra el loop de n8n que quema el
        // número — el envío masivo es la causa #1 de baneo (§13).
        if ($limited = $this->botWriteRateLimit($companyNit, (string) $chat->client_phone)) {
            return $limited;
        }

        // Control #11: anti-loop. Se persiste con sender='bot'; el push (§9.2)
        // nunca emite webhook para sender='bot', así que responder no dispara otro
        // evento que muerda la cola.
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'bot',
            'status' => null,
            'body' => $validated['body'],
            'sent_at' => now(),
        ]);

        $ok = $sender->deliver($chat, $message);

        $chat->forceFill([
            'last_message_at' => now(),
            'pending_reply_since' => null,  // el bot respondió: cierra la espera
        ])->save();

        // Control #12: auditoría. flow_id identifica la CREDENCIAL que envió;
        // channel_id, el número por el que salió. user_id null = actor no-persona.
        $this->auditLogger->log(
            action: 'chat.message.sent_by_bot',
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
                'flow_id' => $flowId,
                'channel_id' => $channel->id,
                'company_nit' => $companyNit,
                'body_length' => mb_strlen((string) $message->body),
            ],
            request: $request,
        );

        if ($idemCacheKey !== null) {
            Cache::put($idemCacheKey, $message->id, now()->addMinutes(5));
        }

        // El mensaje quedó persistido pase lo que pase; si el proveedor lo rechazó,
        // `deliver` ya lo marcó 'failed'. Se devuelve 502 para que n8n reintente,
        // con el mismo cuerpo mínimo (control #13: no eco de historial ni teléfono).
        $status = $ok ? ($message->fresh()->status ?? 'sent') : 'failed';

        return response()->json([
            'message_id' => $message->id,
            'chat_id' => $chat->id,
            'status' => $status,
        ], $ok ? 201 : 502);
    }

    /**
     * Pausar/reanudar el bot en una conversación desde el flujo (§9.8). Mismo
     * efecto que el toggle del operador, con auditoría de la credencial.
     */
    public function bot(Request $request, string $chat): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('bot_company_nit');
        $branchId = $request->attributes->get('bot_branch_id');
        $flowId = $request->attributes->get('bot_flow_id');

        $validated = $request->validate(['paused' => ['required', 'boolean']]);

        $model = Chat::forCompany($companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($chat);

        $model->forceFill(['bot_paused' => $validated['paused']])->save();

        $this->auditLogger->log(
            action: 'chat.bot.toggled',
            auditable: $model,
            data: [
                'chat_id' => $model->id,
                'flow_id' => $flowId,
                'company_nit' => $companyNit,
                'bot_paused' => $validated['paused'],
            ],
            request: $request,
        );

        // F6 (§9.2): avisar del toggle si hay flujo suscrito.
        app(AutomationDispatcher::class)->forChat(
            AutomationDispatcher::EVENT_BOT_TOGGLED,
            $model,
            null,
            ['bot_paused' => (bool) $model->bot_paused],
        );

        return response()->json([
            'chat_id' => $model->id,
            'bot_paused' => (bool) $model->bot_paused,
        ]);
    }

    /**
     * Indicador de "escribiendo…" (§9.3). Best-effort: si el canal no está
     * conectado o Evolution falla, se responde 204 igual — es cosmético, no
     * bloquea la conversación.
     */
    public function typing(Request $request, string $chat): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('bot_company_nit');
        $branchId = $request->attributes->get('bot_branch_id');

        $model = Chat::forCompany($companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($chat);

        $channel = $model->resolveWhatsappChannel();
        if ($channel !== null && $channel->canSendViaEvolution()) {
            EvolutionClient::forAccount($channel)->sendPresence(
                (string) $channel->evo_instance,
                (string) $channel->evoToken(),
                (string) $model->client_phone,
            );
        }

        return response()->json([], 204);
    }
}
