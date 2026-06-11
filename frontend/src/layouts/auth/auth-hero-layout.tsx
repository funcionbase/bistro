import { type ReactNode, useEffect, useState } from 'react';

import { AppLink } from '@/components/app-link';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { OnboardingPageSkeleton } from '@/components/ui/onboarding-page-skeleton';
import { route } from '@/lib/route-compat';

interface AuthHeroLayoutProps {
    eyebrow?: string;
    title: ReactNode;
    description?: ReactNode;
    /**
     * Eyebrow del panel lateral lime. Default `Acceso seguro`.
     */
    panelEyebrow?: string;
    /**
     * Stats grandes del panel lateral. Default usa 3 pilares constantes
     * (`Acceso`, `Privacidad`, `Sesión`) para que la página no se vea vacía.
     */
    panelStats?: Array<{ label: string; value: ReactNode }>;
    /**
     * Footer del panel lateral — típicamente un párrafo descriptivo de
     * confianza/política.
     */
    panelFooter?: ReactNode;
    children: ReactNode;
}

const defaultPanelStats: Array<{ label: string; value: string }> = [
    { label: 'Acceso', value: 'Google' },
    { label: 'Privacidad', value: 'Cifrado' },
    { label: 'Sesión', value: 'Persistente' },
];

/**
 * Layout editorial 2-col para las pantallas de auth sin sesión (forgot,
 * reset, confirm, verify). Mismo shell que `auth/company-selector` y el resto
 * del onboarding F15: logo + `HeroHeadline` + slot de formulario a la
 * izquierda, `HeroPanel` lime con stats + footer a la derecha. En mobile cae
 * a stack vertical y oculta el panel.
 *
 * Mientras React hidrata el bundle, muestra `OnboardingPageSkeleton` para que
 * en conexiones lentas la página no se quede en blanco. El skeleton se
 * cambia por el contenido real en el primer `useEffect` tras montar (ver
 * FRONTEND_UI_GUIDELINES.md §13 — loading).
 *
 * Ver §6.2b (hero 2-col), §11 (formularios), §15 (mobile-first).
 */
export default function AuthHeroLayout({
    eyebrow,
    title,
    description,
    panelEyebrow = 'Acceso seguro',
    panelStats = defaultPanelStats,
    panelFooter,
    children,
}: AuthHeroLayoutProps) {
    const name = 'flexyflow';
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    if (!mounted) {
        return <OnboardingPageSkeleton layout="form" />;
    }

    return (
        <div className="bg-background flex min-h-dvh items-center justify-center px-4 pt-[max(2rem,env(safe-area-inset-top,0px))] pb-[max(2rem,env(safe-area-inset-bottom,0px))] md:px-8 md:pt-[max(2rem,env(safe-area-inset-top,0px))] md:pb-[max(2rem,env(safe-area-inset-bottom,0px))]">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    {/* Columna izquierda: logo + headline + form */}
                    <div className="flex flex-col gap-6 sm:gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                        <AppLink href={route('home')} className="inline-flex w-fit">
                            <img src="/images/logo-black-font.svg" alt={name} className="block h-8 w-auto md:h-10 dark:hidden" />
                            <img src="/images/logo-white-font.svg" alt={name} className="hidden h-8 w-auto md:h-10 dark:block" />
                        </AppLink>

                        <HeroHeadline eyebrow={eyebrow} title={title} description={description} />

                        <div className="space-y-6">{children}</div>
                    </div>

                    {/* Columna derecha: panel lime con stats + footer */}
                    <HeroPanel
                        eyebrow={panelEyebrow}
                        className="order-last hidden md:col-span-5 md:flex lg:col-span-5"
                        footer={
                            panelFooter ?? (
                                <p className="text-sm leading-relaxed opacity-80">
                                    Tu sesión se asocia a la empresa elegida. Cifrado en tránsito y en reposo, sin contraseñas que mantener.
                                </p>
                            )
                        }
                    >
                        <HeroPanelStats stats={panelStats} />
                    </HeroPanel>
                </div>
            </div>
        </div>
    );
}
