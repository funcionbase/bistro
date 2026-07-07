<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Retorna los datos del usuario autenticado combinando BD y payload del JWT.
 *
 * El campo 'role' y 'active_company_name' provienen del JWT (no de BD) para evitar
 * una query extra; reflejan el estado al momento de la emisión del token.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'cedula' => $user->cedula,
                'status' => $user->status,
                // Acceso dual: el frontend usa esto para decidir si la pantalla
                // de contraseña pide la actual (cambio) o no (fijarla por
                // primera vez en una cuenta creada con Google). No expone el
                // hash — solo si existe.
                'has_password' => $user->password !== null,
            ],
            'role' => $payload['role'] ?? null,
            'active_company_name' => $payload['active_company_name'] ?? null,
        ]);
    }

    /**
     * Elimina la cuenta del usuario autenticado vía JWT.
     *
     * Confirmación según el tipo de cuenta:
     * - Usuarios con password (registro local): valida `password` (current_password).
     * - Usuarios OAuth (password=null): valida `confirm_email` exacto al email del usuario.
     *
     * Tras eliminar, invalida el JWT en uso para impedir reutilización del token.
     */
    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        if ($user->password !== null) {
            $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check($request->input('password'), $user->password)) {
                throw ValidationException::withMessages([
                    'password' => __('auth.password'),
                ]);
            }
        } else {
            $request->validate([
                'confirm_email' => ['required', 'string'],
            ]);

            if ($request->input('confirm_email') !== $user->email) {
                throw ValidationException::withMessages([
                    'confirm_email' => __('El email de confirmación no coincide con tu cuenta.'),
                ]);
            }
        }

        $this->auditService->log('user.deleted', $user, $user, [
            'email' => $user->email,
        ], $request);

        $this->jwtService->invalidate($request->attributes->get('jwt_token'));
        $user->delete();

        return response()->json([
            'message' => 'Cuenta eliminada correctamente.',
        ]);
    }
}
