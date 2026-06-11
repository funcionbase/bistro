<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\DestroyPushSubscriptionRequest;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestión de suscripciones Web Push del usuario (#149 CA2).
 *
 * Endpoints:
 *  - POST   /api/v1/push/subscriptions       → upsert (user_id, endpoint)
 *  - DELETE /api/v1/push/subscriptions       → soft-revoke por endpoint
 *  - GET    /api/v1/push/subscriptions/me    → lista propia (UI settings)
 *
 * Convenciones:
 *  - Idempotente: store es upsert (re-suscribirse después de revocar
 *    "des-revoca" la fila — limpia `revoked_at` y refresca `last_seen_at`).
 *  - DELETE responde 204 incluso si no encontró la sub, para evitar
 *    enumeration (no leak de qué endpoints existen).
 *  - El user_agent del request se persiste para diferenciar dispositivos
 *    en la UI de settings; si el FormRequest trae uno explícito, gana ese.
 *  - Audit trail vía `notifications.subscribed` / `notifications.revoked`.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * POST /api/v1/push/subscriptions
     */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = $this->resolveActor($request);
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $data = $request->validated();

        $userAgent = $data['user_agent'] ?? $request->userAgent();

        $sub = DB::transaction(function () use ($user, $companyNit, $branchId, $data, $userAgent) {
            $existing = PushSubscription::query()
                ->where('user_id', $user->id)
                ->where('endpoint', $data['endpoint'])
                ->first();

            if ($existing !== null) {
                $existing->fill([
                    'company_nit' => $companyNit,
                    'branch_id' => $branchId,
                    'p256dh' => $data['p256dh'],
                    'auth' => $data['auth'],
                    'user_agent' => $userAgent,
                    'last_seen_at' => Carbon::now(),
                    'revoked_at' => null,
                ])->save();

                return $existing;
            }

            return PushSubscription::create([
                'user_id' => $user->id,
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['p256dh'],
                'auth' => $data['auth'],
                'user_agent' => $userAgent,
                'last_seen_at' => Carbon::now(),
            ]);
        });

        $this->audit->log(
            'notifications.subscribed',
            user: $user,
            auditable: $sub,
            data: [
                'subscription_id' => $sub->id,
                'user_agent' => $userAgent,
            ],
            request: $request,
        );

        return response()->json([
            'data' => [
                'id' => $sub->id,
                'created_at' => $sub->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/push/subscriptions
     */
    public function destroy(DestroyPushSubscriptionRequest $request): Response
    {
        $user = $this->resolveActor($request);
        $endpoint = (string) $request->validated('endpoint');

        $sub = PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->whereNull('revoked_at')
            ->first();

        if ($sub !== null) {
            $sub->revoked_at = Carbon::now();
            $sub->save();

            $this->audit->log(
                'notifications.revoked',
                user: $user,
                auditable: $sub,
                data: [
                    'subscription_id' => $sub->id,
                    'reason' => 'user_revoke',
                ],
                request: $request,
            );
        }

        return response()->noContent();
    }

    /**
     * GET /api/v1/push/subscriptions/me
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveActor($request);

        $subs = PushSubscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $subs->map(fn (PushSubscription $s) => [
                'id' => $s->id,
                'user_agent' => $s->user_agent,
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    private function resolveActor(Request $request): User
    {
        $payload = $request->attributes->get('jwt_payload');

        return User::findOrFail($payload['sub']);
    }
}
