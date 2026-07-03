import { Button } from '@/components/ui/button';
import { RefreshCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Barra fija inferior que aparece cuando el service worker detecta un deploy
 * nuevo con la pestaña visible (`pwa:update-available`, disparado en
 * `spa/main.tsx`). Sin esto, una tableta abierta 24/7 (caja, KDS) nunca
 * rotaba de versión: el evento se emitía y nadie lo escuchaba.
 *
 * No recarga sola — un reload automático puede perder un cobro a medio
 * llenar. El staff decide cuándo con "Actualizar", o pospone con la X (la
 * app queda funcional; en días con varios deploys seguidos el banner sin
 * descarte quedaba fijo tapando el POS). Si llega OTRO deploy, el evento
 * vuelve a disparar y el banner reaparece — descartar no lo silencia para
 * siempre. La recarga silenciosa con pestaña oculta vive en spa/main.tsx.
 */
export function PwaUpdateBanner() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const onUpdate = () => setVisible(true);
        window.addEventListener('pwa:update-available', onUpdate);
        return () => window.removeEventListener('pwa:update-available', onUpdate);
    }, []);

    if (!visible) return null;

    return (
        <div className="border-border bg-card text-card-foreground fixed inset-x-0 bottom-0 z-50 mx-auto flex max-w-md items-center justify-between gap-2 rounded-t-2xl border border-b-0 px-4 py-3 shadow-lg">
            <span className="text-sm">Nueva versión disponible.</span>
            <div className="flex shrink-0 items-center gap-1">
                <Button size="sm" onClick={() => window.location.reload()}>
                    <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Actualizar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setVisible(false)} aria-label="Posponer actualización">
                    <X className="h-4 w-4" />
                </Button>
            </div>
        </div>
    );
}
