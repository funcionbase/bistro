import { Button } from '@/components/ui/button';
import { RefreshCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Toast no bloqueante que aparece cuando el Service Worker detecta un bundle
 * nuevo (`pwa:update-available`, emitido en `app.tsx`). Recargar fuerza al
 * navegador a tomar el SW en estado `waiting`, descartando la ventana actual.
 *
 * Solo se muestra cuando la app corre como PWA instalada (display-mode
 * standalone o navigator.standalone en iOS). En navegador normal el SW
 * tambien puede entrar en estado `waiting` si quedo cacheado de visitas
 * previas, pero ahi un simple F5/Ctrl+R toma la nueva version sin
 * intervencion — mostrar el banner es ruido y ademas colisiona visualmente
 * con el prompt de Instalar PWA (que vive en el bottom-right). Patron
 * espejado a install-pwa-prompt.tsx que tambien gatea con isStandalone().
 */
function isStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    return (
        window.matchMedia?.('(display-mode: standalone)').matches || (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

export default function UpdateAvailableToast() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!isStandalone()) return;
        const handler = () => setVisible(true);
        window.addEventListener('pwa:update-available', handler);
        return () => window.removeEventListener('pwa:update-available', handler);
    }, []);

    if (!visible) return null;

    return (
        <div className="fixed inset-x-0 top-0 z-50 flex justify-center px-4 pt-3 sm:top-4">
            <div className="bg-card flex items-center gap-3 rounded-full border border-[color:var(--color-status-warning)]/30 px-4 py-2 shadow-lg">
                <RefreshCw className="h-4 w-4 text-[color:var(--color-status-warning)]" />
                <span className="text-sm">Nueva versión disponible</span>
                <Button size="sm" onClick={() => window.location.reload()}>
                    Recargar
                </Button>
                <button
                    type="button"
                    onClick={() => setVisible(false)}
                    aria-label="Cerrar"
                    className="text-muted-foreground hover:text-foreground -m-1 p-1"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}
