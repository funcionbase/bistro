<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resuelve el usuario autor (actor) desde el JWT del request.
 *
 * Centraliza el patrón que estaba repetido en ~8 controllers:
 *
 *   $payload = $request->attributes->get('jwt_payload');
 *   $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;
 *   return $userId ? User::find($userId) : null;
 *
 * El controller debe estar detrás de `ValidateJwt` para que `jwt_payload` exista.
 * Si el middleware faltara, este trait devuelve null en lugar de lanzar — el
 * caller decide si tratar la ausencia como anónimo o error.
 */
trait ResolvesJwtActor
{
    protected function actingUser(Request $request): ?User
    {
        $payload = $request->attributes->get('jwt_payload');
        $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;

        return $userId ? User::find($userId) : null;
    }

    /**
     * Versión que lanza si no hay actor — usar en endpoints que requieren
     * autenticación garantizada por el middleware.
     */
    protected function actingUserOrFail(Request $request): User
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            abort(401, 'Token JWT requerido.');
        }

        return $user;
    }
}
