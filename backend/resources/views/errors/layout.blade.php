{{--
    Layout base de páginas de error (DS v3.1, FRONTEND_UI_GUIDELINES.md §4/§6.2b).

    Calca visualmente el patrón hero 2-col usado en welcome / auth/company-selector
    / enrollment/company: container `max-w-6xl` + grid de 12 col, logo + headline
    a la izquierda, panel `card`/`foreground` a la derecha. Las clases utilitarias
    son las mismas que ya viven en el bundle de la SPA, así que Tailwind las
    compila sin agregar nada al CSS.

    Autónomo: carga un CSS precompilado estático (public/css/errors.css), sin
    Vite ni Tailwind en runtime. Se renderiza incluso si todo lo demás está caído.

    Para visitantes anónimos: nunca muestra logo/colores de `activeCompany` (esas
    shared props pertenecen al contexto autenticado). Usa siempre el wordmark
    genérico de bistro.

    Hijos disponibles (vía @yield/@section):
    - title          string corto para <title>
    - eyebrow        bloque con la pill encima del H1 (la vista decide colores)
    - heading        H1 grande con font-brand (único momento de marca de la vista)
    - description    párrafo descriptivo
    - actions        CTAs (Volver al inicio, Iniciar sesión, Soporte, etc.)
    - panel_eyebrow  pill que va dentro del panel derecho
    - panel_body     párrafo/contenido del panel derecho. NO usar font-brand aquí
                     — el DS §4 reserva font-brand para el H1 hero; el panel
                     replica el patrón de HeroPanel (eyebrow + body + footer).
    - panel_footer   footer opcional del panel (nota corta)
    - footer_label   texto chico al pie del documento (e.g. "Error 404")
    - scripts        bloque opcional para JS inline (p.ej. auto-reintento del 503)

    Clases canónicas para CTAs en @section('actions') (sin React Button disponible):
    - Primary:  bg-primary text-primary-foreground hover:bg-primary/90
                focus-visible:ring-ring inline-flex h-11 items-center justify-center
                gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium
                transition-colors focus-visible:outline-none focus-visible:ring-2
                focus-visible:ring-offset-2
    - Outline:  border-input bg-background text-foreground hover:bg-muted
                focus-visible:ring-ring inline-flex h-11 items-center justify-center
                gap-2 whitespace-nowrap rounded-md border px-4 py-2 text-sm font-medium
                transition-colors focus-visible:outline-none focus-visible:ring-2
                focus-visible:ring-offset-2

    Notas DS:
    - h-11 (44px) es el touch target mínimo (DS §5/§17).
    - hover:bg-muted en outline (NO hover:bg-accent) — el lime está prohibido
      en hover (DS §3 anti-saturación 6).
    - El eyebrow puede ser bg-secondary (neutro, default), bg-destructive/15
      text-destructive (500 — error del sistema), o
      bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]
      (419/503 — sesión expirada / mantenimiento).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'Error') · bistro</title>

    {{-- Iconos: mismo set que el index.html del frontend. El SVG es el favicon
         real en navegadores modernos; el PNG 192 cubre los que no renderizan
         SVG; favicon.ico es el fallback clásico. --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/icons/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon-180.png">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f6f5f3">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#1E232E">

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <link rel="preload" href="/fonts/FlexyFont.woff2" as="font" type="font/woff2" crossorigin>

    {{-- CSS precompilado estático (#220). El backend ya no corre Vite/Tailwind;
         este artefacto se genera con `npm run gen:backend-error-css` desde el
         frontend. Las páginas de error se renderizan sin depender de ningún build. --}}
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">

</head>
<body class="bg-background text-foreground font-sans antialiased">
    <div class="bg-background flex min-h-svh items-center justify-center p-4 md:p-8">
        <div class="w-full max-w-6xl">
            <div class="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                {{-- Columna izquierda: logo + hero + CTAs (col-span-7, mismo split que welcome/auth) --}}
                <div class="flex flex-col gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                    <a href="/" class="inline-flex items-center" aria-label="bistro inicio">
                        <img src="/images/logo-black-font.svg" alt="bistro" class="block h-9 w-auto md:h-10 dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt="bistro" class="hidden h-9 w-auto md:h-10 dark:block" />
                    </a>

                    {{-- HeroHeadline replicado en Blade: eyebrow + H1 font-brand + description --}}
                    <div class="space-y-5">
                        @hasSection('eyebrow')
                            @yield('eyebrow')
                        @endif

                        <h1 class="font-brand text-foreground text-4xl font-medium leading-[1.05] tracking-[-0.02em] md:text-5xl lg:text-6xl">
                            @yield('heading')
                        </h1>

                        @hasSection('description')
                            <p class="text-muted-foreground max-w-xl text-base md:text-lg">
                                @yield('description')
                            </p>
                        @endif
                    </div>

                    @hasSection('actions')
                        <div class="flex flex-wrap items-center gap-3">
                            @yield('actions')
                        </div>
                    @endif

                    <p class="text-muted-foreground text-xs">
                        &copy; {{ date('Y') }} bistro &middot; @yield('footer_label')
                    </p>
                </div>

                {{-- Columna derecha: HeroPanel `tone=card` replicado en Blade.
                     Usamos `card` (no `accent` lime) porque en pantalla de error
                     el tono lime se sentiría fuera de lugar; card mantiene el
                     patrón visual pero respetando el contexto. --}}
                <aside class="bg-card text-card-foreground border-border flex flex-col justify-between gap-8 rounded-3xl border p-6 md:col-span-5 md:p-8 lg:col-span-5 lg:p-10">
                    @hasSection('panel_eyebrow')
                        <span class="bg-foreground text-background inline-flex w-fit items-center rounded-full px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em]">
                            @yield('panel_eyebrow')
                        </span>
                    @endif

                    <div class="text-muted-foreground space-y-3 text-base leading-relaxed md:text-lg">
                        @yield('panel_body')
                    </div>

                    @hasSection('panel_footer')
                        <p class="text-muted-foreground text-sm leading-relaxed">
                            @yield('panel_footer')
                        </p>
                    @endif
                </aside>
            </div>
        </div>
    </div>

    @yield('scripts')
</body>
</html>
