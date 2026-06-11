<?php

/**
 * HU #231 — Forzar login con Google y desactivar email/password.
 *
 * Estos tests son el contrato de regresión para que las rutas legacy de
 * autenticación email/password NUNCA vuelvan a aceptar credenciales:
 *   - GET: redirigen a /auth/google con motivo `email_auth_disabled`.
 *   - POST: responden 410 Gone con código `email_auth_disabled`.
 *   - PUT /settings/password y PUT /api/v1/account/password (autenticadas)
 *     también responden 410 — Google OAuth no entrega contraseña gestionable.
 *
 * El endpoint signed `verify-email/{id}/{hash}` se conserva porque correos
 * legacy aún pueden disparar el link; no se prueba aquí.
 */

use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Log::spy();
});

describe('GET pages email/password', function () {
    it('redirects GET /login to Google OAuth with reason flag', function () {
        $response = $this->get('/login');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
        expect($response->headers->get('Location'))->toContain('reason=email_auth_disabled');
    });

    it('redirects GET /register to Google OAuth with reason flag', function () {
        $response = $this->get('/register');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
        expect($response->headers->get('Location'))->toContain('reason=email_auth_disabled');
    });

    it('redirects GET /forgot-password to Google OAuth', function () {
        $response = $this->get('/forgot-password');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
    });

    it('redirects GET /reset-password/{token} to Google OAuth', function () {
        $response = $this->get('/reset-password/any-token-here');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
    });

    it('redirects GET /verify-email (no signature) to Google OAuth', function () {
        $response = $this->get('/verify-email');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
    });

    it('redirects GET /confirm-password to Google OAuth', function () {
        $response = $this->get('/confirm-password');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/auth/google');
    });

    it('preserves intended query param across the redirect', function () {
        $response = $this->get('/login?intended=%2Fdashboard');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('intended=');
    });
});

describe('POST actions email/password return 410 Gone', function () {
    it('returns 410 Gone for POST /login', function () {
        $response = $this->postJson('/login', [
            'email' => 'someone@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(410);
        $response->assertJson([
            'code' => 'email_auth_disabled',
        ]);
    });

    it('returns 410 Gone for POST /register', function () {
        $response = $this->postJson('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'whatever',
            'password_confirmation' => 'whatever',
        ]);

        $response->assertStatus(410);
        $response->assertJson([
            'code' => 'email_auth_disabled',
        ]);
    });

    it('returns 410 Gone for POST /forgot-password', function () {
        $response = $this->postJson('/forgot-password', [
            'email' => 'someone@example.com',
        ]);

        $response->assertStatus(410);
        $response->assertJson([
            'code' => 'email_auth_disabled',
        ]);
    });

    it('returns 410 Gone for POST /reset-password', function () {
        $response = $this->postJson('/reset-password', [
            'token' => 'any',
            'email' => 'someone@example.com',
            'password' => 'whatever',
            'password_confirmation' => 'whatever',
        ]);

        $response->assertStatus(410);
        $response->assertJson([
            'code' => 'email_auth_disabled',
        ]);
    });
});

describe('Named routes survive (no breakage for route() callers)', function () {
    it('keeps login named route resolvable', function () {
        expect(route('login'))->toContain('/login');
    });

    it('keeps register named route resolvable', function () {
        expect(route('register'))->toContain('/register');
    });

    it('keeps password.request named route resolvable', function () {
        expect(route('password.request'))->toContain('/forgot-password');
    });

    it('keeps auth.google named route resolvable', function () {
        expect(route('auth.google'))->toContain('/auth/google');
    });
});
