@extends('errors.layout')

@section('title', '404 — Página no encontrada')
@section('footer_label', 'Error 404')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        404 &middot; No encontrada
    </span>
@endsection

@section('heading', 'Esta página no existe.')

@section('description', 'El enlace que seguiste puede estar roto o la página puede haberse movido a otra ubicación.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', '¿Buscabas algo?')

@section('panel_body')
    <p>
        Si llegaste aquí desde un enlace antiguo, vuelve al inicio y navega desde el menú principal. Algunas secciones cambiaron de ruta con el rediseño.
    </p>
@endsection

@section('panel_footer')
    Puedes seguir operando con normalidad — solo este recurso no existe.
@endsection
