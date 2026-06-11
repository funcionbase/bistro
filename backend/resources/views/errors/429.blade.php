@extends('errors.layout')

@section('title', '429 — Demasiados intentos')
@section('footer_label', 'Error 429')

@section('eyebrow')
    <span class="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)] inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        429 &middot; Frena un momento
    </span>
@endsection

@section('heading', 'Demasiados intentos seguidos.')

@section('description', 'Por seguridad pausamos las peticiones de esta IP durante un minuto. Espera un poco y vuelve a intentar — no perdiste ningún dato.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Por qué pasa')

@section('panel_body')
    <p>
        Limitamos los reintentos rápidos para protegerte de bots y fugas de credenciales. Tu cuenta sigue activa — solo esta IP queda en espera por unos segundos.
    </p>
@endsection

@section('panel_footer')
    Si sigue fallando después de un minuto, captura la pantalla y escríbenos.
@endsection
