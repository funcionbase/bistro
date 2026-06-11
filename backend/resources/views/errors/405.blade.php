@extends('errors.layout')

@section('title', '405 — Método no permitido')
@section('footer_label', 'Error 405')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        405 &middot; Método no permitido
    </span>
@endsection

@section('heading', 'Esta acción no se puede ejecutar así.')

@section('description', 'El recurso existe, pero solo acepta otro tipo de petición. Esto suele pasar al abrir en el navegador un enlace pensado para enviarse desde la app.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', '¿Qué pasó?')

@section('panel_body')
    <p>
        Algunas acciones — como cerrar sesión — solo se ejecutan desde un botón dentro de la app. Si llegaste aquí pegando una URL en la barra del navegador, vuelve al inicio y úsala desde el menú.
    </p>
@endsection

@section('panel_footer')
    Si seguís viendo este error desde un botón de la app, contanos — es un bug nuestro.
@endsection
