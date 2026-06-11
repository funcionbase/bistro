@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'accent', 'label' => 'Cuenta reactivada'])

# ¡Tu cuenta está activa otra vez!

Hola, {{ $name }}.

Detectamos que liquidaste todas las facturas pendientes.

@component('mail::panel', ['variant' => 'panel-accent'])
<span style="font-size: 20px; font-weight: 600;">{{ $companyName }}</span><br>
Tu cuenta volvió a estar **activa** y puedes operar con normalidad.
@endcomponent

Gracias por mantener tu cuenta al día.

@component('mail::button', ['url' => $dashboardUrl, 'color' => 'primary'])
Ir al dashboard
@endcomponent
@endcomponent
