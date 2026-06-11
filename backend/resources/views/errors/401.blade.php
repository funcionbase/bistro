@extends('errors.layout')

@section('title', '401 — Sesión necesaria')
@section('footer_label', 'Error 401')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        401 &middot; Sin sesión
    </span>
@endsection

@section('heading', 'Inicia sesión para continuar.')

@section('description', 'Tu sesión expiró o este recurso requiere autenticación. Vuelve a iniciar con Google y retomamos donde estabas.')

@section('actions')
    <a href="{{ route('auth.google') }}" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Iniciar sesión con Google
    </a>
    <a href="/" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
@endsection

@section('panel_eyebrow', 'Acceso seguro')

@section('panel_body')
    <p>
        Las sesiones caducan por seguridad después de un tiempo de inactividad o cuando cierras sesión en otro dispositivo. Ningún dato se pierde — todo queda guardado en tu cuenta.
    </p>
@endsection

@section('panel_footer')
    Solo permitimos acceso con cuentas de Google.
@endsection
