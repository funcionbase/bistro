@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'accent', 'label' => 'Registro aprobado'])

# ¡{{ $companyName }} ya está activa!

Hola, {{ $name }}.

¡Buenas noticias! Tu empresa **{{ $companyName }}** quedó activada y ya puedes empezar a usar todo el panel de bistro.

@component('mail::panel', ['variant' => 'panel-accent'])
<span style="font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #1E232E; opacity: 0.6;">Tu plan</span><br>
**{{ $plan['name'] }}**<br>
@if (! empty($plan['description'])){{ $plan['description'] }}<br>@endif
<span style="font-size: 20px; font-weight: 600;">{{ $plan['price_formatted'] }}</span> <span style="opacity: 0.7;">{{ $plan['cycle_label'] }}</span>@if (! empty($plan['tax_notice']))<br><span style="opacity: 0.7; font-size: 14px;">{{ $plan['tax_notice'] }}</span>@endif
@endcomponent

@if (! empty($plan['features']))
**Tu plan incluye:**

@foreach ($plan['features'] as $feature)
- {{ $feature }}
@endforeach
@endif

@if ($trialEndsAt)
Tu período de prueba va hasta el **{{ $trialEndsAt }}**. A partir de esa fecha generamos tu factura mensual y la pagas por transferencia (BREB / cuenta bancaria de bistro) con los datos que aparecen en el panel de facturación.
@else
Cuando llegue el primer cobro te enviaremos la factura y la pagas por transferencia con los datos que aparecen en el panel de facturación.
@endif

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Ir al panel
@endcomponent

¿Dudas? Escríbenos a <a href="mailto:{{ $supportEmail }}" style="color: #0052FF;">{{ $supportEmail }}</a> y te ayudamos.
@endcomponent
