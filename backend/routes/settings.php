<?php

use App\Http\Controllers\FrontendRedirectController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::redirect('settings', 'settings/profile');

// Páginas de settings migradas al shell SPA (#220). El frontend consume
// los endpoints /api/v1/account/* y /api/v1/auth/*.
Route::get('settings/profile', FrontendRedirectController::class)->name('profile.edit');
Route::get('settings/password', FrontendRedirectController::class)->name('password.edit');
Route::get('settings/appearance', FrontendRedirectController::class)->name('appearance');
Route::get('settings/notifications', FrontendRedirectController::class)->name('notifications.edit');

// Acciones web (Breeze). El frontend SPA ya no las usa — apunta a la API.
// Se conservan para no romper named routes mientras dura la transición.
Route::middleware('auth')->group(function () {
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // HU #231 — Cambio de contraseña deshabilitado: el acceso es solo Google OAuth,
    // las cuentas no tienen contraseña gestionable por la app.
    Route::put('settings/password', function (Request $request) {
        Log::info('auth.legacy_endpoint_hit', [
            'path' => $request->path(),
            'method' => 'PUT',
            'user_id' => optional($request->user())->id,
        ]);

        return response()->json([
            'message' => 'El cambio de contraseña está deshabilitado. Tu cuenta usa Google para iniciar sesión.',
            'code' => 'email_auth_disabled',
        ], 410);
    })->name('password.update');
});
