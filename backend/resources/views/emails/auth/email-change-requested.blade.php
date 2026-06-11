@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #C0FD79; color: #1E232E; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Seguridad de tu cuenta</span></div>

# ¿Querés mover tu cuenta a un correo nuevo?

Hola.

Recibimos una solicitud para **cambiar el correo de tu cuenta de {{ config('app.name') }}** a:

@component('mail::panel', ['variant' => 'panel-accent'])
<span style="font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #1E232E; opacity: 0.6;">Correo nuevo</span><br>
**{{ $newEmail }}**
@endcomponent

Alguien intentó registrarse con tu cédula usando ese correo. Si **fuiste vos**, confirmá el cambio con el botón de abajo: a partir de ahí entrarás a tu misma cuenta (tus empresas y tus datos) iniciando sesión con **{{ $newEmail }}**.

@component('mail::button', ['url' => $confirmUrl])
Confirmar el cambio de correo
@endcomponent

Este enlace vence en **{{ $expiresInMinutes }} minutos** y solo sirve una vez.

**¿No fuiste vos?** No hagas nada: tu cuenta sigue igual con este correo. La cédula por sí sola no permite ningún cambio — por eso te pedimos confirmar acá. Si te preocupa, escribinos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a>.

@component('mail::subcopy')
Recibiste este correo porque tu cédula ya está asociada a esta cuenta de {{ config('app.name') }}. La confirmación es la única forma de mover el acceso a otro correo.
@endcomponent
@endcomponent
