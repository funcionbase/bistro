<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// HU #231 — Acceso únicamente vía Google OAuth.
// Las páginas GET de auth email/password redirigen al flujo Google con un
// motivo informativo para que el frontend pueda mostrar el mensaje suave.
// Los POST responden 410 Gone — los named routes se conservan para no
// romper `route('login')` desde código existente (middleware `auth`, etc.).
$redirectToGoogle = function (Request $request) {
    $params = ['reason' => 'email_auth_disabled'];
    if ($intended = $request->query('intended')) {
        $params['intended'] = $intended;
    }

    return redirect()->route('auth.google', $params, 302);
};

$gone = function (Request $request) {
    Log::info('auth.legacy_endpoint_hit', [
        'path' => $request->path(),
        'method' => $request->method(),
        'ip' => $request->ip(),
        'ua' => $request->userAgent(),
    ]);

    return response()->json([
        'message' => 'Autenticación email/contraseña deshabilitada. Usa "Continuar con Google".',
        'code' => 'email_auth_disabled',
    ], 410);
};

// GET: páginas de auth — siempre redirigen a Google OAuth (con motivo).
// Si el usuario ya está autenticado, el frontend redirect controller no
// las maneja porque interceptamos aquí. Si quisieras conservar "ya logueado
// → /" agregá `if ($request->user()) return redirect('/');` arriba del
// redirect; por ahora preferimos rebote consistente.
Route::middleware('web')->group(function () use ($redirectToGoogle) {
    Route::get('login', $redirectToGoogle)->name('login');
    Route::get('register', $redirectToGoogle)->name('register');
    Route::get('forgot-password', $redirectToGoogle)->name('password.request');
    Route::get('reset-password/{token}', $redirectToGoogle)->name('password.reset');
    Route::get('verify-email', $redirectToGoogle)->name('verification.notice');
    Route::get('confirm-password', $redirectToGoogle)->name('password.confirm');
});

// POST: acciones email/password — 410 Gone con log.
Route::middleware('guest')->group(function () use ($gone) {
    Route::post('login', $gone)->middleware('throttle:5,1')->name('login.attempt');
    Route::post('register', $gone)->middleware('throttle:5,1')->name('register.attempt');
    Route::post('forgot-password', $gone)->middleware('throttle:5,1')->name('password.email');
    Route::post('reset-password', $gone)->middleware('throttle:5,1')->name('password.store');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () use ($gone) {
    // El enlace de verificación firmado se conserva: la migración de cuentas
    // legacy puede aún disparar correos con este link. El controller redirige
    // al dashboard cuando el email ya está verificado (Google OAuth siempre
    // entrega `email_verified_at` desde el provider).
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Reenvío de correo de verificación: deshabilitado — Google OAuth ya
    // verifica el email en el callback.
    Route::post('email/verification-notification', $gone)
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
