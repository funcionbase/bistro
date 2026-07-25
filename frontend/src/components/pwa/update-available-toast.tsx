import { Button } from '@/components/ui/button';
import { RefreshCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Aviso de nueva versión de la PWA.
 *
 * Escucha el CustomEvent `pwa:update-available` que emite `spa/main.tsx` cuando
 * el SW nuevo toma control (`controlling`) con la pantalla visible. Muestra un
 * countdown de 30s y recarga automáticamente al llegar a 0; el usuario puede
 * recargar ya ("Ahora") o descartar ("×", cancela el countdown — el JS viejo
 * sigue en memoria hasta el próximo reload natural).
 *
 * En background la recarga es silenciosa (la decide `main.tsx`); este toast solo
 * aparece con el usuario activo.
 */
export default function UpdateAvailableToast() {
    const [remaining, setRemaining] = useState<number | null>(null);

    useEffect(() => {
        const handler = () => setRemaining(30);
        window.addEventListener('pwa:update-available', handler);
        return () => window.removeEventListener('pwa:update-available', handler);
    }, []);

    useEffect(() => {
        if (remaining === null) return;
        if (remaining <= 0) {
            window.location.reload();
            return;
        }
        const t = window.setTimeout(() => setRemaining((r) => (r !== null ? r - 1 : null)), 1000);
        return () => clearTimeout(t);
    }, [remaining]);

    if (remaining === null) return null;

    return (
        <div className="fixed inset-x-0 top-0 z-50 flex justify-center px-4 pt-3 sm:top-4">
            <div className="bg-card flex items-center gap-3 rounded-full border border-[color:var(--color-status-warning)]/30 px-4 py-2 shadow-lg">
                <RefreshCw className="h-4 w-4 text-[color:var(--color-status-warning)]" />
                <span className="text-sm">Nueva versión · recarga en {remaining}s</span>
                <Button size="sm" onClick={() => window.location.reload()}>
                    Ahora
                </Button>
                <button
                    type="button"
                    onClick={() => setRemaining(null)}
                    aria-label="Cerrar"
                    className="text-muted-foreground hover:text-foreground -m-1 p-1"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}
