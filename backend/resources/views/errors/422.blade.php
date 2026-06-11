@extends('errors.layout')

@section('title', '422 — Datos inválidos')
@section('footer_label', 'Error 422')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        422 &middot; Datos inválidos
    </span>
@endsection

@section('heading', 'Algunos datos no pasaron la validación.')

@section('description', 'La petición llegó bien, pero los datos no cumplen el formato esperado. Vuelve al formulario y revisa los campos resaltados.')

@section('actions')
    <a href="javascript:history.back()" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver atrás
    </a>
    <a href="/" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Ir al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Revisa los campos')

@section('panel_body')
    <p>
        Los errores comunes: correos sin <span class="text-foreground font-medium">@</span>, montos con letras, fechas en formato distinto al solicitado o caracteres especiales en campos de texto simple.
    </p>
@endsection

@section('panel_footer')
    Si los datos están bien y sigue fallando, captura la pantalla y escríbenos.
@endsection
