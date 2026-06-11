@extends('errors.layout')

@section('title', '504 — Tiempo agotado')
@section('footer_label', 'Error 504')

@section('eyebrow')
    <span class="bg-destructive/15 text-destructive inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        504 &middot; Tiempo agotado
    </span>
@endsection

@section('heading', 'La respuesta tardó demasiado.')

@section('description', 'El servidor recibió tu petición pero tardó más de lo permitido en responder. Probablemente quedó procesándose en segundo plano.')

@section('actions')
    <a href="javascript:location.reload()" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Reintentar
    </a>
    <a href="/" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Ir al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Antes de reintentar')

@section('panel_body')
    <p>
        Si la operación cambiaba dinero o estados — un cobro, un cierre de caja, una refund — espera un par de minutos y revisa el listado antes de repetir, para no duplicar el asiento.
    </p>
@endsection

@section('panel_footer')
    Las operaciones financieras quedan envueltas en transacción: si no aparece, no se guardó.
@endsection
