@component('mail::message')
{{-- Saludo --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# Ocurrió un inconveniente
@else
# Hola
@endif
@endif

{{-- Líneas introductorias --}}
@foreach ($introLines as $line)
{!! $line !!}

@endforeach

{{-- Botón de acción --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success' => 'success',
        'error' => 'error',
        default => 'primary',
    };
?>
@component('mail::button', ['url' => $actionUrl, 'color' => $color])
{{ $actionText }}
@endcomponent
@endisset

{{-- Líneas de cierre --}}
@foreach ($outroLines as $line)
{!! $line !!}

@endforeach

{{-- Despedida --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Cordialmente,<br>
El equipo de {{ config('app.name') }}
@endif

{{-- Subcopia con URL alterna del botón --}}
@isset($actionText)
@slot('subcopy')
Si el botón "**{{ $actionText }}**" no funciona, copia y pega esta dirección en tu navegador:

<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
@endslot
@endisset
@endcomponent
