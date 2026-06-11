@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'destructive', 'label' => 'Cuenta suspendida'])

# Tu cuenta fue suspendida

Hola, {{ $name }}.

Tu cuenta excedió el período de gracia de 3 meses sin registrar el pago, así que la operación quedó bloqueada.

@component('mail::panel', ['variant' => 'panel-dark'])
A partir de ahora sólo podrás acceder a la pantalla de facturación para subir el comprobante de pago. Apenas validemos tu pago, tu cuenta se reactiva automáticamente en **menos de 24 horas**.
@endcomponent

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Subir comprobante de pago
@endcomponent
@endcomponent
