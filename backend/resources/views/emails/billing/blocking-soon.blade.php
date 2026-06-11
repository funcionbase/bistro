@component('mail::message')
@include('emails.partials.wordmark')

@include('emails.partials.eyebrow', ['variant' => 'warning', 'label' => 'Recordatorio'])

# Tu cuenta se suspende en {{ $daysLeft }} {{ $unit }}

Hola, {{ $name }}.

@php
    $countdownPill = '<span style="display: inline-block; background-color: #FDF0DB; color: #F39C12; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; letter-spacing: 0.02em;">Bloqueo el '.e($blockDate).'</span>';
    $rows = [
        ['label' => 'Suspensión prevista', 'value' => $countdownPill],
    ];
@endphp
Tu cuenta entrará en suspensión el **{{ $blockDate }}** si no se registra el pago.

@include('emails.partials.data-table', ['rows' => $rows])

Una vez suspendida, sólo podrás acceder a la pantalla de facturación para subir el comprobante de pago.

@component('mail::button', ['url' => $panelUrl, 'color' => 'primary'])
Pagar y subir comprobante
@endcomponent
@endcomponent
