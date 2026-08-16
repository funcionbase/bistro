<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAccountProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Gestión de la cuenta del usuario autenticado para el shell SPA.
 *
 * Migra las acciones de `Settings\ProfileController` / `PasswordController`
 * (que devolvían redirects de Inertia) a respuestas JSON. El usuario se
 * resuelve del `jwt_payload` inyectado por el middleware `jwt`.
 */
class AccountController extends Controller
{
    private function user(Request $request): User
    {
        $payload = $request->attributes->get('jwt_payload');
        $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;

        return User::findOrFail($userId);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): JsonResponse
    {
        $user = $this->user($request);

        // `name` es columna generada (first_name + last_name): solo se persisten
        // first/last; la BD recompone `name`.
        $user->fill($request->safe()->only(['first_name', 'last_name', 'email', 'cedula']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        $user->refresh();

        return response()->json([
            'data' => $user->only(['id', 'name', 'first_name', 'last_name', 'email', 'cedula', 'email_verified_at']),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            // La regla `current_password` valida contra el guard de sesión,
            // que no existe con JWT — se chequea manual abajo. Nullable para
            // cuentas Google que FIJAN contraseña por primera vez (password
            // null): ya están autenticadas y no tienen contraseña actual.
            'current_password' => [$user->password !== null ? 'required' : 'nullable', 'string'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        if ($user->password !== null && ! Hash::check((string) ($validated['current_password'] ?? ''), $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no coincide.',
                'errors' => ['current_password' => ['La contraseña actual no coincide.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Usuarios con password (registro local) confirman con la contraseña;
        // usuarios OAuth (password null) confirman escribiendo su email.
        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'current_password']]);
        } else {
            $request->validate(['confirm_email' => ['required', 'string', 'in:'.$user->email]]);
        }

        $user->delete();

        return response()->json(['message' => 'Cuenta eliminada.']);
    }
}
