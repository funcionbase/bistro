<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\Account\AccountEmailChangeException;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountEmailChangeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pantalla de confirmación del cambio de correo (recuperación por cédula).
 *
 * GET muestra la confirmación sin mutar nada (evita que escáneres de correo
 * que siguen enlaces disparen el cambio). POST ejecuta el movimiento de la
 * cuenta. Ruta pública: la autorización es el token enviado al correo viejo.
 */
class AccountEmailChangeController extends Controller
{
    public function __construct(private readonly AccountEmailChangeService $service) {}

    public function show(Request $request): View
    {
        $token = (string) $request->query('token', '');
        $pending = $token !== '' ? $this->service->findPending($token) : null;

        return view('auth.email-change-confirm', [
            'valid' => $pending !== null,
            'token' => $token,
            'newEmail' => $pending?->new_email,
            'loginUrl' => $this->loginUrl(),
            'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
        ]);
    }

    public function confirm(Request $request): View
    {
        $token = (string) $request->input('token', '');

        try {
            $user = $this->service->confirm($token);
        } catch (AccountEmailChangeException $e) {
            return view('auth.email-change-result', [
                'ok' => false,
                'message' => $e->getMessage(),
                'loginUrl' => $this->loginUrl(),
                'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
            ]);
        }

        return view('auth.email-change-result', [
            'ok' => true,
            'newEmail' => $user->email,
            'loginUrl' => $this->loginUrl(),
            'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
        ]);
    }

    private function loginUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/';
    }
}
