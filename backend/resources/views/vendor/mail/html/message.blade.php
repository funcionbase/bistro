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
    $supportEmail = config('mail.reply_to.address') ?? 'hello@funcionbase.com';
@endphp

@php
    $brandHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: 'funcionbase.com';
    $brandUrl = rtrim((string) config('app.frontend_url'), '/') ?: 'https://funcionbase.com';
@endphp
**bistro** — diseñamos y operamos los flujos que hacen funcionar tu negocio de comida.
[{{ $brandHost }}]({{ $brandUrl }})

Recibes este correo porque tienes una cuenta activa en bistro.
¿Dudas? Escríbenos a [{{ $supportEmail }}](mailto:{{ $supportEmail }}).

[Política de privacidad](https://example.com/privacy-policy/) ·
[Términos y condiciones](https://example.com/terms-conditions/)

Copyright © {{ $year }} by bistro · Valle del Cauca, Colombia
@endcomponent
@endslot
@endcomponent
