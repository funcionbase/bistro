import { BrandLogo } from '@/components/brand-logo';
import { ConsentBanner } from '@/components/consent-banner';
import { Button } from '@/components/ui/button';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { useToken } from '@/hooks/use-token';
import { notifyIntroReady } from '@/lib/intro';
import { useDocumentTitle } from '@/lib/use-document-title';
import { useEffect } from 'react';
import { Link } from 'react-router-dom';

const DEMO_URL = 'https://flexyflow.co/#footer-with-contact';

/** Funcionalidades clave del manual — reemplazan el menú de referencia (homerun.co). */
const navLinks: Array<{ label: string; to: string }> = [
    { label: 'Pedidos', to: '/manual/bistro/pedidos' },
    { label: 'Caja y cobros', to: '/manual/bistro/caja' },
    { label: 'Inventario', to: '/manual/bistro/inventario' },
    { label: 'Fidelización', to: '/manual/bistro/fidelizacion' },
    { label: 'Métricas', to: '/manual/bistro/metricas' },
];

/* ponytail: logos demo — restaurantes locales de Pereira (pequeños/medianos,
   sin cadenas nacionales). Con logo real cuando se consiguió el asset
   (sitio oficial o página de Facebook); el resto, wordmark tipográfico. */
const demoLogos: Array<{ name: string; src?: string; wide?: boolean }> = [
    { name: 'La Lucerna', src: '/images/landing/la-lucerna.png', wide: true },
    { name: 'Ámbar' },
    { name: 'El Mesón Español', src: '/images/landing/el-meson-espanol.webp' },
    { name: 'Octava Maravilla' },
    { name: 'Latino Cocina Popular', src: '/images/landing/latino.png' },
    { name: 'Mediterráneo' },
    { name: 'El Olivo', src: '/images/landing/el-olivo.webp' },
    { name: 'Ébano' },
    { name: 'Asados Chispitas' },
    { name: 'Octavo' },
];

const wordmarkStyles = [
    'font-brand text-xl font-medium tracking-tight',
    'font-serif text-lg italic',
    'text-sm font-bold uppercase tracking-[0.22em]',
];

/**
 * Landing pública (#220, shell SPA), layout tipo homerun.co en una sola
 * pantalla sin scroll: nav superior, hero centrado y strip de logos. Si hay
 * marcador de sesión local el nav ofrece entrar al panel; el estado real de
 * la sesión lo resuelve el destino (dashboard → useBootstrap).
 */
