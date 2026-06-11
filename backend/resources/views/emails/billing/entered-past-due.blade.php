@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'warning', 'label' => 'Cuenta en mora'])

# Entraste en período de gracia

Hola, {{ $name }}.

Detectamos facturas vencidas en tu cuenta de restaurante flexyflow.

@component('mail::panel')
<span style="font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #6B7280;">Período de gracia</span><br>
Tienes **3 meses** para ponerte al día. Si no se registra el pago antes del **{{ $blockDate }}**, tu cuenta será suspendida y se bloqueará la operación.
@endcomponent

Durante este período puedes seguir operando con normalidad. Te recomendamos regularizar el pago cuanto antes.

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Ver facturación
@endcomponent
@endcomponent
