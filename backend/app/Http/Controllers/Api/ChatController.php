<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChatAttachmentRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\UpdateChatBotRequest;
use App\Http\Requests\Chat\UpdateChatContactRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatResource;
use App\Jobs\MarkWhatsappMessageReadJob;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CartSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\Chat\ChatAuditLogger;
use App\Services\CompanySettingsService;
use App\Services\FeaturePermissionService;
use App\Services\Whatsapp\AutomationDispatcher;
use App\Services\Whatsapp\WhatsappOutboundMessageSender;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        private readonly ChatAuditLogger $auditLogger,
    ) {}

    /**
     * Resuelve un chat de la empresa activa o aborta con 404 auditado.
     *
     * Punto único de acceso del módulo (§7.5 capa 3): concentra el scope de
     * empresa, la respuesta y la auditoría del rechazo. Que exista un solo
     * camino es lo que hace verificable la regla — ocho `findOrFail` sueltos se
     * mantienen sincronizados por disciplina, esto por construcción.
     *
     * **404, nunca 403** (§7.5 capa 6): un 403 confirmaría que el chat existe en
     * otra empresa, que es justo lo que el aislamiento tiene que ocultar. El 403
     * queda reservado para la falta de permiso, que no revela nada.
     *
     * @param  list<string>  $with
     */
    private function findChatOrDeny(Request $request, string $companyNit, string $id, array $with = []): Chat
    {
        // El id se valida ANTES de consultar. `chats.id` es `uuid`, y un valor
        // que no lo sea hace fallar a PostgreSQL con 22P02 → 500 con el error de
        // la base en el cuerpo (motor, tipo de columna y forma del query). Eso
        // rompe la regla de §7.5 capa 6 —toda falla responde 404— y además
        // convierte el status en un oráculo: 500 para malformado, 404 para bien
        // formado. Verificado en F2b: `GET /chats/no-es-uuid` devolvía 500.
        $chat = Str::isUuid($id)
            ? (function () use ($companyNit, $with, $id): ?Chat {
                $query = Chat::forCompany($companyNit);

                if ($with !== []) {
                    $query->with($with);
                }

                return $query->find($id);
            })()
            : null;

        if ($chat === null) {
            // ponytail: no se consulta si el chat existe en OTRA empresa. Daría
            // una señal más fina (ataque vs. link viejo), pero cuesta una query
            // en el camino de error y mete un dato cross-tenant en una tabla
            // inmutable. La repetición del patrón ya distingue los dos casos.
            $this->auditLogger->log(
                action: 'chat.access.denied',
                user: $this->actor($request),
                data: [
                    'chat_id' => $id,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $id,
            );

            abort(404);
        }

        return $chat;
    }

    /** Ventana de presencia. El refresco de la bandeja es de 30 s: 90 s tolera un poll perdido. */
    private const PRESENCE_TTL_SECONDS = 90;

    private static function presenceKey(string $chatId): string
    {
        return "chat:{$chatId}:viewers";
    }

    /**
     * Deja constancia de que este usuario tiene la conversacion abierta.
     *
     * Un solo registro por chat con el mapa de usuarios, no una clave por
     * usuario: el store `database` no sabe listar por prefijo, asi que con
     * claves sueltas no habria forma de leer "quienes estan viendo".
     *
     * ponytail: read-modify-write sin lock. Con dos instancias escribiendo a la
     * vez se puede perder un visor hasta el siguiente poll de 30 s. El costo de
     * un lock por cada apertura de chat no lo compensa; si algun dia hace falta
     * exactitud, la salida es `Cache::lock`, no una tabla.
     */
    private function touchPresence(Chat $chat, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $key = self::presenceKey((string) $chat->id);
        $viewers = (array) Cache::get($key, []);
        $now = now()->timestamp;

        $viewers[(string) $user->id] = ['name' => (string) $user->name, 'at' => $now];

        // Poda en la escritura: sin esto el mapa crece con cada operador que
        // paso alguna vez por el chat y nunca se limpia.
        $viewers = array_filter(
            $viewers,
            static fn ($v) => is_array($v) && ($now - (int) ($v['at'] ?? 0)) < self::PRESENCE_TTL_SECONDS,
        );

        Cache::put($key, $viewers, self::PRESENCE_TTL_SECONDS);
    }

    /**
     * Quienes mas estan viendo la conversacion, sin contar a quien pregunta.
     *
     * @return list<string> nombres
     */
    private function viewersOf(Chat $chat, ?User $user): array
    {
        $viewers = (array) Cache::get(self::presenceKey((string) $chat->id), []);
        $now = now()->timestamp;
        $selfId = (string) ($user?->id ?? '');

        $names = [];

        foreach ($viewers as $userId => $entry) {
            if ((string) $userId === $selfId || ! is_array($entry)) {
                continue;
            }

            if (($now - (int) ($entry['at'] ?? 0)) >= self::PRESENCE_TTL_SECONDS) {
                continue;
            }

            $names[] = (string) ($entry['name'] ?? '');
        }

        return array_values(array_filter($names, static fn (string $n) => $n !== ''));
    }

    /** El actor sale del JWT ya validado, nunca del body. */
    private function actor(Request $request): ?User
    {
        $payload = (array) $request->attributes->get('jwt_payload', []);

        return isset($payload['sub']) ? User::find((string) $payload['sub']) : null;
    }

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        // Validamos el parametro de busqueda: string corto. Esto cierra abuso
        // por entradas enormes que disparen full table scans con ILIKE.
        $validated = $request->validate([
            'q' => ['nullable', new SafePlainText(maxBytes: 100)],
            // Chips de la bandeja (§8.4b punto 2).
            'filter' => ['nullable', 'string', 'in:pending,all,closed'],
            'channel_id' => ['nullable', 'uuid'],
        ]);
        $term = trim((string) ($validated['q'] ?? ''));
        $filter = (string) ($validated['filter'] ?? 'all');

        // El tope era 5 sin busqueda, pensado para una bandeja que solo mostraba
        // "los ultimos". Con chips y orden por urgencia, 5 no es un orden: es un
        // recorte que esconde justo al cliente que mas espera. 50 cubre el turno
        // de un restaurante sin paginar.
        $limit = $term === '' ? 50 : 100;

        $query = Chat::forCompany($companyNit)
            // `contact` (solo id+name) para que la bandeja muestre el nombre
            // CANONICO del contacto (el que se edita en /clients), no el snapshot
            // viejo de `chats.client_name`: sin esto el mismo cliente aparece con
            // nombres distintos entre el hilo de SMS, el de WhatsApp y /clients.
            // Sin BranchScope: el contacto puede estar en otra sede que el chat y
            // el nombre debe resolverse igual.
            ->with(['latestMessage', 'whatsappAccount', 'contact' => function ($q): void {
                $q->withoutGlobalScope(BranchScope::class)->select('id', 'name');
            }])
            // Prioridad real (§8.4b punto 2): primero los que esperan respuesta,
            // el que espera hace mas tiempo arriba. Postgres pone los NULL al
            // final con ASC NULLS LAST, que es exactamente "los ya respondidos
            // van despues". Recien dentro de cada grupo ordena por actividad.
            ->orderByRaw('pending_reply_since ASC NULLS LAST')
            ->orderByDesc('last_message_at')
            ->limit($limit);

        if ($filter === 'pending') {
            $query->whereNotNull('pending_reply_since')->where('status', 'open');
        } elseif ($filter === 'closed') {
            $query->where('status', 'closed');
        }

        if (! empty($validated['channel_id'])) {
            // El canal se filtra sin verificar que sea de la empresa: no hace
            // falta. La consulta ya esta scopeada por `company_nit`, asi que un
            // id ajeno devuelve vacio en vez de filtrar nada (§7.5 capa 3).
            $query->where('whatsapp_account_id', $validated['channel_id']);
        }

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

        // Los canales viajan con la bandeja y no en un request aparte: el filtro
        // por canal solo aparece con dos o mas (§8.4 punto 1), asi que el
        // frontend necesita el conteo antes de decidir si dibujar los chips.
        // Una consulta corta contra una tabla de a lo sumo una fila por sede.
        $channels = CompanyWhatsappAccount::query()
            ->where('company_nit', $companyNit)
            ->whereNotNull('evo_instance')
            ->orderByRaw('branch_id IS NOT NULL')
            ->get(['id', 'label', 'status', 'branch_id', 'phone_e164']);

        return response()->json([
            'data' => ChatResource::collection($chats),
            'meta' => [
                'pending_count' => $this->pendingCountFor($companyNit),
                'channels' => $channels->map(fn (CompanyWhatsappAccount $c) => [
                    'id' => $c->id,
                    'label' => $c->label,
                    'status' => $c->status,
                    'phone_e164' => $c->phone_e164,
                ])->all(),
            ],
        ]);
    }

    /**
     * Contador para el badge del sidebar y el titulo de pestaña (§8.4b punto 1).
     *
     * Endpoint propio y minimo porque el badge vive FUERA de la bandeja: el
     * operador tiene el panel en otra pestaña o en otra pantalla del panel, que
     * es justo cuando el aviso hace falta. Devolver la lista entera para contar
     * seria mover kilobytes cada minuto por un entero.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        return response()->json(['data' => ['pending' => $this->pendingCountFor($companyNit)]]);
    }

    /**
     * Conversaciones abiertas esperando respuesta.
     *
     * Usa `chats_pending_reply_idx`, el indice parcial que creo F1: el
     * `whereNotNull` es lo que hace que Postgres pueda aplicarlo. El
     * `BranchScope` global sigue activo, asi que el operador de Sede Norte
     * cuenta lo suyo y no lo de Sede Sur.
     */
    private function pendingCountFor(string $companyNit): int
    {
        return Chat::forCompany($companyNit)
            ->whereNotNull('pending_reply_since')
            ->where('status', 'open')
            ->count();
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = $this->findChatOrDeny($request, $companyNit, $id, ['messages.sentBy:id,name', 'contact', 'whatsappAccount']);

        // Presencia liviana (§5.7): quien mas tiene abierta esta conversacion.
        // Sobre la cache que ya existe, sin tabla ni WebSocket. El objetivo real
        // es que dos personas no escriban lo mismo, no el tiempo real — y para
        // eso alcanza con una ventana de 60 s.
        $chat->setAttribute('viewers', $this->viewersOf($chat, $this->actor($request)));

        // Dedupe de 30 min: el operador entra y sale del mismo chat decenas de
        // veces por turno. El listado de la bandeja NO se audita (§7.6).
        $this->auditLogger->log(
            action: 'chat.viewed',
            user: $this->actor($request),
            auditable: $chat,
            data: ['chat_id' => $chat->id, 'company_nit' => $companyNit],
            request: $request,
            dedupeKey: $chat->id,
        );

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

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        // Presencia ANTES de cualquier return temprano (§5.7). Si se registrara
        // despues del check de read receipts, la presencia solo funcionaria para
        // las empresas que tienen el doble chulito activado — dos cosas sin
        // relacion atadas por el orden de las lineas.
        $this->touchPresence($chat, $this->actor($request));

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

        // El read receipt sale por el canal que origino la conversacion (F1).
        $channel = $chat->resolveWhatsappChannel();
        if ($channel === null) {
            return response()->json(['skipped' => 'no_channel']);
        }

        Cache::put($cacheKey, $latestInbound->meta_message_id, now()->addMinutes(5));

        MarkWhatsappMessageReadJob::dispatch(
            $channel->id,
            (string) $latestInbound->meta_message_id,
            (string) $chat->client_phone,
        );

        return response()->json([
            'dispatched' => true,
            'meta_message_id' => $latestInbound->meta_message_id,
        ]);
    }

    /**
     * CIBER-05: stream autenticado de la media de un mensaje de chat. Antes se
     * servía por el proxy anónimo `/storage-proxy/chat-media/*` (sin auth ni
     * scope de empresa). Ahora resuelve el mensaje scopeado a la empresa activa
     * + `chats.read` y recién ahí firma la URL S3 (302, TTL corto). El `<img>`
     * del SPA adjunta la cookie JWT (SameSite=None) → autentica.
     */
    public function mediaUrl(Request $request, string $id, string $messageId): RedirectResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        // IDOR de §7.5: el `messageId` se resuelve SIEMPRE dentro del chat ya
        // scopeado por empresa. Sin el `where('chat_id')`, un operador legítimo
        // de la empresa A podría bajar la media de un mensaje de B conociendo
        // solo su UUID — y los UUID viajan en las respuestas de la API.
        // Misma guarda que para el chat: `chat_messages.id` tambien es `uuid`.
        $message = Str::isUuid($messageId)
            ? ChatMessage::query()
                ->whereKey($messageId)
                ->where('chat_id', $chat->id)
                ->whereNotNull('media_path')
                ->first()
            : null;

        if ($message === null) {
            // Mismo 404 que un chat ajeno: pedir la media de otro chat no puede
            // distinguirse de pedir una que no existe.
            $this->auditLogger->log(
                action: 'chat.access.denied',
                user: $this->actor($request),
                data: [
                    'chat_id' => $chat->id,
                    'message_id' => $messageId,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $messageId,
            );

            abort(404);
        }

        // SIN dedupe por acceso sería inviable: `media_url` se regenera en cada
        // poll de 30 s y el browser no cachea el 302 a la prefirmada, así que la
        // bandeja dispara un GET por imagen por poll. El dedupe es por mensaje,
        // no por chat: conserva qué media se abrió, que es el dato sensible.
        $this->auditLogger->log(
            action: 'chat.media.viewed',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'media_type' => $message->media_type,
                'company_nit' => $companyNit,
            ],
            request: $request,
            dedupeKey: $message->id,
        );

        $disk = (string) config('filesystems.default');
        $url = Storage::disk($disk)
            ->temporaryUrl($message->media_path, now()->addMinutes(15));

        return redirect()->away($url, 302);
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

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        // La ficha expone teléfono, notas e historial de pedidos: es la vista
        // con más PII del módulo y por eso se audita aunque sea de lectura.
        $this->auditLogger->log(
            action: 'chat.client.viewed',
            user: $this->actor($request),
            auditable: $chat,
            data: ['chat_id' => $chat->id, 'contact_id' => $chat->contact_id, 'company_nit' => $companyNit],
            request: $request,
            dedupeKey: $chat->id,
        );

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

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        $now = now();
        $payload = (array) $request->attributes->get('jwt_payload', []);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'operator',
            // Autoria por mensaje: con varios operadores en la misma bandeja,
            // `sender='operator'` a secas no dice quien le respondio que al
            // cliente.
            'sent_by_user_id' => $payload['sub'] ?? null,
            'body' => $request->string('body')->toString(),
            'sent_at' => $now,
        ]);

        // Cuando el operador interviene manualmente pausamos el bot para evitar
        // respuestas automaticas mientras dura la conversacion humana.
        // `pending_reply_since` vuelve a null: el cliente ya no espera.
        $chat->update([
            'last_message_at' => $now,
            'pending_reply_since' => null,
            'bot_paused' => true,
        ]);

        // Si la conversacion vino de WhatsApp, entregamos el mensaje al cliente
        // por la Cloud API. Sincrono: el operador necesita feedback inmediato
        // si fallo (numero invalido, ventana de 24h cerrada, etc.).
        if ($chat->source === 'whatsapp') {
            WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
            $message->refresh();
        }

        // Sin dedupe: cada mensaje al cliente es un hecho distinto. Se guarda la
        // LONGITUD, nunca el cuerpo — ya vive en `chat_messages` y duplicarlo en
        // una tabla inmutable multiplica la exposición (§7.6).
        $this->auditLogger->log(
            action: 'chat.message.sent',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
                'company_nit' => $companyNit,
                'body_length' => mb_strlen((string) $message->body),
                'status' => $message->status,
            ],
            request: $request,
        );

        return response()->json([
            'data' => new ChatMessageResource($message),
        ], 201);
    }

    /**
     * Adjunto saliente (§6.7). El archivo sube a S3 y Evolution lo consume por
     * URL prefirmada de TTL corto — nunca se le manda el base64, que obligaría a
     * empujar 16 MB por PHP-FPM en una instancia de 2 GB.
     *
     * La validación (MIME real por finfo, lista blanca, tope de 16 MB) vive en
     * StoreChatAttachmentRequest. La UI del compositor llega en F3.
     */
    public function storeAttachment(StoreChatAttachmentRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        $file = $request->file('file');
        $type = (string) $request->input('type');
        $caption = (string) $request->input('caption', '');
        $fileName = $request->safeFileName();
        $now = now();
        $payload = (array) $request->attributes->get('jwt_payload', []);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'operator',
            'sent_by_user_id' => $payload['sub'] ?? null,
            'body' => trim('['.$type.'] '.$fileName.' '.$caption),
            'media_type' => $type,
            'media_mime' => $file->getMimeType(),
            'media_payload' => array_filter([
                'file_name' => $fileName !== '' ? $fileName : null,
                'size_bytes' => $file->getSize(),
                'caption' => $caption !== '' ? $caption : null,
                'ptt' => $type === 'audio' ? $request->boolean('voice_note') : null,
            ], static fn ($v) => $v !== null),
            'sent_at' => $now,
        ]);

        // La CLAVE en S3 la genera el servidor con los UUID: el nombre que mandó
        // el cliente nunca toca el keyspace.
        $extension = $file->extension() ?: 'bin';
        $path = sprintf('chat-media/%s/%s.%s', $chat->id, $message->id, $extension);
        Storage::disk((string) config('filesystems.default'))->put($path, $file->get());

        $message->forceFill(['media_path' => $path])->save();

        $chat->update([
            'last_message_at' => $now,
            'pending_reply_since' => null,
            'bot_paused' => true,
        ]);

        if ($chat->source === 'whatsapp') {
            WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
            $message->refresh();
        }

        // Sin dedupe: cada mensaje al cliente es un hecho distinto. Se guarda la
        // LONGITUD, nunca el cuerpo — ya vive en `chat_messages` y duplicarlo en
        // una tabla inmutable multiplica la exposición (§7.6).
        $this->auditLogger->log(
            action: 'chat.message.sent',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
                'company_nit' => $companyNit,
                'body_length' => mb_strlen((string) $message->body),
                'status' => $message->status,
            ],
            request: $request,
        );

        return response()->json([
            'data' => new ChatMessageResource($message),
        ], 201);
    }

    /**
     * Reintenta un mensaje que quedo en `failed` (§8.4b punto 4).
     *
     * Hoy un fallo se pinta de rojo y muere ahi: el operador no tiene forma de
     * reenviar salvo reescribir el mensaje entero, y si era un adjunto lo tiene
     * que volver a subir.
     *
     * Reintenta el MISMO registro en vez de crear uno nuevo: duplicar la fila
     * dejaria dos burbujas en la conversacion —una fallida y una enviada— por un
     * solo mensaje que el cliente ve una vez.
     */
    public function retryMessage(Request $request, string $id, string $messageId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        // Mismo IDOR que la media (§7.5): el `messageId` se resuelve SIEMPRE
        // dentro del chat ya scopeado, y el id se valida antes de tocar la base.
        $message = Str::isUuid($messageId)
            ? ChatMessage::query()
                ->whereKey($messageId)
                ->where('chat_id', $chat->id)
                ->first()
            : null;

        if ($message === null) {
            $this->auditLogger->log(
                action: 'chat.access.denied',
                user: $this->actor($request),
                data: [
                    'chat_id' => $chat->id,
                    'message_id' => $messageId,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $messageId,
            );

            abort(404);
        }

        if ($message->status !== 'failed') {
            return response()->json([
                'message' => 'Ese mensaje no falló: no hay nada que reintentar.',
                'code' => 'MESSAGE_NOT_FAILED',
            ], 409);
        }

        if ($message->sender === 'client') {
            return response()->json([
                'message' => 'No se reenvían mensajes del cliente.',
                'code' => 'MESSAGE_NOT_OUTBOUND',
            ], 409);
        }

        // Se limpia el motivo anterior: si vuelve a fallar, el tooltip tiene que
        // mostrar por que fallo AHORA, no la causa de la vez pasada.
        $message->forceFill(['status' => null, 'failure_reason' => null])->save();

        if ($chat->source === 'whatsapp') {
            WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
            $message->refresh();
        }

        $this->auditLogger->log(
            action: 'chat.message.retried',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
                'company_nit' => $companyNit,
                'status' => $message->status,
                'failure_reason' => $message->failure_reason,
            ],
            request: $request,
        );

        return response()->json([
            'data' => new ChatMessageResource($message),
        ]);
    }

    /**
     * Link corto de carta con sesión de seguimiento (unifica "enviar la carta"
     * y "enviar carrito", antes cartLink con CartJWT de ~600 chars a
     * pedidos.flexyflow.co).
     *
     * Crea una `CartSession` ligada al chat (`chat_id`) con un token UUID en
     * `jwt_jti` y devuelve el token; el frontend arma la URL corta
     * `/menus?cart={token}`. Cuando el cliente confirma el pedido desde la
     * carta, `BranchOrderController::store` convierte la sesión y precarga en
     * la conversación lo que seleccionó (ChatMessage con el resumen).
     */
    public function menuLink(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        // Sede cuya carta se envía: la del chat; fallback a la sede activa del
        // operador. El checkout público necesita el menu_qr_token de la sede.
        $branchId = $chat->branch_id ?? $request->attributes->get('active_branch_id');
        $branch = $branchId !== null
            ? Branch::query()
                ->whereKey($branchId)
                ->where('company_nit', $companyNit)
                ->whereNull('archived_at')
                ->first()
            : null;

        if ($branch === null || (string) $branch->menu_qr_token === '') {
            return response()->json([
                'message' => 'La sede no tiene carta digital configurada.',
                'code' => 'MENU_TOKEN_MISSING',
            ], 409);
        }

        $ttlHours = max(1, (int) config('bot.menu_link_ttl_hours', 24));

        $session = CartSession::create([
            'jwt_jti' => (string) Str::uuid(),
            'company_nit' => $companyNit,
            'branch_id' => (string) $branch->id,
            'chat_id' => $chat->id,
            'client_phone' => (string) $chat->client_phone,
            'status' => 'active',
            'expired_at' => now()->addHours($ttlHours),
        ]);

        $this->auditLogger->log(
            action: 'chat.menu_link.sent',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'cart_session_id' => $session->id,
                'branch_id' => (string) $branch->id,
            ],
            request: $request,
        );

        return response()->json([
            'data' => [
                'token' => $session->jwt_jti,
                'expires_at' => $session->expired_at?->toIso8601String(),
            ],
        ]);
    }

    public function updateBot(UpdateChatBotRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        $paused = $request->boolean('paused');
        $wasPaused = (bool) $chat->bot_paused;

        $chat->update([
            'bot_paused' => $paused,
            // Reanudar limpia el handoff pendiente.
            'handoff_requested_at' => $paused ? $chat->handoff_requested_at : null,
            'handoff_reason' => $paused ? $chat->handoff_reason : null,
        ]);

        // El toggle es último-en-escribir-gana (§7.5.2): con dos operadores en el
        // mismo chat, la auditoría es lo único que reconstruye quién lo dejó como
        // está. Por eso va sin dedupe y con el valor anterior.
        $this->auditLogger->log(
            action: 'chat.bot.toggled',
            user: $this->actor($request),
            auditable: $chat,
            data: [
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'from_paused' => $wasPaused,
                'to_paused' => $paused,
            ],
            request: $request,
        );

        // F6 (§9.2): avisar a n8n del toggle si hay flujo suscrito.
        app(AutomationDispatcher::class)->forChat(
            AutomationDispatcher::EVENT_BOT_TOGGLED,
            $chat,
            null,
            ['bot_paused' => $paused],
        );

        return response()->json([
            'data' => new ChatResource($chat->fresh()),
        ]);
    }

    public function updateContact(UpdateChatContactRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        $chat = $this->findChatOrDeny($request, $companyNit, $id);

        $name = $request->filled('name') ? $request->string('name')->toString() : null;
        // Canónico `57...` antes del lookup del contacto: el firstOrNew/where de
        // abajo compara contra el valor guardado (que ya es canónico por el
        // mutator), y sin normalizar aquí no matchearía y duplicaría el contacto.
        $phone = $request->filled('phone')
            ? PhoneNumber::toColombianCanonical($request->string('phone')->toString())
            : $chat->client_phone;
        $notes = $request->filled('notes') ? $request->string('notes')->toString() : null;

        $before = [
            'name' => $chat->client_name,
            'phone' => $chat->client_phone,
        ];

        // `$request` FALTABA en el `use` y la closure lo referenciaba abajo para
        // resolver `active_branch_id`. Cualquier PATCH que crease un contacto
        // nuevo —contacto inexistente, o teléfono cambiado a uno no registrado—
        // reventaba con "Undefined variable $request" y devolvía 500. Bug
        // preexistente: está igual en HEAD, no lo introduce F2b.
        DB::transaction(function () use ($request, $chat, $companyNit, $name, $phone, $notes): void {
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

        // ÚNICA excepción a la regla "solo identificadores" de §7.6: el
        // before/after ES el cambio auditado — sin los valores, la fila no dice
        // nada. `_pii_exempt` lo hace explícito para que se lea como una
        // decisión y no como un filtro que se olvidaron de aplicar.
        $this->auditLogger->log(
            action: 'chat.contact.updated',
            user: $this->actor($request),
            auditable: $chat->fresh(),
            data: [
                '_pii_exempt' => true,
                'chat_id' => $chat->id,
                'company_nit' => $companyNit,
                'before' => $before,
                'after' => ['name' => $name ?? $before['name'], 'phone' => $phone],
            ],
            request: $request,
        );

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

        // `withoutBranchScope()` es deliberado y NO es un hueco: reasignar exige
        // ver un chat que hoy está en otra sede. El aislamiento de EMPRESA sigue
        // duro por el `where('company_nit')` explícito, que es la capa que de
        // verdad separa clientes (§7.5 capa 3).
        /** @var ?Chat $chat */
        $chat = Chat::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('id', $id)
            ->first();

        if ($chat === null) {
            $this->auditLogger->log(
                action: 'chat.access.denied',
                user: $this->actor($request),
                data: [
                    'chat_id' => $id,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $id,
            );

            abort(404);
        }

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
