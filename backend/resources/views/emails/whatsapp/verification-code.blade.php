@component('mail::message')
# Hola, {{ $owner->name ?? 'propietario' }}

**{{ $requester->name }}** solicitó **{{ $actionLabel }}** sobre la cuenta de WhatsApp de **{{ $company->commercial_name }}**.

@if($ip || $userAgent)
> @if($ip) IP: `{{ $ip }}` @endif
> @if($userAgent) Dispositivo: `{{ \Illuminate\Support\Str::limit($userAgent, 80) }}` @endif
@endif

Si autorizaste este cambio, comparte el siguiente código con la persona encargada de realizarlo:

@component('mail::panel')
<div style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 28px; letter-spacing: 8px; text-align: center; font-weight: 700; color: #1E232E;">
{{ $code }}
</div>
@endcomponent

El código vence en **{{ $expiresInMinutes }} minutos**.

@component('mail::button', ['url' => $rejectUrl, 'color' => 'error'])
No fui yo — bloquear esta solicitud
@endcomponent

Si no reconoces esta solicitud, oprime el botón de arriba para invalidar el código y notificar al equipo de soporte.

Cordialmente,<br>
**{{ config('app.name') }}**
@endcomponent
