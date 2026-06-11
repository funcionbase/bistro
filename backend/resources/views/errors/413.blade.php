@extends('errors.layout')

@section('title', '413 — Archivo demasiado grande')
@section('footer_label', 'Error 413')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        413 &middot; Archivo grande
    </span>
@endsection

@section('heading', 'El archivo pesa demasiado.')

@section('description', 'El archivo que intentaste subir supera el tamaño máximo permitido. Comprímelo o reduce su resolución y vuelve a intentar.')

@section('actions')
    <a href="javascript:history.back()" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver atrás
    </a>
    <a href="/" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Ir al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Cómo reducirlo')

@section('panel_body')
    <p>
        Para fotos: bajar a JPG/WebP con calidad 80% suele cortar el tamaño a la mitad sin perder nitidez. Para PDFs: imprimir a PDF con compresión "tamaño mínimo" desde el visor.
    </p>
@endsection

@section('panel_footer')
    Si el archivo es un comprobante de pago, también puedes enviárnoslo por correo.
@endsection
