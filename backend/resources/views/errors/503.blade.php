@extends('errors.layout')

@section('title', '503 — Mantenimiento')
@section('footer_label', 'Error 503')

@section('eyebrow')
    <span class="inline-flex items-center rounded-full bg-[color:var(--color-status-warning)]/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-[color:var(--color-status-warning)]">
        503 &middot; En mantenimiento
    </span>
@endsection

@section('heading', 'Estamos actualizando la app.')

@section('description', 'Esta pantalla se recarga sola cada 30 segundos. En cuanto termine el despliegue vuelves a entrar — no necesitas volver a iniciar sesión.')

@section('actions')
    <button type="button" onclick="window.location.reload()" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Recargar ahora
    </button>
@endsection

@section('panel_eyebrow', 'Mantenimiento breve')

@section('panel_body')
    <p>
        Estamos publicando una versión nueva. Los despliegues normalmente toman menos de 60 segundos — la página se recarga sola cuando vuelve a estar lista.
    </p>
@endsection

@section('panel_footer')
    Los pedidos en curso no se pierden — se reanudan al volver.
@endsection

@section('scripts')
    {{-- Auto-reintento cada 30s para que el usuario no tenga que refrescar
         manualmente durante un deploy o un mantenimiento breve. --}}
    <script>
        setTimeout(function () {
            window.location.reload();
        }, 30000);
    </script>
@endsection