export default function Welcome() {
    useDocumentTitle('Software para restaurantes del Eje Cafetero');
    const token = useToken();

    // Avisa al intro del shell (index.html) que la app ya montó — la cortina
    // inicia su salida (respetando su tiempo mínimo de exhibición).
    useEffect(() => {
        notifyIntroReady();
    }, []);

    return (
        <>
            <div className="bg-background flex h-svh flex-col overflow-hidden">
                {/* Nav superior: logo + funcionalidades clave + acceso */}
                <header className="px-6 py-4">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between gap-6">
                        <Link to="/" className="shrink-0" aria-label="bistro — inicio">
                            <BrandLogo className="h-7" />
                        </Link>

                        <div className="hidden items-center gap-6 lg:flex">
                            {navLinks.map((link) => (
                                <Link
                                    key={link.to}
                                    to={link.to}
                                    className="text-muted-foreground hover:text-foreground py-2 text-sm font-medium transition-colors"
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>

                        <div className="flex items-center gap-2 sm:gap-4">
                            {token ? (
                                <Button asChild className="rounded-full">
                                    <a href="/dashboard">Ir al panel</a>
                                </Button>
                            ) : (
                                <>
                                    {/* Visible también en mobile: es la única vía de login desde la landing. */}
                                    <Button asChild variant="ghost" className="rounded-full">
                                        <Link to="/login" className="text-muted-foreground">
                                            Iniciar sesión
                                        </Link>
                                    </Button>
                                    <a
                                        href={DEMO_URL}
                                        className="text-foreground hidden py-2 text-sm font-medium underline-offset-4 hover:underline md:block"
                                    >
                                        Agendar demo
                                    </a>
                                    <Button asChild className="rounded-full">
                                        <Link to="/register">Prueba gratis</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                {/* Hero estilo homerun: texto a la izquierda, collage de producto
                    sangrando por el borde derecho. En mobile se apila y el
                    collage se encoge (min-h-0) para conservar el no-scroll. */}
                <main className="mx-auto flex min-h-0 w-full max-w-6xl flex-1 flex-col items-center gap-6 px-6 pt-4 lg:grid lg:grid-cols-[1fr_1.05fr] lg:items-center lg:gap-10 lg:pt-0">
                    <HeroHeadline
                        className="flex max-w-xl flex-col items-center text-center lg:items-start lg:text-left"
                        eyebrow="Software para restaurantes"
                        title={
                            <>
                                El sistema simple{' '}
                                <br />
                                para tu restaurante.
                            </>
                        }
                        description="Menú digital con QR, pedidos, caja POS, inventario y facturación electrónica DIAN. Hecho para restaurantes pequeños y medianos de Pereira, Manizales y Armenia — sin instalaciones, sin licencias por terminal."
                        actions={
                            <div className="flex flex-wrap items-center justify-center gap-3 lg:justify-start">
                                <Button asChild size="lg" className="rounded-full font-semibold">
                                    <Link to="/register">Prueba gratis</Link>
                                </Button>
                                <Button asChild size="lg" variant="outline" className="rounded-full font-semibold">
                                    <a href={DEMO_URL}>Agendar demo</a>
                                </Button>
                            </div>
                        }
                    />

                    {/* Collage: en lg sangra hacia la derecha (max-w-none + overflow-hidden del root).
                        En pantallas apiladas y cortas se oculta — mejor sin imagen que ilegible. */}
                    <div className="flex min-h-0 w-full flex-1 items-center justify-center max-lg:[@media(max-height:800px)]:hidden lg:h-full lg:flex-none lg:justify-start">
                        <img
                            src="/images/landing/hero-collage.svg"
                            alt="Panel de bistro: comandas en cocina, cuenta de mesa, ventas del día, pedidos por QR y alertas de inventario"
                            className="h-full max-h-[38vh] w-auto rounded-3xl object-contain lg:max-h-[72vh] lg:max-w-none"
                        />
                    </div>
                </main>

                {/* Strip de logos — marquesina infinita estilo homerun con
                    restaurantes locales de Pereira (demo). Lista duplicada +
                    keyframes logo-marquee (-50% = un ciclo). */}
                <section aria-label="Restaurantes que confían en bistro" className="space-y-5 pb-8">
                    <p className="text-muted-foreground px-6 text-center text-xs font-semibold uppercase tracking-[0.18em]">
                        Con la confianza de restaurantes del Eje Cafetero
                    </p>
                    <div className="overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_12%,black_88%,transparent)]">
                        <div className="flex w-max animate-[logo-marquee_32s_linear_infinite] items-center opacity-80 grayscale motion-reduce:animate-none" aria-hidden>
                            {[...demoLogos, ...demoLogos].map((logo, i) => {
                                const idx = i % demoLogos.length;
                                if (logo.src) {
                                    return (
                                        <span key={i} className="flex shrink-0 items-center gap-2.5 whitespace-nowrap px-7">
                                            <img
                                                src={logo.src}
                                                alt={logo.name}
                                                className={logo.wide ? 'h-6 w-auto' : 'h-8 w-8 rounded-full object-cover'}
                                            />
                                            {!logo.wide && <span className="font-brand text-foreground text-lg font-medium tracking-tight">{logo.name}</span>}
                                        </span>
                                    );
                                }
                                return (
                                    <span key={i} className={`text-foreground shrink-0 whitespace-nowrap px-7 ${wordmarkStyles[idx % wordmarkStyles.length]}`}>
                                        {logo.name}
                                    </span>
                                );
                            })}
                        </div>
                    </div>
                </section>
            </div>
            <ConsentBanner />
        </>
    );
}
