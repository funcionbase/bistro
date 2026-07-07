@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #C0FD79; color: #1E232E; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Verifica tu correo</span></div>

# Un paso más y listo

Hola.

Creaste una cuenta en **{{ config('app.name') }}** con este correo. Confírmalo con el botón de abajo para continuar con el registro de tu empresa:

@component('mail::button', ['url' => $verifyUrl])
Verificar mi correo
@endcomponent

Este enlace vence en **{{ $expiresInMinutes }} minutos**. Si venció, puedes pedir uno nuevo desde la pantalla "Verifica tu correo" al iniciar sesión.

**¿No creaste esta cuenta?** Ignora este correo y no pasará nada — sin la verificación, la cuenta no puede registrar ninguna empresa. Si te preocupa, escríbenos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a>.

@component('mail::subcopy')
Si el botón no funciona, copia y pega este enlace en tu navegador: {{ $verifyUrl }}
@endcomponent
@endcomponent
