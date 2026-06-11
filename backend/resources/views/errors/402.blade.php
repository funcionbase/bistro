@extends('errors.layout')

@section('title', '402 — Pago requerido')
@section('footer_label', 'Error 402')

@section('eyebrow')
    <span class="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)] inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        402 &middot; Pago requerido
    </span>
@endsection

@section('heading', 'Tu suscripción necesita atención.')

@section('description', 'Para usar esta sección hay un pago pendiente o la suscripción está vencida. Resuélvelo desde el panel de facturación — toma menos de un minuto.')

@section('actions')
    <a href="/billing" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Ir a facturación
    </a>
    <a href="mailto:info@flexyflow.co?subject=Soporte%20de%20facturación" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Contactar soporte
    </a>
@endsection

@section('panel_eyebrow', 'Operación pausada')

@section('panel_body')
    <p>
        Tu cuenta no se elimina ni se borran tus datos: solo quedan limitadas las funciones de cobro hasta que la suscripción esté al día.
    </p>
@endsection

@section('panel_footer')
    Si ya pagaste y sigue apareciendo este aviso, escríbenos con el comprobante.
@endsection
