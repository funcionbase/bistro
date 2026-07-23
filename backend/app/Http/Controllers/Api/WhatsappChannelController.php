<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Whatsapp\StoreWhatsappChannelRequest;
use App\Http\Resources\WhatsappChannelResource;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\User;
use App\Services\Chat\ChatAuditLogger;
use App\Services\FeaturePermissionService;
use App\Services\Whatsapp\EvolutionChannelService;
use App\Services\Whatsapp\WhatsappOutboundMessageSender;
use App\Services\Whatsapp\WhatsappVerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Canales de WhatsApp de la empresa activa — uno por sede, más el de empresa
 * (plan 8-whatsapp.md §5.2, §6.5, §8.2, §8.3).
 *
 *   GET    /api/v1/whatsapp/channels                     (whatsapp.read)
 *   POST   /api/v1/whatsapp/channels                     (whatsapp.connect)
 *   GET    /api/v1/whatsapp/channels/{id}/state          (whatsapp.read)
 *   GET    /api/v1/whatsapp/channels/{id}/qr             (whatsapp.connect)
 *   POST   /api/v1/whatsapp/channels/{id}/pairing-code   (whatsapp.connect)
 *   POST   /api/v1/whatsapp/channels/{id}/test-message   (whatsapp.connect)
 *   DELETE /api/v1/whatsapp/channels/{id}                (whatsapp.disconnect + OTP)
 *
 * Convive con `WhatsappAccountController`, que sigue sirviendo el camino Meta
 * Cloud API hasta el corte de F4. Las dos leen la misma tabla; ninguna escribe
 * las columnas de la otra.
 *
 * **Autorización composable** (§7.3), porque el slug del permiso no matchea la
 * convención CRUD del middleware:
 *  - canal de EMPRESA: `whatsapp.connect` + owner/admin;
 *  - canal de SEDE: `whatsapp.connect` + `whatsapp.manage_branch_channels` +
 *    acceso real a esa sede vía `branch_users`.
 *
 * El middleware de ruta cubre el permiso base; el reparto empresa/sede se
 * resuelve acá porque depende del `branch_id` del cuerpo, que el middleware no
 * mira.
 */
