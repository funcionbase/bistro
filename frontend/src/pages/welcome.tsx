import { ConsentBanner } from '@/components/consent-banner';
import GoogleAuthButton from '@/components/google-auth-button';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { useTiktokPixel } from '@/hooks/use-tiktok-pixel';
import { useToken } from '@/hooks/use-token';
import { useDocumentTitle } from '@/lib/use-document-title';

const heroStats: Array<{ label: string; value: string }> = [
    { label: 'Operación', value: '24/7' },
    { label: 'Cobros', value: 'Sin fricción' },
    { label: 'Reportes', value: 'En vivo' },
];

/**
 * Landing pública (#220, shell SPA). Si hay marcador de sesión local
 * ofrece entrar al panel; si no, el acceso por Google. El estado real de
 * la sesión lo resuelve el destino (dashboard → useBootstrap).
 */
export default function Welcome() {
    useDocumentTitle('Bienvenido');
    // TikTok Pixel solo en la landing pública (campañas TikTok Ads). El resto
    // del panel no lo carga — ver `hooks/use-tiktok-pixel.ts`.
    useTiktokPixel();
    const token = useToken();

    return (
        <>
            <div className="bg-background flex min-h-svh items-center justify-center p-4 md:p-8">
                <div className="w-full max-w-6xl">
                    <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                        {/* Columna izquierda: logo + hero + acceso */}
                        <div className="flex flex-col gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                            <img src="/images/logo-black-font.svg" alt="flexyflow" className="block h-9 w-auto md:h-10 dark:hidden" />
                            <img src="/images/logo-white-font.svg" alt="flexyflow" className="hidden h-9 w-auto md:h-10 dark:block" />

                            <HeroHeadline
                                eyebrow="Bienvenido"
                                title={
                                    <>
                                        Lo que importa,
                                        <br />a la mano.
                                    </>
                                }
                                description="Tu operación en un solo panel. Inicia sesión con tu cuenta de Gmail para continuar."
                            />

                            <div className="max-w-sm space-y-3">
                                {token ? (
                                    <a
                                        href="/dashboard"
                                        className="bg-primary text-primary-foreground hover:bg-primary/90 flex w-full items-center justify-center rounded-md px-4 py-3 text-sm font-semibold transition-colors"
                                    >
                                        Ir al panel
                                    </a>
                                ) : (
                                    <GoogleAuthButton />
                                )}
                                <p className="text-muted-foreground text-xs">Solo se permite acceso con cuentas de Google.</p>
                            </div>
                        </div>

                        {/* Columna derecha: bloque lime con value props */}
                        <HeroPanel
                            eyebrow="Diseñado para tu empresa"
                            className="md:col-span-5 lg:col-span-5"
                            footer={
                                <p className="text-sm leading-relaxed opacity-80">
                                    Caja, POS, planificador y reportes. Todo en un mismo panel — sin instalaciones, sin licencias por terminal.
                                </p>
                            }
                        >
                            <HeroPanelStats stats={heroStats} />
                        </HeroPanel>
                    </div>
                </div>
            </div>
            <ConsentBanner />
        </>
    );
}
