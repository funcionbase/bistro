@extends('errors.layout')

{{--
    Fallback genérico para códigos 4xx que no tienen vista específica
    (`errors/{code}.blade.php`). Laravel resuelve primero el código exacto, y
    si no existe cae a este. Tapa 409 / 410 / 415 / 423 / 425 / 426 / 428 /
    431 / 451 / etc.

    `$exception` está disponible (Symfony\Component\HttpKernel\Exception\HttpException).
    Mostramos solo el statusCode + frase corta, NUNCA el message crudo
    (suele exponer detalles internos).
--}}
@php
    $status = isset($exception) && method_exists($exception, 'getStatusCode')
        ? $exception->getStatusCode()
        : 400;
@endphp

@section('title', $status . ' — No pudimos completar la solicitud')
@section('footer_label', 'Error ' . $status)

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        {{ $status }} &middot; Solicitud rechazada
    </span>
@endsection

@section('heading', 'No pudimos completar esta solicitud.')

@section('description', 'La petición no se procesó porque algo en ella no cumplió las reglas del sistema. Vuelve al inicio y repite la acción desde el menú.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', '¿Qué pasó?')

@section('panel_body')
    <p>
        Recibimos tu petición, pero las condiciones para procesarla no se cumplieron — puede ser que el recurso ya no exista, que el formato no sea el esperado o que falte un permiso. Repetir la acción desde el menú principal suele resolverlo.
    </p>
@endsection

@section('panel_footer')
    Si vuelve a pasar desde un botón de la app, captura la pantalla y escríbenos.
@endsection
