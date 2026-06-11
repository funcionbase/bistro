@extends('errors.layout')

@section('title', '502 — Conexión interrumpida')
@section('footer_label', 'Error 502')

@section('eyebrow')
    <span class="bg-destructive/15 text-destructive inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        502 &middot; Bad gateway
    </span>
@endsection

@section('heading', 'No alcanzamos el servidor.')

@section('description', 'Algo entre tu conexión y nosotros falló al pasar la petición. Suele resolverse al refrescar — el equipo monitorea estos eventos automáticamente.')

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
        Los errores 502 se registran y disparan alertas a oncall. Si era una operación crítica (cobro, cierre de caja), espera un par de minutos antes de reintentar para no duplicarla.
    </p>
@endsection

@section('panel_footer')
    Si esto persiste, escríbenos a info@flexyflow.co — revisamos al toque.
@endsection
