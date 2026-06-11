<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Rules\SafePlainText;
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
