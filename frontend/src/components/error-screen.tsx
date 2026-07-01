import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel } from '@/components/ui/hero-panel';
import { useDocumentTitle } from '@/lib/use-document-title';
import { type ReactNode } from 'react';

interface ErrorScreenProps {
    /** Título de la pestaña del navegador (sin el sufijo de marca). */
    documentTitle: string;
    /** Pill uppercase encima del H1 (ej. `404 · No encontrada`). */
    eyebrow: string;
    /** Título hero. Acepta JSX para partir la frase con `<br />`. */
    title: ReactNode;
    /** Bajada descriptiva debajo del H1. */
    description: string;
    /** CTAs — típicamente uno o dos `<Button>`. */
    actions: ReactNode;
    /** Texto chico al pie del documento (ej. `Error 404`). */
    footerLabel: string;
    /** Pill uppercase del panel derecho. */
    panelEyebrow: string;
    /** Cuerpo del panel derecho. */
    panelBody: ReactNode;
    /** Nota opcional al pie del panel. */
    panelFooter?: ReactNode;
}

/**
 * Shell de página de error del frontend SPA — port React de
 * `resources/views/errors/layout.blade.php`.
 *
 * Replica el patrón hero 2-col del DS (logo + `HeroHeadline` + CTAs a la
 * izquierda, `HeroPanel` a la derecha) que comparten `welcome`, `enrollment/*`
 * y los selectores de auth. Mantiene la 404 y la pantalla de fallo de runtime
 * visualmente consistentes con el resto del onboarding y con las páginas de
 * error Blade del backend.
 *
 * El panel usa `tone="card"` (neutro), nunca `accent` (lime): el DS §3
 * (anti-saturación 8) reserva el lime para momentos celebrables — una
 * pantalla de error no lo es.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §4 (tipografía hero) y §6.2b (catálogo hero).
 */
export function ErrorScreen({
    documentTitle,
    eyebrow,
    title,
    description,
    actions,
    footerLabel,
    panelEyebrow,
    panelBody,
    panelFooter,
}: ErrorScreenProps) {
    useDocumentTitle(documentTitle);

    return (
        <div className="bg-background flex min-h-svh items-center justify-center px-4 py-8 md:p-8">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    {/* Columna izquierda: logo + hero + CTAs */}
                    <div className="flex flex-col gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                        <a href="/" className="inline-flex w-fit items-center" aria-label="bistro inicio">
                            <img src="/images/logo-black-font.svg" alt="bistro" className="block h-9 w-auto md:h-10 dark:hidden" />
                            <img src="/images/logo-white-font.svg" alt="bistro" className="hidden h-9 w-auto md:h-10 dark:block" />
                        </a>

                        <HeroHeadline eyebrow={eyebrow} title={title} description={description} />

                        <div className="flex flex-wrap items-center gap-3">{actions}</div>

                        <p className="text-muted-foreground text-xs">
                            &copy; {new Date().getFullYear()} flexyflow &middot; {footerLabel}
                        </p>
                    </div>

                    {/* Columna derecha: panel neutro `card` — sin lime (DS §3) */}
                    <HeroPanel
                        eyebrow={panelEyebrow}
                        tone="card"
                        className="md:col-span-5 lg:col-span-5"
                        footer={panelFooter ? <p className="text-muted-foreground text-sm leading-relaxed">{panelFooter}</p> : undefined}
                    >
                        <div className="text-muted-foreground space-y-3 text-base leading-relaxed md:text-lg">{panelBody}</div>
                    </HeroPanel>
                </div>
            </div>
        </div>
    );
}
