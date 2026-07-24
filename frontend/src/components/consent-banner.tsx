import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { resolveGa4Id, updateGa4Consent } from '@/hooks/use-ga4';
import { getStoredConsent, setStoredConsent } from '@/lib/consent';
import { useState } from 'react';

/**
 * Banner de consentimiento de cookies (Habeas Data CO): barra inferior no
 * bloqueante con dos categorías — **esenciales** (siempre activas) y
 * **analíticas** (GTM + GA4). Solo aparece en builds con GA4 configurado.
 */

/** Política de privacidad pública (pdn). El banner solo aparece donde hay trackers (pdn). */
const PRIVACY_URL = 'https://flexyflow.co/privacy-policy/';

export function ConsentBanner() {
    const [open, setOpen] = useState(() => Boolean(resolveGa4Id(null)) && getStoredConsent() === null);
    const [customizing, setCustomizing] = useState(false);
    const [analytics, setAnalytics] = useState(true);

    function decide(grantAnalytics: boolean): void {
        setStoredConsent(grantAnalytics);
        updateGa4Consent(grantAnalytics);
        setOpen(false);
    }

    if (!open) {
        return null;
    }

    return (
        <div role="dialog" aria-modal="false" aria-label="Preferencias de cookies" className="fixed inset-x-0 bottom-0 z-50 px-3 pt-3 pb-safe-1 sm:px-4 sm:pt-4">
            <div className="bg-card text-card-foreground border-border mx-auto w-full max-w-3xl rounded-xl border p-4 shadow-lg sm:p-5">
                <div className="space-y-1">
                    <h2 className="text-foreground text-base font-semibold">Cookies</h2>
                    <p className="text-muted-foreground text-sm">
                        Usamos cookies esenciales para que el panel funcione, y opcionales para medición. Tú decides cuáles.
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
                            <Button onClick={() => decide(analytics)}>Guardar</Button>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => setCustomizing(true)}>
                                    Personalizar
                                </Button>
                                <Button variant="outline" onClick={() => decide(false)}>
                                    Solo esenciales
                                </Button>
                                <Button onClick={() => decide(true)}>Aceptar todo</Button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
