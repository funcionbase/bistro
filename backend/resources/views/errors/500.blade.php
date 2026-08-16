@extends('errors.layout')

@section('title', '500 — Error inesperado')
@section('footer_label', 'Error 500')

@section('eyebrow')
    <span class="bg-destructive/15 text-destructive inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        500 &middot; Error del servidor
    </span>
@endsection

@section('heading', 'Algo se rompió de nuestro lado.')

@section('description', 'Ya estamos viendo qué pasó. Vuelve a intentarlo en un momento — si la falla persiste, escríbenos y lo resolvemos.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
    <a href="mailto:hello@funcionbase.com?subject=Error%20500%20en%20bistro" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Contactar soporte
    </a>
@endsection

@section('panel_eyebrow', 'Lo estamos viendo')

@section('panel_body')
    <p>
        Las fallas inesperadas se registran automáticamente. Si esta operación era crítica (un cobro, un cierre de caja) escríbenos a <span class="text-foreground font-medium">hello@funcionbase.com</span> para revisarlo de inmediato.
    </p>
@endsection

@section('panel_footer')
    Si era un guardado, vuelve a intentarlo en un par de minutos.
@endsection
