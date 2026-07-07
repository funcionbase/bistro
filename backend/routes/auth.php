<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\FrontendRedirectController;
use Illuminate\Support\Facades\Route;

// Acceso dual: Google OAuth + correo/contraseña (re-habilitado; antes HU #231
// forzaba solo-Google). Las páginas GET de auth viven en el SPA — cualquier
// hit al backend se reenvía conservando path y query string. Las acciones
// reales son JSON bajo /api/v1/auth/* (routes/api.php). Los named routes se
// conservan para `route('login')` desde middleware/código existente.
Route::middleware('web')->group(function () {
    Route::get('login', FrontendRedirectController::class)->name('login');
    Route::get('register', FrontendRedirectController::class)->name('register');
    Route::get('forgot-password', FrontendRedirectController::class)->name('password.request');
    Route::get('reset-password/{token}', FrontendRedirectController::class)->name('password.reset');
    Route::get('verify-email', FrontendRedirectController::class)->name('verification.notice');
    Route::get('confirm-password', FrontendRedirectController::class)->name('password.confirm');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

// Enlace de verificación de correo: firmado + temporal, SIN middleware auth —
// la sesión de esta app es un JWT en cookie (no sesión web); el controller
// decide el destino según esa cookie. `signed` valida firma y expiración.
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['web', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
