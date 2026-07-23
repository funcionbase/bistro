<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationFlowResource;
use App\Models\AutomationFlow;
use App\Models\Branch;
use App\Models\CompanyWhatsappAccountEvent;
use App\Rules\SafePlainText;
use App\Services\FeaturePermissionService;
use App\Services\Whatsapp\AutomationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * F6 (§9.5) — gestión de flujos de automatización (n8n) desde el panel.
 *
 * Es la superficie del OPERADOR (JWT + permiso), distinta del contrato del bot
 * (`bot.token`). Acá se crea el flujo, se genera/rota el token (mostrado una sola
 * vez, patrón PAT), se prueba con un `ping` real y se listan las entregas.
 *
 * ponytail: la autorización usa el permiso base `whatsapp.update` + verificación
 * de que la sede pertenece a la empresa. NO replica el reparto fino de §7.3
 * (owner para el flujo de empresa; `whatsapp.manage_branch_channels` para el de
 * sede) que sí hace `WhatsappChannelController::denyIfCannotManage`. Upgrade path:
 * extraer ese guard a un concern compartido si el reparto importa para automation.
 */
class AutomationFlowController extends Controller
{
    /** Eventos que un flujo puede suscribir. `order.status.changed` es fase posterior (§9.2). */
    private const ALLOWED_EVENTS = [
        AutomationDispatcher::EVENT_MESSAGE_RECEIVED,
        AutomationDispatcher::EVENT_HANDOFF_REQUESTED,
        AutomationDispatcher::EVENT_BOT_TOGGLED,
        AutomationDispatcher::EVENT_CHANNEL_STATUS,
    ];

    public function __construct(private readonly FeaturePermissionService $permissionService) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'read');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flows = AutomationFlow::forCompany($companyNit)
            ->with('branch:id,name')
            ->orderByRaw('(branch_id IS NULL) DESC')  // empresa primero, luego sedes
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => AutomationFlowResource::collection($flows)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $this->validatePayload($request, creating: true);
        $branchId = $validated['branch_id'] ?? null;

        if (($error = $this->assertBranchInCompany($companyNit, $branchId)) !== null) {
            return $error;
        }

        // Unicidad por alcance (1 flujo de empresa + 1 por sede): se chequea acá
        // para devolver 409 accionable en vez del 500 del índice único parcial.
        $exists = AutomationFlow::forCompany($companyNit)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Ese alcance ya tiene un flujo de automatización.',
                'code' => 'FLOW_ALREADY_EXISTS',
            ], 409);
        }

        $flow = new AutomationFlow;
        $flow->fill([
            'company_nit' => $companyNit,
            'branch_id' => $branchId,
            'label' => $validated['label'] ?? null,
            'url' => $validated['url'],
            'events' => $validated['events'] ?? self::ALLOWED_EVENTS,
            'enabled' => $validated['enabled'] ?? false,
            'secret_encrypted' => $this->newSecret(),
        ]);
        $flow->save();                    // save primero: mintToken necesita el id
        $token = $flow->mintToken();
        $flow->save();

        // token y secret se muestran UNA sola vez (patrón PAT). Después solo el last4.
        return response()->json([
            'data' => new AutomationFlowResource($flow->load('branch:id,name')),
            'token' => $token,
            'secret' => (string) $flow->secret_encrypted,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flow = $this->resolveOrDeny($companyNit, $id);
        $validated = $this->validatePayload($request, creating: false);

        $flow->fill(array_filter([
            'label' => $validated['label'] ?? null,
            'url' => $validated['url'] ?? null,
            'events' => $validated['events'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('enabled', $validated)) {
            $flow->enabled = (bool) $validated['enabled'];
        }
        $flow->save();

        return response()->json(['data' => new AutomationFlowResource($flow->load('branch:id,name'))]);
    }

    public function rotateToken(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flow = $this->resolveOrDeny($companyNit, $id);
        $token = $flow->mintToken();
        $flow->save();

        return response()->json(['token' => $token, 'token_last4' => $flow->token_last4]);
    }

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flow = $this->resolveOrDeny($companyNit, $id);
        $flow->secret_encrypted = $this->newSecret();
        $flow->save();

        return response()->json(['secret' => (string) $flow->secret_encrypted]);
    }

    /**
     * "Enviar evento de prueba" (§9.5): un `ping` REAL y firmado a la URL del
     * flujo, SÍNCRONO (la UI muestra status y latencia al instante). No usa la
     * cola: es diagnóstico, no entrega de negocio.
     */
    public function test(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flow = $this->resolveOrDeny($companyNit, $id);

        $body = (string) json_encode([
            'event' => 'ping',
            'sent_at' => now()->toIso8601String(),
            'company_nit' => $companyNit,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $flow->secret_encrypted);

        $start = microtime(true);
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Flexyflow-Event' => 'ping',
                    'X-Flexyflow-Delivery' => (string) Str::uuid(),
                    'X-Flexyflow-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post((string) $flow->url);

            return response()->json([
                'ok' => $response->successful(),
                'http_status' => $response->status(),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'http_status' => 0,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => 'unreachable',
            ], 200);
        }
    }

    /** Últimas 20 entregas del flujo (§9.5), desde company_whatsapp_account_events. */
    public function deliveries(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'read');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $flow = $this->resolveOrDeny($companyNit, $id);

        $rows = CompanyWhatsappAccountEvent::query()
            ->where('event_type', 'automation_delivery')
            ->where('payload->flow_id', $flow->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['payload', 'created_at']);

        $deliveries = $rows->map(static fn (CompanyWhatsappAccountEvent $e): array => [
            'event' => $e->payload['event'] ?? null,
            'http_status' => $e->payload['http_status'] ?? null,
            'latency_ms' => $e->payload['latency_ms'] ?? null,
            'attempt' => $e->payload['attempt'] ?? null,
            'at' => $e->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $deliveries]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'whatsapp', 'update');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $this->resolveOrDeny($companyNit, $id)->delete();

        return response()->json([], 204);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'branch_id' => ['nullable', 'uuid'],
            'label' => ['nullable', new SafePlainText(maxBytes: 60, allowWhitespace: true)],
            // §9.5: https obligatorio — el webhook lleva contenido de conversaciones.
            'url' => [$required, 'url', 'starts_with:https://', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in(self::ALLOWED_EVENTS)],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }

    /** 404 (no 403) si la sede no es de esta empresa — no confirma existencia ajena. */
    private function assertBranchInCompany(string $companyNit, ?string $branchId): ?JsonResponse
    {
        if ($branchId === null) {
            return null;
        }

        $ok = Branch::query()
            ->where('company_nit', $companyNit)
            ->where('id', $branchId)
            ->whereNull('archived_at')
            ->exists();

        return $ok ? null : response()->json([
            'message' => 'Sede no encontrada en esta empresa.',
            'code' => 'BRANCH_NOT_FOUND',
        ], 404);
    }

    private function resolveOrDeny(string $companyNit, string $id): AutomationFlow
    {
        return AutomationFlow::forCompany($companyNit)->findOrFail($id);
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
