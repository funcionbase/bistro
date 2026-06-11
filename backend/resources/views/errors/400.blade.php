@extends('errors.layout')

@section('title', '400 — Solicitud inválida')
@section('footer_label', 'Error 400')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        400 &middot; Solicitud inválida
    </span>
@endsection

@section('heading', 'No entendimos esta solicitud.')

@section('description', 'La petición que hiciste llegó incompleta o con datos que no pudimos leer. Suele pasar al pegar una URL editada a mano o al recargar un formulario antiguo.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', '¿Qué pasó?')

@section('panel_body')
    <p>
        Vuelve al inicio y repite la acción desde el menú principal. Si fue un guardado, revisa que los campos no tengan caracteres raros copiados de otro lado.
    </p>
@endsection

@section('panel_footer')
    Si vuelve a pasar desde un botón de la app, contanos — es un bug nuestro.
@endsection
