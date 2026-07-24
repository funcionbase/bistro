import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { BrandLogo } from '@/components/brand-logo';
import { MANUAL_BASE, getPrevNext, manualPageUrl, wikiSections } from '@/lib/manual-nav';
import { ChevronLeft, ChevronRight, Menu, X } from 'lucide-react';

interface ManualLayoutProps {
    currentSlug: string;
    pageTitle: string;
    pageDescription: string;
    metaTitle: string;
    metaDescription: string;
    sectionLabel?: string;
    readingTime?: string;
    /** Fecha legible de la última actualización del contenido (ej. "8 de julio de 2026"). */
    lastUpdated?: string;
    children: React.ReactNode;
}

interface TocEntry {
    id: string;
    label: string;
}

/** Slug estable para anchors de TOC a partir del texto del h2. */
function slugifyHeading(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** Tarjeta Anterior/Siguiente del pie de página del manual. */
function PagerLink({ page, direction }: { page: { slug: string; label: string }; direction: 'prev' | 'next' }) {
    const isPrev = direction === 'prev';
    return (
        <Link
            to={manualPageUrl(page.slug)}
            className="flex min-h-[44px] items-center gap-2 rounded-lg border border-border bg-card px-4 py-3 text-sm hover:bg-muted"
        >
            {isPrev && <ChevronLeft className="h-4 w-4 shrink-0 text-muted-foreground" />}
            <div className={isPrev ? undefined : 'text-right'}>
                <p className="text-xs text-muted-foreground">{isPrev ? 'Anterior' : 'Siguiente'}</p>
                <p className="font-medium">{page.label}</p>
            </div>
            {!isPrev && <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />}
        </Link>
    );
}

export default function ManualLayout({
    currentSlug,
    pageTitle,
    pageDescription,
    metaTitle,
    metaDescription,
    sectionLabel,
    readingTime,
    lastUpdated,
    children,
}: ManualLayoutProps) {
    const { prev, next } = getPrevNext(currentSlug);
    const location = useLocation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [toc, setToc] = useState<TocEntry[]>([]);
    const overlayRef = useRef<HTMLDivElement>(null);
    const proseRef = useRef<HTMLDivElement>(null);

    // TOC "En esta página": autogenerado desde los h2 del contenido — asigna
    // ids slugificados sin requerir cambios en las páginas (estilo homerun).
    useEffect(() => {
        const headings = proseRef.current?.querySelectorAll('h2');
        if (!headings) return;
        const entries: TocEntry[] = [];
        headings.forEach((h) => {
            const label = h.textContent?.trim() ?? '';
            if (!label) return;
            const id = h.id || slugifyHeading(label);
            h.id = id;
            entries.push({ id, label });
        });
        setToc(entries);
    }, [location.pathname]);

    useEffect(() => {
        document.title = metaTitle;

        let meta = document.querySelector<HTMLMetaElement>('meta[name="description"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.name = 'description';
            document.head.appendChild(meta);
        }
        meta.content = metaDescription;

        let canonical = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
        if (!canonical) {
            canonical = document.createElement('link');
            canonical.rel = 'canonical';
            document.head.appendChild(canonical);
        }
        canonical.href = `https://bistro.flexyflow.co${location.pathname}`;

        // JSON-LD (breadcrumbs + artículo) para posicionamiento del manual —
        // Google lo lee del DOM renderizado.
        const jsonLd = document.createElement('script');
        jsonLd.type = 'application/ld+json';
        jsonLd.id = 'manual-jsonld';
        jsonLd.textContent = JSON.stringify([
            {
                '@context': 'https://schema.org',
                '@type': 'BreadcrumbList',
                itemListElement: [
                    { '@type': 'ListItem', position: 1, name: 'bistro', item: 'https://bistro.flexyflow.co/' },
                    { '@type': 'ListItem', position: 2, name: 'Manual de usuario', item: `https://bistro.flexyflow.co${MANUAL_BASE}` },
                    { '@type': 'ListItem', position: 3, name: pageTitle, item: `https://bistro.flexyflow.co${location.pathname}` },
                ],
            },
            {
                '@context': 'https://schema.org',
                '@type': 'TechArticle',
                headline: pageTitle,
                description: metaDescription,
                inLanguage: 'es-CO',
                author: { '@type': 'Organization', name: 'flexyflow', url: 'https://flexyflow.co' },
                about: 'Software de gestión para restaurantes: menú digital QR, POS, inventario y facturación DIAN',
            },
        ]);
        document.head.appendChild(jsonLd);

        return () => {
            canonical?.remove();
            jsonLd.remove();
        };
    }, [metaTitle, metaDescription, pageTitle, location.pathname]);

    // Cierra sidebar al navegar
    useEffect(() => {
        setSidebarOpen(false);
    }, [location.pathname]);

    const navContent = (
        <nav aria-label="Manual de usuario">
            {wikiSections.map((section) => (
                <div key={section.label} className="mb-5">
                    <p className="mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        {section.label}
                    </p>
                    <ul className="space-y-0.5">
                        {section.pages.map((page) => {
                            const isActive = page.slug === currentSlug;
                            return (
                                <li key={page.slug}>
                                    <Link
                                        to={manualPageUrl(page.slug)}
                                        className={`flex items-center rounded-md px-3 py-2 text-sm transition-colors ${
                                            isActive
                                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                                : 'text-muted-foreground hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground'
                                        }`}
                                        aria-current={isActive ? 'page' : undefined}
                                    >
                                        {page.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );

    return (
        <div className="min-h-dvh bg-background text-foreground">
            {/* Header sticky */}
            <header className="sticky top-0 z-40 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex h-14 max-w-7xl items-center gap-4 px-4 sm:px-6">
                    <a href="https://bistro.flexyflow.co" className="flex shrink-0 items-center gap-2" aria-label="bistro — inicio">
                        <BrandLogo className="h-7" />
                    </a>
                    <span className="hidden text-muted-foreground sm:block">/</span>
                    <Link to={MANUAL_BASE} className="hidden text-sm text-muted-foreground hover:text-foreground sm:block">
                        Manual de usuario
                    </Link>
                    <div className="flex-1" />
                    {/* Botón menú móvil */}
                    <button
                        className="flex min-h-[44px] items-center gap-2 rounded-md border border-border px-4 py-2 text-sm text-muted-foreground hover:bg-muted lg:hidden"
                        onClick={() => setSidebarOpen(true)}
                        aria-label="Abrir navegación"
                    >
                        <Menu className="h-4 w-4" />
                        <span>Menú</span>
                    </button>
                    <a
                        href="https://bistro.flexyflow.co"
                        className="hidden rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 lg:block"
                    >
                        Entrar al panel
                    </a>
                </div>
            </header>

            {/* Overlay móvil */}
            {sidebarOpen && (
                <div
                    ref={overlayRef}
                    className="fixed inset-0 z-50 bg-black/50 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                >
                    <aside
                        className="absolute left-0 top-0 h-full w-72 overflow-y-auto bg-card p-5 shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="mb-5 flex items-center justify-between">
                            <span className="text-sm font-semibold">Manual de usuario</span>
                            <button
                                onClick={() => setSidebarOpen(false)}
                                className="rounded-md p-2 text-muted-foreground hover:bg-muted"
                                aria-label="Cerrar"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                        {/* CTA accesible en mobile (en desktop vive en el header) */}
                        <a
                            href="https://bistro.flexyflow.co"
                            className="mb-5 flex min-h-[44px] items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Entrar al panel
                        </a>
                        {navContent}
                    </aside>
                </div>
            )}

            {/* Body */}
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:flex lg:gap-10">
                {/* Sidebar desktop */}
                <aside className="hidden w-60 shrink-0 lg:block">
                    <div className="sticky top-20 overflow-y-auto">{navContent}</div>
                </aside>

                {/* Contenido */}
                <main className="min-w-0 flex-1">
                    {/* Breadcrumbs */}
                    <nav className="mb-6 flex items-center gap-1.5 text-xs text-muted-foreground" aria-label="Breadcrumb">
                        <a href="https://bistro.flexyflow.co" className="hover:text-foreground">
                            bistro.flexyflow.co
                        </a>
                        <span>/</span>
                        <Link to={MANUAL_BASE} className="hover:text-foreground">
                            manual
                        </Link>
                        {currentSlug && (
                            <>
                                <span>/</span>
                                <span className="text-foreground">{pageTitle}</span>
                            </>
                        )}
                    </nav>

                    {/* Encabezado de página — H1 display estilo homerun */}
                    <div className="mb-8">
                        {sectionLabel && (
                            <span className="mb-3 inline-flex items-center rounded-full bg-secondary px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-secondary-foreground">
                                {sectionLabel}
                            </span>
                        )}
                        <h1 className="font-brand mb-3 text-3xl font-medium leading-[1.05] tracking-[-0.02em] text-foreground md:text-4xl lg:text-5xl">
                            {pageTitle}
                        </h1>
                        <p className="max-w-[46rem] text-base text-muted-foreground">{pageDescription}</p>
                        {/* Meta inline (mobile/tablet) — en xl vive en el rail derecho */}
                        {(lastUpdated || readingTime) && (
                            <p className="mt-3 text-xs text-muted-foreground xl:hidden">
                                {lastUpdated && <>Actualizado: {lastUpdated}</>}
                                {lastUpdated && readingTime && <> · </>}
                                {readingTime && <>⏱ {readingTime} de lectura</>}
                            </p>
                        )}
                    </div>

                    {/* TOC mobile/tablet — en xl vive en el rail derecho */}
                    {toc.length > 1 && (
                        <details className="border-border bg-card mb-6 max-w-[46rem] rounded-lg border px-4 py-3 xl:hidden">
                            <summary className="cursor-pointer select-none text-sm font-semibold">En esta página</summary>
                            <ul className="mt-2 space-y-1">
                                {toc.map((entry) => (
                                    <li key={entry.id}>
                                        <a
                                            href={`#${entry.id}`}
                                            className="text-muted-foreground hover:text-foreground block py-1.5 text-sm transition-colors"
                                        >
                                            {entry.label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}

                    {/* Contenido de la página — ancho de lectura ~720px */}
                    <div ref={proseRef} className="wiki-prose max-w-[46rem]">
                        {children}
                    </div>

                    {/* Prev / Next */}
                    {(prev || next) && (
                        <div className="mt-12 flex items-center justify-between gap-4 border-t border-border pt-6">
                            {prev ? <PagerLink page={prev} direction="prev" /> : <div />}
                            {next && <PagerLink page={next} direction="next" />}
                        </div>
                    )}

                    {/* Footer mínimo */}
                    <footer className="mt-16 border-t border-border pt-6 text-center text-xs text-muted-foreground">
                        <p>
                            <a href="https://bistro.flexyflow.co" className="hover:text-foreground">
                                bistro.flexyflow.co
                            </a>{' '}
                            &nbsp;·&nbsp;{' '}
                            <a href="https://flexyflow.co" className="hover:text-foreground">
                                flexyflow.co
                            </a>
                        </p>
                    </footer>
                </main>

                {/* Rail derecho (xl): meta + TOC "En esta página" — estilo homerun */}
                <aside className="hidden w-56 shrink-0 xl:block" aria-label="Información de la página">
                    <div className="sticky top-20 space-y-6 text-sm">
                        {(lastUpdated || sectionLabel || readingTime) && (
                            <div className="space-y-3">
                                {lastUpdated && (
                                    <div>
                                        <p className="font-semibold text-foreground">Última actualización</p>
                                        <p className="text-muted-foreground">{lastUpdated}</p>
                                    </div>
                                )}
                                {sectionLabel && (
                                    <div>
                                        <p className="font-semibold text-foreground">Sección</p>
                                        <p className="text-muted-foreground">{sectionLabel}</p>
                                    </div>
                                )}
                                {readingTime && (
                                    <div>
                                        <p className="font-semibold text-foreground">Lectura</p>
                                        <p className="text-muted-foreground">{readingTime}</p>
                                    </div>
                                )}
                            </div>
                        )}
                        {toc.length > 0 && (
                            <nav aria-label="En esta página">
                                <p className="mb-2 border-b border-border pb-2 font-semibold text-foreground">En esta página</p>
                                <ul className="space-y-2">
                                    {toc.map((entry) => (
                                        <li key={entry.id}>
                                            <a
                                                href={`#${entry.id}`}
                                                className="text-muted-foreground transition-colors hover:text-foreground"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    document.getElementById(entry.id)?.scrollIntoView({ behavior: 'smooth' });
                                                    window.history.replaceState(null, '', `#${entry.id}`);
                                                }}
                                            >
                                                {entry.label}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        )}
                    </div>
                </aside>
            </div>
        </div>
    );
}
