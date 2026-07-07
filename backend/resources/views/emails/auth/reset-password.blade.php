@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #C0FD79; color: #1E232E; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Seguridad de tu cuenta</span></div>

# Restablece tu contraseña

Hola.

Recibimos una solicitud para **restablecer la contraseña** de tu cuenta de {{ config('app.name') }}. Si tu cuenta entraba solo con Google, este mismo enlace te permite **crear una contraseña** y usar ambos accesos con el mismo correo.

@component('mail::button', ['url' => $resetUrl])
Crear nueva contraseña
@endcomponent

Este enlace vence en **{{ $expiresInMinutes }} minutos** y solo sirve una vez.

**¿No pediste esto?** Ignora este correo: tu contraseña actual (y tu acceso con Google) siguen funcionando igual. Si te preocupa, escríbenos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a>.

@component('mail::subcopy')
Si el botón no funciona, copia y pega este enlace en tu navegador: {{ $resetUrl }}
@endcomponent
@endcomponent
