@extends('errors.layout')

@section('title', '419 — Sesión expirada')
@section('footer_label', 'Error 419')

@section('eyebrow')
    <span class="inline-flex items-center rounded-full bg-[color:var(--color-status-warning)]/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-[color:var(--color-status-warning)]">
        419 &middot; Sesión vencida
    </span>
@endsection

@section('heading', 'Tu sesión expiró por inactividad.')

@section('description', 'Por seguridad cerramos tu sesión después de un tiempo sin actividad. Vuelve a iniciar sesión para continuar donde quedaste.')

@section('actions')
    <a href="/auth" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Iniciar sesión
    </a>
@endsection

@section('panel_eyebrow', 'Sesión segura')

@section('panel_body')
    <p>
        Después de un tiempo sin actividad cerramos tu sesión automáticamente. Es una medida estándar para proteger la caja, los pagos y los datos de tus clientes.
    </p>
@endsection

@section('panel_footer')
    Tu información sigue intacta — al iniciar sesión retomas donde quedaste.
@endsection
