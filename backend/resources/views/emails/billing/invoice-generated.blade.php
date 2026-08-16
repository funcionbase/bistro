@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'neutral', 'label' => 'Nueva factura'])

# Tienes una nueva factura

Hola, {{ $name }}.

Se generó la factura de tu suscripción **{{ $plan['name'] }}** para el período de **{{ $period }}**.

@php
    $rows = [
        ['label' => 'Plan', 'value' => e($plan['name'])],
        ['label' => 'Período', 'value' => e($periodFrom).' al '.e($periodTo)],
        ['label' => 'Total a pagar', 'value' => '<span style="font-size: 17px; font-weight: 600;">'.e($amount).' '.e($currency).'</span>'],
        ['label' => 'Vencimiento', 'value' => e($dueDate)],
    ];
@endphp
@include('emails.partials.data-table', ['rows' => $rows])

Para pagar, ingresa al panel de facturación. Ahí encontrarás los datos de transferencia BREB / cuenta bancaria de bistro y la opción para subir el comprobante de pago. Apenas lo verifiquemos, marcamos tu factura como pagada.

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Ver factura y subir comprobante
@endcomponent
@endcomponent
