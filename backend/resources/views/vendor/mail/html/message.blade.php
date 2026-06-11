@component('mail::layout')
{{-- Header --}}
@slot('header')
@component('mail::header', ['url' => config('app.frontend_url')])
{{ config('app.name') }}
@endcomponent
@endslot

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
@component('mail::subcopy')
{{ $subcopy }}
@endcomponent
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
@php
    $year = now()->format('Y');
    $supportEmail = config('mail.reply_to.address') ?? 'soporte@flexyflow.co';
@endphp

@php
    $brandHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: 'flexyflow.co';
    $brandUrl = rtrim((string) config('app.frontend_url'), '/') ?: 'https://flexyflow.co';
@endphp
**flexyflow** — diseñamos y operamos los flujos que hacen funcionar tu negocio de comida.
[{{ $brandHost }}]({{ $brandUrl }})

Recibes este correo porque tienes una cuenta activa en flexyflow.
¿Dudas? Escríbenos a [{{ $supportEmail }}](mailto:{{ $supportEmail }}).

[Política de privacidad](https://flexyflow.co/privacy-policy/) ·
[Términos y condiciones](https://flexyflow.co/terms-conditions/)

Copyright © {{ $year }} by flexyflow · Valle del Cauca, Colombia
@endcomponent
@endslot
@endcomponent
