import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { resolveGa4Id, updateGa4Consent } from '@/hooks/use-ga4';
import { getStoredConsent, setStoredConsent } from '@/lib/consent';
import { useSharedData } from '@/lib/shared-data';
import { useEffect, useState } from 'react';

/**
 * Banner de consentimiento de cookies/analytics (Habeas Data CO), modelado
 * sobre el de flexyflow.co y adaptado al panel: categorías **esenciales**
 * (siempre activas) + **analíticas** (GA4). El panel no usa pixels de
 * marketing/ads, así que esa categoría se omite.
 *
 * Modal no descartable (hay que elegir). Se monta en `SpaSharedDataBridge` y
 * solo aparece si GA4 está configurado y el usuario aún no decidió. La
 * decisión se persiste vía `lib/consent` y se aplica con Consent Mode v2
 * (`updateGa4Consent`).
 */
export function ConsentBanner() {
    const { gaMeasurementId } = useSharedData();
    const bootstrap = useBootstrap();
    const privacyUrl = bootstrap.data?.legalUrls?.privacy;

    const ga4Enabled = Boolean(resolveGa4Id(gaMeasurementId));

    const [open, setOpen] = useState(false);
    const [customizing, setCustomizing] = useState(false);
    const [analytics, setAnalytics] = useState(true);

    useEffect(() => {
        // Solo preguntamos si hay algo que medir (GA4 on) y no hay decisión previa.
        if (ga4Enabled && getStoredConsent() === null) {
            setOpen(true);
        }
    }, [ga4Enabled]);

    /** Persiste la decisión, la aplica a GA4 y cierra el modal. */
    function decide(grantAnalytics: boolean): void {
        setStoredConsent(grantAnalytics);
        updateGa4Consent(grantAnalytics);
        setOpen(false);
    }

    if (!open) {
        return null;
    }

    return (
        <Dialog open={open}>
            <DialogContent
                // Modal obligatorio: ocultamos la X y bloqueamos cerrar sin elegir.
                className="[&>button]:hidden sm:max-w-md"
                onInteractOutside={(event) => event.preventDefault()}
                onEscapeKeyDown={(event) => event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Cookies y privacidad</DialogTitle>
                    <DialogDescription>
                        Usamos cookies esenciales para que el panel funcione, y opcionales para medición (Google Analytics 4). Vos decidís cuáles.
                    </DialogDescription>
                </DialogHeader>

                {customizing && (
                    <div className="space-y-3">
                        <label className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 p-3">
                            <Checkbox checked disabled className="mt-0.5" />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">Esenciales</span>
                                <span className="block text-muted-foreground">Sesión, seguridad y preferencias. Sin estas el panel no funciona.</span>
                            </span>
                        </label>

                        <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                            <Checkbox
                                checked={analytics}
                                onCheckedChange={(value) => setAnalytics(value === true)}
                                className="mt-0.5"
                            />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">Analíticas</span>
                                <span className="block text-muted-foreground">
                                    Google Analytics 4 — métricas de uso anónimas para mejorar el producto.
                                </span>
                            </span>
                        </label>
                    </div>
                )}

                {privacyUrl && (
                    <a
                        href={privacyUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        Política de privacidad →
                    </a>
                )}

                <DialogFooter className="gap-2">
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
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
