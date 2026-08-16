<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KdsDeviceToken;
use App\Models\KdsStation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generación y revocación de device-tokens KDS.
 *
 * El token en claro tiene 48 chars (URL-safe base62 derivado de
 * `Str::random(48)`). Se hashea con SHA-256 antes de persistirse. El claro
 * se devuelve UNA sola vez en `generate()` para que la UI lo muestre con
 * copy-once; nunca queda en BD ni en logs.
 *
 * Audita generación y revocación. La identificación del token usado en
 * cada request (por `EnsureKdsDeviceToken`) actualiza `last_seen_at` y
 * `last_ip` inline.
 */
class KdsDeviceTokenService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Genera un nuevo token para la estación dada.
     *
     * @return array{token: string, model: KdsDeviceToken}
     */
    public function generate(KdsStation $station, ?string $label, User $actor, Request $request): array
    {
        $clear = Str::random(48);
        $hash = KdsDeviceToken::hashToken($clear);

        $model = DB::transaction(function () use ($station, $label, $hash) {
            return KdsDeviceToken::create([
                'company_nit' => $station->company_nit,
                'branch_id' => $station->branch_id,
                'station_id' => $station->id,
                'token_hash' => $hash,
                'label' => $label,
            ]);
        });

        $this->audit->log(
            'kds.device_token.generated',
            user: $actor,
            auditable: $model,
            data: [
                'station_id' => $station->id,
                'station_slug' => $station->slug,
                'label' => $label,
            ],
            request: $request,
        );

        return ['token' => $clear, 'model' => $model];
    }

    public function revoke(KdsDeviceToken $token, User $actor, Request $request): void
    {
        if ($token->isRevoked()) {
            return;
        }

        DB::transaction(function () use ($token) {
            $token->revoked_at = Carbon::now();
            $token->save();
        });

        $this->audit->log(
            'kds.device_token.revoked',
            user: $actor,
            auditable: $token,
            data: [
                'station_id' => $token->station_id,
                'label' => $token->label,
            ],
            request: $request,
        );
    }

    /**
     * Resuelve el token desde el request. Lee primero `Authorization: Bearer
     * <token>`, después cookie `kds_device_token`, después query `?device=`.
     * Retorna null si no encuentra o si está revocado.
     *
     * Actualiza `last_seen_at` y `last_ip` antes de devolver.
     */
    public function resolveFromRequest(Request $request): ?KdsDeviceToken
    {
        $clear = $this->extractClearToken($request);
        if ($clear === null) {
            return null;
        }

        $hash = KdsDeviceToken::hashToken($clear);

        /** @var KdsDeviceToken|null $token */
        $token = KdsDeviceToken::query()
            ->where('token_hash', $hash)
            ->active()
            ->first();

        if ($token === null) {
            return null;
        }

        // Actualizar last_seen inline. No es bloqueador si falla.
        $token->forceFill([
            'last_seen_at' => Carbon::now(),
            'last_ip' => $request->ip(),
        ])->save();

        return $token;
    }

    private function extractClearToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            $candidate = trim(substr($header, 7));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $cookie = $request->cookie('kds_device_token');
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $query = $request->query('device');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        return null;
    }
}
