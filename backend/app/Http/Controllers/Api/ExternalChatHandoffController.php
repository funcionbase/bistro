<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Rules\SafePlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint exclusivo para que el bot externo solicite intervencion humana.
 *
 * El bot autentica con su JWT (middleware bot.jwt). El company_nit no se acepta
 * en el body: se toma del payload del JWT para evitar que un bot de una empresa
 * pause chats de otra.
 *
 * Si la conversacion no existe se crea on-the-fly (lo normal cuando el bot esta
 * gestionando un cliente nuevo). El resultado es un chat con bot_paused=true
 * y handoff_requested_at fijado, listo para que un operador lo retome desde el panel.
 */
class ExternalChatHandoffController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('bot_company_nit');

        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s]+$/'],
            'client_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'last_message' => ['nullable', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
        ]);

        $now = now();

        $chat = DB::transaction(function () use ($companyNit, $validated, $now): Chat {
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

            $chat->fill([
                'client_name' => $validated['client_name'] ?? $chat->client_name ?? $contact->name,
                'contact_id' => $contact->id,
                'branch_id' => $chat->branch_id ?? $defaultBranchId,
                'status' => $chat->exists ? $chat->status : 'open',
                'bot_paused' => true,
                'handoff_requested_at' => $now,
                'handoff_reason' => $validated['reason'] ?? null,
                'last_message_at' => $now,
            ])->save();

            if (! empty($validated['last_message'])) {
                ChatMessage::create([
                    'chat_id' => $chat->id,
                    'sender' => 'bot',
                    'body' => $validated['last_message'],
                    'sent_at' => $now,
                ]);
            }

            return $chat;
        });

        return response()->json([
            'data' => [
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'client_phone' => $chat->client_phone,
                'bot_paused' => true,
                'handoff_requested_at' => $chat->handoff_requested_at?->toIso8601String(),
            ],
        ], 201);
    }
}
