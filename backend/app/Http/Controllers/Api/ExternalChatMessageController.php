<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ThrottlesBotChatWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Rules\SafePlainText;
use App\Services\Chat\ChatAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints para que el bot externo cache las conversaciones de WhatsApp en BD.
 *
 * El objetivo es reducir las llamadas a la API de Meta: el bot persiste cada
 * mensaje recibido (push) y luego puede leer historial/deltas desde aqui (pull)
 * en lugar de volver a pegarle a Meta.
 *
 * Aislamiento: company_nit se obtiene siempre del JWT de bot (bot_company_nit),
 * nunca del body o del query string. Asi un bot de la empresa A no puede leer
 * ni escribir conversaciones de la empresa B.
 *
 * Idempotencia: cada mensaje incluye meta_message_id; la unique constraint
 * (chat_id, meta_message_id) impide duplicados cuando el bot reintenta.
 */
class ExternalChatMessageController extends Controller
{
    use ThrottlesBotChatWrites;

    public function __construct(private readonly ChatAuditLogger $auditLogger) {}

    public function store(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('bot_company_nit');

        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s]+$/'],
            'client_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'sender' => ['required', 'string', 'in:client,bot'],
            'body' => ['required', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
            'meta_message_id' => ['nullable', 'string', 'max:128'],
            'sent_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:sent,delivered,read,failed'],
        ]);

        // Anti-loop del bot: la escritura del bot puede disparar un envío real por
        // WhatsApp aguas abajo — un flujo en bucle quema el número (§9.6, §13).
        if ($limited = $this->botWriteRateLimit((string) $companyNit, $validated['client_phone'])) {
            return $limited;
        }

        $sentAt = isset($validated['sent_at']) ? Carbon::parse($validated['sent_at']) : now();

        [$chat, $message, $created] = DB::transaction(function () use (
            $companyNit, $validated, $sentAt
        ): array {
            $defaultBranchId = Branch::query()
                ->where('company_nit', $companyNit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            $contact = Contact::firstOrCreate(
                [
                    'company_nit' => $companyNit,
                    'phone' => $validated['client_phone'],
                ],
                [
                    'name' => $validated['client_name'] ?? null,
                    'branch_id' => $defaultBranchId,
                ],
            );

            // ponytail: el bot externo se autentica por empresa, no por canal,
            // así que resuelve el chat por (company_nit, client_phone). Techo
            // conocido: si la empresa llega a tener varios canales con el mismo
            // teléfono de cliente, elige uno arbitrario. Se cierra en F6, donde
            // el token del bot ya acota (NIT, sede) y puede filtrar por canal.
            /** @var Chat $chat */
            $chat = Chat::firstOrNew([
                'company_nit' => $companyNit,
                'client_phone' => $validated['client_phone'],
            ]);
            if (! $chat->exists) {
                $chat->status = 'open';
                $chat->branch_id = $defaultBranchId;
                $chat->bot_paused = false;
                $chat->client_name = $validated['client_name'] ?? $contact->name;
            }
            $chat->contact_id = $contact->id;
            $chat->last_message_at = $sentAt;
            // Espera del cliente: la abre el mensaje entrante (solo si no había
            // una en curso) y la cierra la respuesta del bot.
            if ($validated['sender'] === 'client') {
                if ($chat->pending_reply_since === null) {
                    $chat->pending_reply_since = $sentAt;
                }
            } else {
                $chat->pending_reply_since = null;
            }
            $chat->meta_synced_at = now();
            $chat->save();

            // Idempotencia por meta_message_id: si llega un retry desde el bot
            // con el mismo id de Meta, devolvemos el existente sin duplicar.
            if (! empty($validated['meta_message_id'])) {
                $existing = ChatMessage::where('chat_id', $chat->id)
                    ->where('meta_message_id', $validated['meta_message_id'])
                    ->first();
                if ($existing !== null) {
                    return [$chat, $existing, false];
                }
            }

            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'sender' => $validated['sender'],
                'status' => $validated['status'] ?? null,
                'body' => $validated['body'],
                'meta_message_id' => $validated['meta_message_id'] ?? null,
                'sent_at' => $sentAt,
            ]);

            return [$chat, $message, true];
        });

        // Solo el mensaje del BOT es una respuesta al cliente; el `sender=client`
        // es el bot empujando lo que ya recibio de WhatsApp, no una accion suya.
        if ($created && $validated['sender'] === 'bot') {
            $this->auditLogger->log(
                action: 'chat.message.sent_by_bot',
                auditable: $chat,
                data: [
                    'chat_id' => $chat->id,
                    'chat_message_id' => $message->id,
                    'company_nit' => $companyNit,
                    'body_length' => mb_strlen((string) $message->body),
                ],
                request: $request,
            );
        }

        return response()->json([
            'data' => new ChatMessageResource($message),
            'meta' => [
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'created' => $created,
                'bot_paused' => (bool) $chat->bot_paused,
            ],
        ], $created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('bot_company_nit');

        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s]+$/'],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $chat = Chat::forCompany($companyNit)
            ->where('client_phone', $validated['client_phone'])
            ->first();

        if ($chat === null) {
            return response()->json(['data' => [], 'meta' => ['chat_id' => null, 'bot_paused' => false]]);
        }

        $query = ChatMessage::where('chat_id', $chat->id)->orderBy('sent_at');
        if (! empty($validated['since'])) {
            $query->where('sent_at', '>', Carbon::parse($validated['since']));
        }
        $messages = $query->limit($validated['limit'] ?? 100)->get();

        $chat->forceFill(['meta_synced_at' => now()])->save();

        // `user_id = null`: el actor es una credencial, no una persona. Es la
        // UNICA lectura de conversaciones que hace el bot (§12), asi que se
        // audita aunque el resto de lecturas del bot no exista. Dedupe de 15 min
        // por chat: el bot relee el historial en cada turno.
        $this->auditLogger->log(
            action: 'chat.history.read_by_bot',
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'messages_returned' => $messages->count(),
            ],
            request: $request,
            dedupeKey: $chat->id,
        );

        return response()->json([
            'data' => ChatMessageResource::collection($messages),
            'meta' => [
                'chat_id' => $chat->id,
                'bot_paused' => (bool) $chat->bot_paused,
                'handoff_requested_at' => $chat->handoff_requested_at?->toIso8601String(),
                'last_message_at' => $chat->last_message_at?->toIso8601String(),
            ],
        ]);
    }
}
