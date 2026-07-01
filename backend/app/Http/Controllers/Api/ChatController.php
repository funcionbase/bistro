<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\UpdateChatBotRequest;
use App\Http\Requests\Chat\UpdateChatContactRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatResource;
use App\Jobs\MarkWhatsappMessageReadJob;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Order;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\CompanySettingsService;
use App\Services\FeaturePermissionService;
use App\Services\Whatsapp\WhatsappOutboundMessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Panel de conversaciones del operador (chats con clientes via WhatsApp/bot).
 *
 * index() — lista chats de la empresa activa con preview del ultimo mensaje (para el sidebar).
 * show()  — devuelve un chat con todos sus mensajes (para el detalle del panel).
 * storeMessage() — operador envia un mensaje manual al cliente (sender = 'operator').
 * updateBot() — pausa/reanuda las respuestas automaticas del bot en una conversacion.
 * updateContact() — guarda/edita los datos del contacto WhatsApp asociado al chat.
 *
 * Permission: chats.read / chats.update. Los permisos heredan el patron de orders/coupons.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        // Validamos el parametro de busqueda: string corto. Esto cierra abuso
        // por entradas enormes que disparen full table scans con ILIKE.
        $validated = $request->validate([
            'q' => ['nullable', new SafePlainText(maxBytes: 100)],
        ]);
        $term = trim((string) ($validated['q'] ?? ''));

        // Sin busqueda mostramos solo las 5 mas recientes (vista por defecto al
        // abrir el panel). Cuando hay termino de busqueda subimos el tope para
        // que los matches por orden/mensaje no queden ocultos.
        $limit = $term === '' ? 5 : 100;

        $query = Chat::forCompany($companyNit)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->limit($limit);

        if ($term !== '') {
            // Escapamos los metacaracteres de LIKE/ILIKE (`%`, `_`) y el propio
            // caracter de escape `!` para evitar abuso de wildcards. El binding
            // de PDO ya previene SQL injection; esto es defensa adicional.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $like = '%'.$escaped.'%';

            // Si el termino contiene digitos tambien buscamos por id de orden:
            // recuperamos los telefonos de ordenes cuyo id (casteado a texto)
            // contenga el termino dentro de la empresa activa.
            $orderPhones = [];
            if (preg_match('/\d/', $term)) {
                $orderPhones = Order::forCompany($companyNit)
                    ->whereRaw("CAST(id AS TEXT) ILIKE ? ESCAPE '!'", [$like])
                    ->pluck('client_phone')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }

            $query->where(function ($w) use ($like, $orderPhones): void {
                $w->whereRaw("client_name ILIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("client_phone ILIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas(
                        'messages',
                        fn ($m) => $m->whereRaw("body ILIKE ? ESCAPE '!'", [$like])
                    );

                if (! empty($orderPhones)) {
                    $w->orWhereIn('client_phone', $orderPhones);
                }
            });
        }

        $chats = $query->get();

        $this->attachLatestPaidOrders($chats, $companyNit);

        return response()->json([
            'data' => ChatResource::collection($chats),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = Chat::forCompany($companyNit)
            ->with(['messages', 'contact'])
            ->findOrFail($id);

        $this->attachLatestPaidOrders(collect([$chat]), $companyNit);

        return response()->json([
            'data' => new ChatResource($chat),
        ]);
    }

    /**
     * Marca como leidos en Meta (doble chulito azul) los mensajes entrantes del
     * chat. Se invoca desde el frontend cuando el operador abre la conversacion
     * con la pestana visible/enfocada.
     *
     * Reglas:
     *  - Requiere `chats.read` (es un evento de visualizacion, no de escritura).
     *  - Antes de tocar Meta valida el setting `whatsapp_read_receipts`. Si esta
     *    apagado, responde 200 con `skipped=read_receipts_disabled` y no llama a
     *    Meta. El frontend invoca el endpoint a ciegas; el backend es la unica
     *    fuente de verdad para el switch de privacidad.
     *  - Solo despacha el job para el ultimo mensaje entrante con `meta_message_id`.
     *    Meta trata los read receipts como acumulativos: marcar el ultimo cubre
     *    todos los anteriores en la misma conversacion.
     *  - Throttle por chat (cache 60s) para no saturar la cola si el operador
     *    cambia de pestana repetidamente.
     */
    public function markRead(Request $request, string $id, CompanySettingsService $settings): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = Chat::forCompany($companyNit)->findOrFail($id);

        $enabled = (bool) $settings->get($companyNit, 'whatsapp_read_receipts', false);
        if (! $enabled) {
            return response()->json(['skipped' => 'read_receipts_disabled']);
        }

        $latestInbound = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('sender', 'client')
            ->whereNotNull('meta_message_id')
            ->orderByDesc('id')
            ->first();

        if ($latestInbound === null) {
            return response()->json(['skipped' => 'no_inbound_messages']);
        }

        $cacheKey = "chat:{$chat->id}:last_read_message_id";
        $lastMarked = Cache::get($cacheKey);
        if ($lastMarked === $latestInbound->meta_message_id) {
            return response()->json(['skipped' => 'already_marked']);
        }

        Cache::put($cacheKey, $latestInbound->meta_message_id, now()->addMinutes(5));

        MarkWhatsappMessageReadJob::dispatch($companyNit, (string) $latestInbound->meta_message_id);

        return response()->json([
            'dispatched' => true,
            'meta_message_id' => $latestInbound->meta_message_id,
        ]);
    }

    /**
     * Adjunta a cada chat la ultima orden del cliente (la mas reciente por `ordered_at`,
     * sin importar estado ni si esta pagada). Se usa para mostrar el badge de estado
     * en la lista de conversaciones y en el header del chat.
     *
     * @param  Collection<int, Chat>  $chats
     */
    private function attachLatestPaidOrders(Collection $chats, string $companyNit): void
    {
        $phones = $chats->pluck('client_phone')->filter()->unique()->values()->all();
        if (empty($phones)) {
            return;
        }

        $latestByPhone = Order::forCompany($companyNit)
            ->whereIn('client_phone', $phones)
            ->orderByDesc('ordered_at')
            ->get(['id', 'status', 'client_phone', 'ordered_at'])
            ->groupBy('client_phone')
            ->map(fn ($group) => $group->first());

        $chats->each(function (Chat $chat) use ($latestByPhone): void {
            $order = $latestByPhone->get($chat->client_phone);
            $chat->setAttribute('latest_order_id', $order?->id);
            $chat->setAttribute('latest_order_status', $order?->status);
        });
    }

    /**
     * Devuelve los datos del contacto asociado al chat y el historial de ordenes
     * del cliente (por `client_phone` dentro de la empresa activa).
     */
    public function clientDetail(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        /** @var Chat $chat */
        $chat = Chat::forCompany($companyNit)->findOrFail($id);

        $contact = $chat->contact_id
            ? Contact::forCompany($companyNit)->find($chat->contact_id)
            : null;

        $orders = Order::forCompany($companyNit)
            ->where('client_phone', $chat->client_phone)
            ->orderByDesc('ordered_at')
            ->limit(50)
            ->get(['id', 'status', 'order_type', 'total', 'discount_amount', 'items', 'ordered_at']);

        return response()->json([
            'data' => [
                'contact' => [
                    'id' => $contact?->id,
                    'name' => $contact?->name ?? $chat->client_name,
                    'phone' => $chat->client_phone,
                    'notes' => $contact?->notes,
                ],
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'order_type' => $order->order_type,
                    'total' => (float) $order->total,
                    'discount_amount' => (float) $order->discount_amount,
                    'items_count' => is_array($order->items) ? count($order->items) : 0,
                    'ordered_at' => $order->ordered_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    public function storeMessage(StoreChatMessageRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = Chat::forCompany($companyNit)->findOrFail($id);

        $now = now();

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'operator',
            'body' => $request->string('body')->toString(),
            'sent_at' => $now,
        ]);

        // Cuando el operador interviene manualmente pausamos el bot para evitar
        // respuestas automaticas mientras dura la conversacion humana.
        $chat->update([
            'last_message_at' => $now,
            'bot_paused' => true,
        ]);

        // Si la conversacion vino de WhatsApp, entregamos el mensaje al cliente
        // por la Cloud API. Sincrono: el operador necesita feedback inmediato
        // si fallo (numero invalido, ventana de 24h cerrada, etc.).
        if ($chat->source === 'whatsapp') {
            WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
            $message->refresh();
        }

        return response()->json([
            'data' => new ChatMessageResource($message),
        ], 201);
    }

    public function updateBot(UpdateChatBotRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = Chat::forCompany($companyNit)->findOrFail($id);

        $paused = $request->boolean('paused');

        $chat->update([
            'bot_paused' => $paused,
            // Reanudar limpia el handoff pendiente.
            'handoff_requested_at' => $paused ? $chat->handoff_requested_at : null,
            'handoff_reason' => $paused ? $chat->handoff_reason : null,
        ]);

        return response()->json([
            'data' => new ChatResource($chat->fresh()),
        ]);
    }

    public function updateContact(UpdateChatContactRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        /** @var Chat $chat */
        $chat = Chat::forCompany($companyNit)->findOrFail($id);

        $name = $request->filled('name') ? $request->string('name')->toString() : null;
        $phone = $request->filled('phone') ? $request->string('phone')->toString() : $chat->client_phone;
        $notes = $request->filled('notes') ? $request->string('notes')->toString() : null;

        DB::transaction(function () use ($chat, $companyNit, $name, $phone, $notes): void {
            // Si se cambia el telefono, evita colision con otro contacto en la misma empresa.
            if ($phone !== $chat->client_phone) {
                $exists = Contact::forCompany($companyNit)
                    ->where('phone', $phone)
                    ->where('id', '!=', $chat->contact_id)
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'phone' => ['Ya existe un contacto con ese numero en la empresa.'],
                    ]);
                }
            }

            $contact = Contact::firstOrNew([
                'company_nit' => $companyNit,
                'phone' => $phone,
            ]);
            if (! $contact->exists) {
                $contact->branch_id = (string) $request->attributes->get('active_branch_id');
            }
            if ($name !== null) {
                $contact->name = $name;
            }
            if ($notes !== null) {
                $contact->notes = $notes;
            }
            $contact->save();

            $chat->client_phone = $phone;
            $chat->contact_id = $contact->id;
            if ($name !== null) {
                $chat->client_name = $name;
            }
            $chat->save();
        });

        return response()->json([
            'data' => new ChatResource($chat->fresh()),
        ]);
    }

    /**
     * Reasigna un chat hacia otra sede de la misma empresa (#192).
     *
     * Política: el chat permanece único por (company_nit, client_phone) — su
     * `branch_id` indica cuál sede LO ATIENDE actualmente. Solo los usuarios
     * con acceso a esa sede lo ven en su bandeja (BranchScope natural).
     *
     * Reglas de autorización:
     *  - Owner (rol cuyo nombre == `config('roles.role_names.owner')`):
     *    siempre puede reasignar. OJO: `role.is_system=true` cobija owner,
     *    admin Y employee (los 3 roles institucionales), así que NO sirve como
     *    señal de "owner" para este permiso sensible de sede — `admin`/`employee`
     *    deben pasar por el permiso explícito. Se detecta el owner por nombre,
     *    igual que `UserRoleController::authorizeManagerRole` (#192).
     *  - Otros roles (incl. admin/employee): requieren permiso
     *    `chats.reassign_branch` Y tener acceso (vía `branch_users`) a la sede
     *    destino. No se puede "tomar" un chat hacia una sede a la que el actor
     *    no llega.
     *
     * Auditoría: `chat.reassigned` con `from_branch_id`, `to_branch_id`,
     * `reason`. `AuditService::log` agrega `branch_id` y
     * `actor_active_branch_id` automáticamente.
     */
    public function reassignBranch(Request $request, AuditService $audit, string $id): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $payload = (array) $request->attributes->get('jwt_payload', []);
        // `is_system` es true para owner, admin Y employee → no distingue al
        // owner. Para este permiso sensible de sede el bypass es owner-only:
        // se detecta por nombre del rol (patrón canónico UserRoleController).
        $isOwner = ($payload['role']['name'] ?? null) === config('roles.role_names.owner');
        $permissions = (array) ($payload['permissions'] ?? []);

        if (! $isOwner && ! in_array('chats.reassign_branch', $permissions, true)) {
            return response()->json([
                'message' => 'No tienes permiso para reasignar chats entre sedes.',
                'code' => 'CHAT_REASSIGN_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'string', 'uuid'],
            'reason' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        /** @var Chat $chat */
        $chat = Chat::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('id', $id)
            ->firstOrFail();

        $target = Branch::query()
            ->where('company_nit', $companyNit)
            ->where('id', $validated['branch_id'])
            ->whereNull('archived_at')
            ->first();

        if ($target === null) {
            return response()->json([
                'message' => 'Sede destino no encontrada en esta empresa.',
                'code' => 'BRANCH_NOT_FOUND',
            ], 404);
        }

        // Autorización composable documentada en la ruta: owner O
        // (chats.reassign_branch + ACCESO a la sede destino). Sin este check
        // un no-owner podía mover chats hacia sedes donde no opera.
        if (! $isOwner) {
            $hasTargetAccess = BranchUser::query()
                ->where('branch_id', $target->id)
                ->where('user_id', (string) ($payload['sub'] ?? ''))
                ->exists();

            if (! $hasTargetAccess) {
                return response()->json([
                    'message' => 'No tienes acceso a la sede destino.',
                    'code' => 'CHAT_REASSIGN_TARGET_FORBIDDEN',
                ], 403);
            }
        }

        if ($chat->branch_id === $target->id) {
            return response()->json([
                'data' => new ChatResource($chat),
                'message' => 'El chat ya pertenece a la sede solicitada.',
            ]);
        }

        // No-owner: además del permiso, debe tener acceso real a la sede
        // destino vía `branch_users`. Owner pasa por bypass.
        if (! $isOwner) {
            $accessible = collect($payload['branches'] ?? [])->pluck('id')->all();
            if (! in_array($target->id, $accessible, true)) {
                return response()->json([
                    'message' => 'No tienes acceso a la sede destino.',
                    'code' => 'BRANCH_NOT_ACCESSIBLE',
                ], 403);
            }
        }

        $fromBranchId = $chat->branch_id;

        DB::transaction(function () use ($chat, $target): void {
            $chat->branch_id = $target->id;
            $chat->save();
        });

        $actor = isset($payload['sub']) ? User::find((string) $payload['sub']) : null;

        $audit->log(
            action: 'chat.reassigned',
            user: $actor,
            auditable: $chat->fresh(),
            data: [
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $target->id,
                'reason' => $validated['reason'] ?? null,
            ],
            request: $request,
        );

        return response()->json([
            'data' => new ChatResource($chat->fresh()),
        ]);
    }
}