class WhatsappChannelController extends Controller
{
    /** Un canal en `pending` más viejo que esto es basura de un wizard abandonado (§8.4b punto 5). */
    private const PENDING_TTL_HOURS = 24;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly ChatAuditLogger $auditLogger,
        private readonly WhatsappVerificationCodeService $verificationService,
    ) {}

    /**
     * Lista los canales + las sedes que todavía no tienen uno.
     *
     * Las sedes sin canal viajan en `meta` y no en `data` a propósito: no son
     * canales, son huecos. La UI las pinta como placeholder accionable (§8.2),
     * que es lo que convierte "configurar" en una acción evidente en vez de
     * esconderla dentro de un menú.
     */
    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $channels = CompanyWhatsappAccount::query()
            ->with('branch:id,name')
            ->where('company_nit', $companyNit)
            // Solo canales de Evolution: las filas viejas de Meta las sigue
            // sirviendo `WhatsappAccountController` hasta F4. Sin este filtro la
            // lista mezclaría dos modelos de conexión con acciones distintas.
            ->whereNotNull('evo_instance')
            ->orderByRaw('branch_id IS NOT NULL')
            ->orderBy('created_at')
            ->get();

        $this->attachActivity($channels->all());

        $branches = Branch::query()
            ->where('company_nit', $companyNit)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        $taken = $channels->pluck('branch_id')->filter()->all();
        $accessible = $this->accessibleBranchIds($request);

        $missing = $branches
            ->reject(fn (Branch $b) => in_array($b->id, $taken, true))
            // Un jefe de sede no puede conectar sedes ajenas: mostrarle el
            // placeholder sería ofrecerle un botón que el backend le va a negar.
            ->filter(fn (Branch $b) => $accessible === null || in_array($b->id, $accessible, true))
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])
            ->values();

        $hasCompanyChannel = $channels->contains(fn (CompanyWhatsappAccount $c) => $c->branch_id === null);

        return response()->json([
            'data' => WhatsappChannelResource::collection($channels),
            'meta' => [
                'branches_without_channel' => $missing,
                'branch_count' => $branches->count(),
                'has_company_channel' => $hasCompanyChannel,
                'connected_count' => $channels->where('status', 'connected')->count(),
                // El wizard se salta el paso 1 cuando no hay decisión que tomar
                // (§8.3): una sola sede y sin canal de empresa.
                'can_manage_company_channel' => $this->isPrivileged($request),
            ],
        ]);
    }

    /**
     * Crea el canal y lo deja en `pending` esperando el escaneo.
     *
     * No conecta nada: el QR se pide después, en el paso 2 del wizard. Separar
     * los dos momentos es lo que permite retomar un canal abandonado desde la
     * tarjeta sin volver a empezar (§8.4b punto 5).
     */
    public function store(StoreWhatsappChannelRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'connect');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = $request->input('branch_id');

        $branch = null;

        if ($branchId !== null && $branchId !== '') {
            $branch = Branch::query()
                ->where('company_nit', $companyNit)
                ->where('id', $branchId)
                ->whereNull('archived_at')
                ->first();

            if ($branch === null) {
                // 404 y no 403: confirmar que la sede existe en otra empresa es
                // exactamente lo que el aislamiento tiene que ocultar (§7.5).
                return response()->json([
                    'message' => 'Sede no encontrada en esta empresa.',
                    'code' => 'BRANCH_NOT_FOUND',
                ], 404);
            }
        }

        if (($denied = $this->denyIfCannotManage($request, $branch)) !== null) {
            return $denied;
        }

        // Purga oportunista: si quedó un `pending` viejo del mismo alcance, el
        // unique parcial haría fallar el alta con un 500 de Postgres en vez de
        // dejar reconectar. Se limpia acá y también desde el poll de salud.
        $this->purgeStalePending($companyNit, $branch?->id);

        $existing = CompanyWhatsappAccount::query()
            ->where('company_nit', $companyNit)
            ->when($branch === null, fn ($q) => $q->whereNull('branch_id'))
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('evo_instance')
            ->first();

        if ($existing !== null) {
            // Reutilizar el canal a medio conectar es justo lo que evita el
            // callejón sin salida de §8.4b: el usuario cerró el modal del QR y
            // vuelve a darle a "Conectar".
            if (in_array($existing->status, ['pending', 'verifying'], true)) {
                $this->recordConsent($request, $existing);

                return response()->json([
                    'data' => new WhatsappChannelResource($existing->load('branch:id,name')),
                    'meta' => ['resumed' => true],
                ], 200);
            }

            return response()->json([
                'message' => 'Ese alcance ya tiene un número conectado. Desconectalo antes de conectar otro.',
                'code' => 'CHANNEL_ALREADY_EXISTS',
            ], 409);
        }

        $company = Company::query()->where('nit', $companyNit)->firstOrFail();

        $result = EvolutionChannelService::make()->provision(
            $company,
            $branch,
            $request->filled('label') ? $request->string('label')->toString() : null,
        );

        if (! ($result['ok'] ?? false)) {
            // Error accionable, no toast genérico (§8.2): el problema es del
            // servidor de mensajería, o sea nuestro, y el copy lo dice.
            return response()->json([
                'message' => 'No podemos contactar el servidor de mensajería. Volvé a intentar en un momento.',
                'code' => 'CHANNEL_PROVISION_FAILED',
                'reason' => $result['error'] ?? 'unknown',
            ], 502);
        }

        $account = $result['account'];
        $this->recordConsent($request, $account);

        return response()->json([
            'data' => new WhatsappChannelResource($account->load('branch:id,name')),
        ], 201);
    }

    /**
     * Estado real del canal. Lo pollea el wizard cada 2 s mientras el modal del
     * QR está abierto (§8.3), así que es el endpoint más caliente del módulo:
     * no hace nada más que consultar Evolution y reflejar la fila.
     */
    public function state(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'read');

        $account = $this->findChannelOrDeny($request, $id);

        $result = EvolutionChannelService::make()->syncState($account);

        return response()->json([
            'data' => new WhatsappChannelResource($account->fresh()->load('branch:id,name')),
            'meta' => [
                'evolution_state' => $result['state'],
                'reachable' => $result['ok'],
            ],
        ]);
    }

    /** QR para vincular. El servicio ya audita el acceso: es un secreto de sesión. */
    public function qr(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'connect');

        $account = $this->findChannelOrDeny($request, $id);

        if (($denied = $this->denyIfCannotManage($request, $account->branch)) !== null) {
            return $denied;
        }

        $result = EvolutionChannelService::make()->qr($account);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => 'No pudimos generar el código. Volvé a intentar.',
                'code' => 'QR_UNAVAILABLE',
                'reason' => $result['error'] ?? 'unknown',
            ], 502);
        }

        return response()->json([
            'data' => [
                'qr' => $result['qr'],
                'pairing_code' => $result['pairing_code'] ?? null,
                // El QR de WhatsApp caduca solo; el countdown del wizard se
                // alimenta de acá para no hardcodear el TTL en el frontend.
                'expires_in' => 40,
            ],
        ]);
    }

    /**
     * Código de 8 dígitos: la ruta accesible completa para quien no puede usar
     * la cámara (§8.3). Recrea la instancia apuntando al teléfono, así que es
     * destructivo sobre un canal a medio vincular — no sobre uno conectado.
     */
    public function pairingCode(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'connect');

        $account = $this->findChannelOrDeny($request, $id);

        if (($denied = $this->denyIfCannotManage($request, $account->branch)) !== null) {
            return $denied;
        }

        if ($account->status === 'connected') {
            return response()->json([
                'message' => 'Este canal ya está conectado.',
                'code' => 'CHANNEL_ALREADY_CONNECTED',
            ], 409);
        }

        $validated = $request->validate([
            'phone_e164' => ['required', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
        ]);

        $result = EvolutionChannelService::make()->pairingCode(
            $account,
            ltrim((string) $validated['phone_e164'], '+'),
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => 'No pudimos generar el código de vinculación. Probá con el QR.',
                'code' => 'PAIRING_CODE_UNAVAILABLE',
                'reason' => $result['error'] ?? 'unknown',
            ], 502);
        }

        return response()->json(['data' => ['pairing_code' => $result['pairing_code']]]);
    }

    /**
     * Mensaje de prueba al propio número conectado (§8.4b punto 3).
     *
     * Cierra el momento de mayor duda del cliente —"¿funcionó?"— con un clic, en
     * vez de dejarlo mirando una bandeja vacía. Se manda al número del canal, o
     * sea a sí mismo: no le llega a ningún cliente real.
     */
    public function testMessage(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'connect');

        $account = $this->findChannelOrDeny($request, $id);

        if (($denied = $this->denyIfCannotManage($request, $account->branch)) !== null) {
            return $denied;
        }

        if (! $account->canSendViaEvolution()) {
            return response()->json([
                'message' => 'El canal no está conectado. Escaneá el QR antes de enviar la prueba.',
                'code' => 'CHANNEL_NOT_CONNECTED',
            ], 409);
        }

        $phone = (string) $account->phone_e164;

        if ($phone === '') {
            return response()->json([
                'message' => 'Todavía no detectamos el número del canal. Esperá unos segundos y volvé a intentar.',
                'code' => 'CHANNEL_PHONE_UNKNOWN',
            ], 409);
        }

        $companyNit = (string) $account->company_nit;
        $now = now();

        // El chat consigo mismo es un chat normal: así el operador ve llegar el
        // mensaje en la bandeja, que es la confirmación que buscaba. Sin esto la
        // prueba se enviaría a ciegas y no probaría el circuito completo.
        $chat = DB::transaction(function () use ($account, $companyNit, $phone, $now): Chat {
            $chat = Chat::withoutBranchScope()
                ->where('company_nit', $companyNit)
                ->where('client_phone', $phone)
                ->where('whatsapp_account_id', $account->id)
                ->first();

            if ($chat === null) {
                $chat = new Chat;
                $chat->forceFill([
                    'company_nit' => $companyNit,
                    'branch_id' => $account->branch_id,
                    'whatsapp_account_id' => $account->id,
                    'client_phone' => $phone,
                    'client_name' => 'Prueba de conexión',
                    'source' => 'whatsapp',
                    'status' => 'open',
                    'bot_paused' => true,
                    'last_message_at' => $now,
                ])->save();
            }

            return $chat;
        });

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender' => 'operator',
            'sent_by_user_id' => $this->actor($request)?->id,
            'body' => 'Mensaje de prueba de flexyflow. Si lo estás viendo, tu WhatsApp quedó conectado.',
            'sent_at' => $now,
        ]);

        WhatsappOutboundMessageSender::forCurrentEnvironment()->deliver($chat, $message);
        $message->refresh();

        $this->auditLogger->log(
            action: 'whatsapp.channel.test_message_sent',
            user: $this->actor($request),
            auditable: $account,
            data: [
                'channel_id' => $account->id,
                'company_nit' => $companyNit,
                'chat_id' => $chat->id,
                'status' => $message->status,
            ],
            request: $request,
        );

        return response()->json([
            'data' => [
                'chat_id' => $chat->id,
                'status' => $message->status,
                'failure_reason' => $message->failure_reason,
            ],
        ], 201);
    }

    /**
     * Desconecta el canal. Exige OTP del owner, igual que el camino Meta: cerrar
     * la sesión deja a la empresa sin WhatsApp hasta que alguien vuelva a
     * escanear, y el que escanea tiene que tener el teléfono en la mano.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'disconnect');

        $account = $this->findChannelOrDeny($request, $id);

        if (($denied = $this->denyIfCannotManage($request, $account->branch)) !== null) {
            return $denied;
        }

        // Un canal que nunca llegó a conectarse no tiene sesión que cerrar ni
        // conversaciones que perder: pedir OTP ahí es fricción sin riesgo, y es
        // justo el caso del wizard abandonado que hay que poder limpiar.
        if (! in_array($account->status, ['pending', 'verifying'], true)) {
            $this->consumeVerificationCode($request, 'disconnect');
        }

        EvolutionChannelService::make()->disconnect($account);

        return response()->json(['data' => ['status' => 'disconnected']]);
    }

    /**
     * Métricas del canal para la tarjeta (§8.4b punto 11): mensajes por día y
     * tiempo medio de respuesta, últimos 7 días. Es el número que le dice al
     * dueño si el módulo funciona. Sin tabla nueva — sale de `chat_messages`.
     */
    public function metrics(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'read');

        $account = $this->findChannelOrDeny($request, $id);

        $since = now()->subDays(7)->startOfDay();

        // Mensajes por día (entrantes + salientes del canal). El join crudo a
        // `chats` evita el N+1 y el scope de empresa lo da el `whatsapp_account_id`,
        // que ya viene de un canal resuelto dentro de la empresa activa.
        $perDay = ChatMessage::query()
            ->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->where('chats.whatsapp_account_id', $account->id)
            ->where('chat_messages.sent_at', '>=', $since)
            ->groupByRaw('date(chat_messages.sent_at)')
            ->selectRaw('date(chat_messages.sent_at) as day, count(*) as total')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->day, 'count' => (int) $row->total])
            ->all();

        // Tiempo medio de respuesta: gap entre un mensaje del cliente y la
        // siguiente respuesta (operador/bot) en el mismo chat, promediado.
        // ponytail: mide la respuesta al ÚLTIMO entrante antes de contestar, no
        // la "primera de la racha". Para el número que le importa al dueño
        // —¿cuánto tardamos en contestar?— alcanza y sale de una sola consulta.
        $avg = DB::selectOne(<<<'SQL'
            WITH ordered AS (
                SELECT cm.sender, cm.sent_at,
                       LEAD(cm.sent_at) OVER (PARTITION BY cm.chat_id ORDER BY cm.sent_at, cm.id) AS next_at,
                       LEAD(cm.sender)  OVER (PARTITION BY cm.chat_id ORDER BY cm.sent_at, cm.id) AS next_sender
                FROM chat_messages cm
                JOIN chats c ON c.id = cm.chat_id
                WHERE c.whatsapp_account_id = ?
                  AND cm.sent_at >= ?
            )
            SELECT AVG(EXTRACT(EPOCH FROM (next_at - sent_at))) AS avg_seconds
            FROM ordered
            WHERE sender = 'client'
              AND next_sender IN ('operator', 'bot')
              AND next_at >= sent_at
        SQL, [$account->id, $since]);

        return response()->json([
            'data' => [
                'messages_per_day' => $perDay,
                'avg_response_seconds' => $avg?->avg_seconds !== null
                    ? (int) round((float) $avg->avg_seconds)
                    : null,
            ],
        ]);
    }

    /**
     * Resuelve un canal de la empresa activa o aborta con 404 auditado.
     *
     * Mismo criterio que `ChatController::findChatOrDeny`: punto único de
     * acceso, id validado antes de tocar la base (un no-UUID hace fallar a
     * Postgres con 22P02 → 500 con el error de la base en el cuerpo), y 404
     * para toda falla de scope.
     */
    private function findChannelOrDeny(Request $request, string $id): CompanyWhatsappAccount
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $account = Str::isUuid($id)
            ? CompanyWhatsappAccount::query()
                ->with('branch:id,name')
                ->where('company_nit', $companyNit)
                ->whereKey($id)
                ->first()
            : null;

        if ($account === null) {
            $this->auditLogger->log(
                action: 'chat.access.denied',
                user: $this->actor($request),
                data: [
                    'channel_id' => $id,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $id,
            );

            abort(404);
        }

        return $account;
    }

    /**
     * Reparto empresa/sede de §7.3. Devuelve la respuesta de rechazo, o `null`
     * si puede seguir.
     */
    private function denyIfCannotManage(Request $request, ?Branch $branch): ?JsonResponse
    {
        if ($this->isPrivileged($request)) {
            return null;
        }

        if ($branch === null) {
            return response()->json([
                'message' => 'Solo el propietario o un administrador puede conectar el número de toda la empresa.',
                'code' => 'CHANNEL_COMPANY_SCOPE_FORBIDDEN',
            ], 403);
        }

        $payload = (array) $request->attributes->get('jwt_payload', []);
        $permissions = (array) ($payload['permissions'] ?? []);

        if (! in_array('whatsapp.manage_branch_channels', $permissions, true)) {
            return response()->json([
                'message' => 'Necesitás el permiso «Gestionar canales de sede» para conectar el WhatsApp de una sede.',
                'code' => 'CHANNEL_BRANCH_SCOPE_FORBIDDEN',
            ], 403);
        }

        $hasAccess = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', (string) ($payload['sub'] ?? ''))
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'No tenés acceso a esa sede.',
                'code' => 'CHANNEL_BRANCH_NOT_ACCESSIBLE',
            ], 403);
        }

        return null;
    }

    /**
     * Owner o admin. `is_system` no sirve: también es true para `employee`, así
     * que el patrón canónico del proyecto compara el nombre del rol.
     */
    private function isPrivileged(Request $request): bool
    {
        $payload = (array) $request->attributes->get('jwt_payload', []);
        $roleName = $payload['role']['name'] ?? null;

        return in_array($roleName, [
            config('roles.role_names.owner'),
            config('roles.role_names.admin'),
        ], true);
    }

    /**
     * Sedes que el usuario puede administrar, o `null` si las puede todas.
     *
     * @return list<string>|null
     */
    private function accessibleBranchIds(Request $request): ?array
    {
        if ($this->isPrivileged($request)) {
            return null;
        }

        $payload = (array) $request->attributes->get('jwt_payload', []);

        if (! in_array('whatsapp.manage_branch_channels', (array) ($payload['permissions'] ?? []), true)) {
            return [];
        }

        return collect($payload['branches'] ?? [])->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    /**
     * Evidencia del consentimiento de §8.3: quién aceptó, desde qué IP y cuándo.
     *
     * Va a `company_whatsapp_account_events` y no a `audit_logs` porque es un
     * hecho del ciclo de vida del canal, no una acción sobre una conversación.
     * Es lo único que respalda que al cliente se le advirtió que WhatsApp puede
     * bloquearle el número.
     */
    private function recordConsent(Request $request, CompanyWhatsappAccount $account): void
    {
        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'consent_accepted',
            'payload' => [
                'actor_user_id' => $this->actor($request)?->id,
                'ip' => $request->ip(),
                'accepted_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Borra los canales que quedaron a medio conectar hace más de un día.
     *
     * Un `pending` ocupa el slot del índice único parcial, así que sin esto el
     * usuario que cerró el modal del QR ayer no puede volver a conectar hoy: le
     * respondería 409 para siempre.
     */
    private function purgeStalePending(string $companyNit, ?string $branchId): void
    {
        CompanyWhatsappAccount::query()
            ->where('company_nit', $companyNit)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', ['pending', 'verifying'])
            ->where('created_at', '<', now()->subHours(self::PENDING_TTL_HOURS))
            ->get()
            ->each(fn (CompanyWhatsappAccount $stale) => EvolutionChannelService::make()->destroy($stale));
    }

    /**
     * Inyecta actividad agregada en los canales, en UNA consulta.
     *
     * @param  list<CompanyWhatsappAccount>  $channels
     */
    private function attachActivity(array $channels): void
    {
        if ($channels === []) {
            return;
        }

        $ids = array_map(static fn (CompanyWhatsappAccount $c) => $c->id, $channels);

        $stats = Chat::withoutBranchScope()
            ->whereIn('whatsapp_account_id', $ids)
            ->groupBy('whatsapp_account_id')
            ->selectRaw('whatsapp_account_id, COUNT(*) AS chats_count, MAX(last_message_at) AS last_message_at')
            ->get()
            ->keyBy('whatsapp_account_id');

        foreach ($channels as $channel) {
            $row = $stats->get($channel->id);
            $channel->setAttribute('chats_count', (int) ($row->chats_count ?? 0));
            $channel->setAttribute(
                'last_message_at',
                $row?->last_message_at ? (string) Carbon::parse($row->last_message_at)->toIso8601String() : null,
            );
        }
    }

    /** El actor sale del JWT ya validado, nunca del body. */
    private function actor(Request $request): ?User
    {
        $payload = (array) $request->attributes->get('jwt_payload', []);

        return isset($payload['sub']) ? User::find((string) $payload['sub']) : null;
    }

    private function consumeVerificationCode(Request $request, string $action): void
    {
        $code = (string) $request->header('X-Whatsapp-Verification-Code', '');

        if ($code === '') {
            abort(response()->json(['message' => 'Falta el código de verificación.'], 422));
        }

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();
        $user = $this->actor($request);

        if ($user === null) {
            abort(response()->json(['message' => 'Sesión inválida.'], 401));
        }

        try {
            $this->verificationService->verify($company, $user, $action, $code);
        } catch (RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }
    }
}
