@extends('errors.layout')

{{--
    Fallback genérico para códigos 5xx que no tienen vista específica.
    Laravel resuelve primero el código exacto, y si no existe cae a este.
    Tapa 501 / 505 / 507 / 508 / 510 / 511 / etc.

    `$exception` está disponible. Solo exponemos el statusCode + frase
    corta — NUNCA el message crudo, que puede filtrar paths o internals.
--}}
@php
    $status = isset($exception) && method_exists($exception, 'getStatusCode')
        ? $exception->getStatusCode()
        : 500;
@endphp

@section('title', $status . ' — Error del servidor')
@section('footer_label', 'Error ' . $status)

@section('eyebrow')
    <span class="bg-destructive/15 text-destructive inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        {{ $status }} &middot; Error del servidor
    </span>
@endsection

@section('heading', 'Algo se rompió de nuestro lado.')

@section('description', 'No es algo que hayas hecho mal. El equipo recibe estos errores automáticamente y los revisa. Espera un par de minutos antes de reintentar para no duplicar la operación.')

@section('actions')
    <a href="javascript:location.reload()" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Reintentar
    </a>
    <a href="/" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Ir al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Lo estamos viendo')

@section('panel_body')
    <p>
        Las fallas del servidor se registran automáticamente y disparan alertas a oncall. Si esto bloquea una operación crítica (cobro, cierre de caja, factura) escríbenos a <span class="text-foreground font-medium">info@flexyflow.co</span> para revisarlo de inmediato.
    </p>
@endsection

@section('panel_footer')
    Antes de reintentar un cobro, revisa el listado — la transacción puede haber quedado guardada.
@endsection
