import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { resolveGa4Id, updateGa4Consent } from '@/hooks/use-ga4';
import { resolveTiktokPixelId } from '@/hooks/use-tiktok-pixel';
import { getStoredConsent, setStoredConsent } from '@/lib/consent';
import { useState } from 'react';

/**
 * Banner de consentimiento de cookies (Habeas Data CO), modelado sobre el de
 * flexyflow.co: barra inferior no bloqueante con tres categorías —
 * **esenciales** (siempre activas), **analíticas** (GTM + GA4) y **marketing**
 * (TikTok Pixel). Mientras no haya decisión los trackers opcionales NO cargan.
 *
 * Desacoplado del bootstrap a propósito: se monta tanto en la landing pública
 * (`Welcome`, sin sesión) como en el área autenticada (`SpaAppLayout`). Por eso
 * la detección de "¿hay algo que medir?" usa el fallback build-time (`VITE_*`)
 * vía `resolveGa4Id`/`resolveTiktokPixelId` en vez de props compartidas Inertia,
 * y el link de privacidad es una constante (la URL pública pdn).
 *
 * La decisión se persiste vía `lib/consent` y se aplica:
 * - analíticas → Consent Mode v2 (`updateGa4Consent`).
 * - marketing  → `setStoredConsent` notifica a `useTiktokPixel` (carga el pixel).
 */

/** Política de privacidad pública (pdn). El banner solo aparece donde hay trackers (pdn). */
const PRIVACY_URL = 'https://flexyflow.co/privacy-policy/';

/** `noMarketing`: oculta la categoría TikTok Pixel. Usar en páginas autenticadas
 *  donde el pixel no carga — evita mostrar la opción de marketing a usuarios logueados. */
export function ConsentBanner({ noMarketing = false }: { noMarketing?: boolean }) {
    const [open, setOpen] = useState(
        () => Boolean(resolveGa4Id(null) || (!noMarketing && resolveTiktokPixelId())) && getStoredConsent() === null,
    );
    const [customizing, setCustomizing] = useState(false);
    const [analytics, setAnalytics] = useState(true);
    const [marketing, setMarketing] = useState(true);

    function decide(grantAnalytics: boolean, grantMarketing: boolean): void {
        // En contexto autenticado (noMarketing) preservamos la decisión de marketing
        // existente para no pisar lo que el usuario eligió en la landing.
        const effectiveMarketing = noMarketing ? (getStoredConsent()?.marketing ?? false) : grantMarketing;
        setStoredConsent(grantAnalytics, effectiveMarketing);
        updateGa4Consent(grantAnalytics);
        setOpen(false);
    }

    if (!open) {
        return null;
    }

    return (
        <div role="dialog" aria-modal="false" aria-label="Preferencias de cookies" className="fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4">
            <div className="bg-card text-card-foreground border-border mx-auto w-full max-w-3xl rounded-xl border p-4 shadow-lg sm:p-5">
                <div className="space-y-1">
                    <h2 className="text-foreground text-base font-semibold">Cookies</h2>
                    <p className="text-muted-foreground text-sm">
                        Usamos cookies esenciales para que el panel funcione, y opcionales para medición y publicidad. Vos decidís cuáles.
                    </p>
                </div>

                {customizing && (
                    <div className="mt-4 space-y-3">
                        <label className="border-border bg-muted/30 flex items-start gap-3 rounded-lg border p-3">
                            <Checkbox checked disabled className="mt-0.5" />
                            <span className="text-sm">
                                <span className="text-foreground font-medium">Esenciales</span>
                                <span className="text-muted-foreground block">Sesión, seguridad y preferencias. Sin estas el panel no funciona.</span>
                            </span>
                        </label>

                        <label className="border-border flex items-start gap-3 rounded-lg border p-3">
                            <Checkbox checked={analytics} onCheckedChange={(value) => setAnalytics(value === true)} className="mt-0.5" />
                            <span className="text-sm">
                                <span className="text-foreground font-medium">Analíticas</span>
                                <span className="text-muted-foreground block">
                                    Google Tag Manager + Google Analytics 4. Nos dice qué páginas funcionan; ayuda a mejorar el producto.
                                </span>
                            </span>
                        </label>

                        {!noMarketing && (
                            <label className="border-border flex items-start gap-3 rounded-lg border p-3">
                                <Checkbox checked={marketing} onCheckedChange={(value) => setMarketing(value === true)} className="mt-0.5" />
                                <span className="text-sm">
                                    <span className="text-foreground font-medium">Marketing y publicidad</span>
                                    <span className="text-muted-foreground block">
                                        TikTok Pixel. Mide nuestras campañas y conversiones para mostrarte anuncios relevantes.
                                    </span>
                                </span>
                            </label>
                        )}
                    </div>
                )}

                <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <a
                        href={PRIVACY_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary text-sm underline-offset-4 hover:underline"
                    >
                        Política de privacidad →
                    </a>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        {customizing ? (
                            <Button onClick={() => decide(analytics, marketing)}>Guardar</Button>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => setCustomizing(true)}>
                                    Personalizar
                                </Button>
                                <Button variant="outline" onClick={() => decide(false, false)}>
                                    Solo esenciales
                                </Button>
                                <Button onClick={() => decide(true, true)}>Aceptar todo</Button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
