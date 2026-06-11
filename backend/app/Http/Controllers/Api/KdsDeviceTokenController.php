<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KdsDeviceToken;
use App\Models\KdsStation;
use App\Models\User;
use App\Services\KdsDeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de device-tokens KDS (#115 F6).
 *
 * Endpoints expuestos a la pantalla de settings de la empresa para que el
 * owner / admin con `kds.manage_stations` pueda generar tokens copy-once y
 * revocarlos. El claro NUNCA se devuelve después del store; si se
 * extravía, la única opción es generar uno nuevo.
 */
class KdsDeviceTokenController extends Controller
{
    public function __construct(private readonly KdsDeviceTokenService $tokens) {}

    /**
     * GET /api/v1/company/kds/stations/{stationId}/tokens
     *
     * Lista tokens de la estación (activos y revocados — los revocados se
     * conservan para audit). Nunca incluye el claro.
     */
    public function index(Request $request, string $stationId): JsonResponse
    {
        $station = $this->locateStationOrFail($request, $stationId);

        $tokens = KdsDeviceToken::query()
            ->where('station_id', $station->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $tokens->map(fn (KdsDeviceToken $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'last_seen_at' => optional($t->last_seen_at)?->toIso8601String(),
                'last_ip' => $t->last_ip,
                'revoked_at' => optional($t->revoked_at)?->toIso8601String(),
                'created_at' => optional($t->created_at)?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/v1/company/kds/stations/{stationId}/tokens
     *
     * Genera un token nuevo. Devuelve el claro UNA sola vez. El frontend
     * lo muestra en copy-once dialog y no lo persiste en localStorage.
     */
    public function store(Request $request, string $stationId): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        $station = $this->locateStationOrFail($request, $stationId);
        $actor = $this->resolveActor($request);

        $result = $this->tokens->generate(
            station: $station,
            label: $validated['label'] ?? null,
            actor: $actor,
            request: $request,
        );

        return response()->json([
            'token' => $result['token'],
            'data' => [
                'id' => $result['model']->id,
                'label' => $result['model']->label,
                'created_at' => optional($result['model']->created_at)?->toIso8601String(),
            ],
            'launch_url' => route('kds.station', ['stationSlug' => $station->slug]).'?device='.$result['token'],
        ], 201);
    }

    /**
     * DELETE /api/v1/company/kds/stations/{stationId}/tokens/{tokenId}
     *
     * Revoca un token (soft). Próximos requests con ese token reciben 401.
     */
    public function destroy(Request $request, string $stationId, string $tokenId): JsonResponse
    {
        $station = $this->locateStationOrFail($request, $stationId);
        $actor = $this->resolveActor($request);

        $token = KdsDeviceToken::query()
            ->where('station_id', $station->id)
            ->whereKey($tokenId)
            ->firstOrFail();

        $this->tokens->revoke($token, $actor, $request);

        return response()->json([
            'data' => [
                'id' => $token->id,
                'revoked_at' => optional($token->fresh()->revoked_at)?->toIso8601String(),
            ],
        ]);
    }

    private function locateStationOrFail(Request $request, string $stationId): KdsStation
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = (string) $request->attributes->get('active_branch_id');

        return KdsStation::query()
            ->whereKey($stationId)
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->firstOrFail();
    }

    private function resolveActor(Request $request): User
    {
        $userId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        return User::query()->findOrFail($userId);
    }
}
