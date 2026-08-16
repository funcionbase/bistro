@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #C0FD79; color: #1E232E; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Invitación a equipo</span></div>

# Te invitaron a un restaurante en bistro

Hola.

**{{ $inviterName }}** te dio acceso a **{{ $company->commercial_name }}** en {{ config('app.name') }}. Ya puedes entrar al panel iniciando sesión con este mismo correo (**{{ $invitation->email }}**).

@component('mail::panel', ['variant' => 'panel-accent'])
<span style="font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #1E232E; opacity: 0.6;">Restaurante</span><br>
**{{ $company->commercial_name }}**<br>
NIT: {{ $company->nit }}<br>
Correo invitado: {{ $invitation->email }}
@endcomponent

## ¿Cómo entras?

1. **Inicia sesión con tu cuenta de Gmail.** Usa la misma a la que te llegó esta invitación (**{{ $invitation->email }}**). Si aún no tienes cuenta en {{ config('app.name') }}, créala con ese mismo correo — la app la enlaza sola.
2. **Aceptación automática.** Apenas entres, tu acceso a {{ $company->commercial_name }} queda activo. No tienes que copiar códigos ni pegar enlaces.
3. **Listo.** El propietario ya te asignó un rol con los permisos que vas a necesitar.

@component('mail::button', ['url' => $loginUrl])
Entrar a bistro
@endcomponent

¿No esperabas esta invitación o el correo no es tuyo? Ignora este mensaje o escríbenos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a> y la cancelamos.

@component('mail::subcopy')
Recibiste este correo porque alguien del equipo de **{{ $company->commercial_name }}** (NIT {{ $company->nit }}) te invitó a unirte en {{ config('app.name') }}.
@endcomponent
@endcomponent
