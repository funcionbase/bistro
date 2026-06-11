@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'warning', 'label' => 'Factura vencida'])

# Tu factura está vencida

Hola, {{ $name }}.

La factura de tu suscripción **{{ $planName }}** venció sin que registremos el pago.

@php
    $overduePill = '<span style="display: inline-block; background-color: #FDF0DB; color: #F39C12; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; letter-spacing: 0.02em;">Venció el '.e($dueDate).'</span>';
    $rows = [
        ['label' => 'Plan', 'value' => e($planName)],
        ['label' => 'Total pendiente', 'value' => '<span style="font-size: 17px; font-weight: 600;">'.e($amount).' '.e($currency).'</span>'],
        ['label' => 'Estado', 'value' => $overduePill],
    ];
@endphp
@include('emails.partials.data-table', ['rows' => $rows])

Tu cuenta entró en período de gracia. Realiza el pago para evitar la suspensión del servicio.

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Ver facturación
@endcomponent
@endcomponent
