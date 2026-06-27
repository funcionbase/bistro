import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
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
    children: React.ReactNode;
}

export default function ManualLayout({
    currentSlug,
    pageTitle,
    pageDescription,
    metaTitle,
    metaDescription,
    sectionLabel,
    readingTime,
    children,
}: ManualLayoutProps) {
    const { prev, next } = getPrevNext(currentSlug);
    const location = useLocation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const overlayRef = useRef<HTMLDivElement>(null);

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

        return () => {
            canonical?.remove();
        };
    }, [metaTitle, metaDescription, location.pathname]);

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
                                        className={`flex items-center rounded-md px-3 py-1.5 text-sm transition-colors ${
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
        <div className="min-h-screen bg-background text-foreground">
            {/* Header sticky */}
            <header className="sticky top-0 z-40 border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex h-14 max-w-7xl items-center gap-4 px-4 sm:px-6">
                    <a href="https://bistro.flexyflow.co" className="flex shrink-0 items-center gap-2">
                        <img src="/images/logo-black-font.svg" alt="flexyflow" className="h-7 w-auto dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt="flexyflow" className="hidden h-7 w-auto dark:block" />
                    </a>
                    <span className="hidden text-muted-foreground sm:block">/</span>
                    <Link to={MANUAL_BASE} className="hidden text-sm text-muted-foreground hover:text-foreground sm:block">
                        Manual de usuario
                    </Link>
                    <div className="flex-1" />
                    {/* Botón menú móvil */}
                    <button
                        className="flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted lg:hidden"
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
                                className="rounded-md p-1 text-muted-foreground hover:bg-muted"
                                aria-label="Cerrar"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
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

                    {/* Encabezado de página */}
                    <div className="mb-8">
                        {sectionLabel && (
                            <span className="mb-2 inline-flex items-center rounded-full bg-secondary px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-secondary-foreground">
                                {sectionLabel}
                            </span>
                        )}
                        <h1 className="font-brand mb-2 text-2xl font-medium tracking-tight text-foreground md:text-3xl">{pageTitle}</h1>
                        <p className="text-sm text-muted-foreground">{pageDescription}</p>
                        {readingTime && (
                            <p className="mt-2 text-xs text-muted-foreground">⏱ {readingTime} de lectura</p>
                        )}
                    </div>

                    {/* Contenido de la página */}
                    <div className="wiki-prose">{children}</div>

                    {/* Prev / Next */}
                    {(prev || next) && (
                        <div className="mt-12 flex items-center justify-between gap-4 border-t border-border pt-6">
                            {prev ? (
                                <Link
                                    to={manualPageUrl(prev.slug)}
                                    className="flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-3 text-sm hover:bg-muted"
                                >
                                    <ChevronLeft className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <div>
                                        <p className="text-xs text-muted-foreground">Anterior</p>
                                        <p className="font-medium">{prev.label}</p>
                                    </div>
                                </Link>
                            ) : (
                                <div />
                            )}
                            {next && (
                                <Link
                                    to={manualPageUrl(next.slug)}
                                    className="flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-3 text-sm hover:bg-muted"
                                >
                                    <div className="text-right">
                                        <p className="text-xs text-muted-foreground">Siguiente</p>
                                        <p className="font-medium">{next.label}</p>
                                    </div>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                </Link>
                            )}
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
            </div>
        </div>
    );
}
