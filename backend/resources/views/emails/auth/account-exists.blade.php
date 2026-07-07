@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #C0FD79; color: #1E232E; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Tu cuenta</span></div>

# Ya tienes una cuenta con este correo

Hola.

Alguien (quizás tú) intentó **crear una cuenta nueva** en {{ config('app.name') }} con este correo, pero ya tienes una. No creamos nada nuevo ni cambiamos tu cuenta.

Para entrar, usa tu acceso de siempre:

@component('mail::button', ['url' => $loginUrl])
Iniciar sesión
@endcomponent

¿No recuerdas tu contraseña, o siempre entraste con Google y quieres una? Puedes crearla acá:

@component('mail::button', ['url' => $forgotUrl, 'color' => 'success'])
Crear o restablecer contraseña
@endcomponent

**¿No fuiste tú?** No tienes que hacer nada: tu cuenta sigue igual y con este correo. Si te preocupa, escríbenos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a>.
@endcomponent
