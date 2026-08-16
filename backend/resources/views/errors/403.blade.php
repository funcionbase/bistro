@extends('errors.layout')

@section('title', '403 — Sin permisos')
@section('footer_label', 'Error 403')

@section('eyebrow')
    <span class="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
        403 &middot; Sin permisos
    </span>
@endsection

@section('heading', 'No tienes permiso para ver esto.')

@section('description', 'Tu rol actual no incluye acceso a esta sección. Si crees que es un error, pídele al propietario de la empresa que actualice tus permisos.')

@section('actions')
    <a href="/" class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Volver al inicio
    </a>
    <a href="/auth/company-selector" class="border-input bg-background text-foreground hover:bg-muted focus-visible:ring-ring inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2">
        Cambiar de empresa
    </a>
@endsection

@section('panel_eyebrow', 'Permisos por rol')

@section('panel_body')
    <p>
        En bistro el acceso a cada módulo se asigna por rol. Si necesitas entrar a esta sección, el propietario puede ajustar tu rol desde <span class="text-foreground font-medium">Gestión &rsaquo; Usuarios</span>.
    </p>
@endsection

@section('panel_footer')
    También puedes cambiar de empresa si tienes acceso a más de un restaurante.
@endsection
