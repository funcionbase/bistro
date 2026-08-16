/**
 * Aviso de cuota de IndexedDB cerca del límite.
 *
 * Chequea `navigator.storage.estimate()` cada 60s mientras hay pendientes.
 * - >80%: banner amarillo informativo.
 * - >95%: banner rojo crítico — el cashier debería bloquear nuevas órdenes
 *   offline (responsabilidad del componente que recibe la callback).
 */
import { estimateStorageUsage } from '@/lib/offline/db';
import { useEffect, useState } from 'react';

export function StorageQuotaWarning({ onCritical }: { onCritical?: (critical: boolean) => void }) {
    const [ratio, setRatio] = useState<number | null>(null);

    useEffect(() => {
        let cancelled = false;
        const tick = async () => {
            const r = await estimateStorageUsage();
            if (!cancelled) {
                setRatio(r);
                onCritical?.(r !== null && r > 0.95);
            }
        };
        void tick();
        const id = setInterval(() => void tick(), 60000);
        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, [onCritical]);

    if (ratio === null || ratio < 0.8) return null;

    const critical = ratio > 0.95;
    const tone = critical
        ? 'bg-[color:var(--color-status-critical)]/10 border-[color:var(--color-status-critical)]/30 text-[color:var(--color-status-critical)]'
        : 'bg-[color:var(--color-status-warning)]/10 border-[color:var(--color-status-warning)]/30 text-[color:var(--color-status-warning)]';
    const pct = Math.round(ratio * 100);

    return (
        <div className={`sticky top-0 z-40 flex items-center gap-2 border-b px-4 py-2 text-sm ${tone}`}>
            <span className="font-semibold">
                {critical ? '⚠ Almacenamiento del navegador casi lleno' : 'Almacenamiento del navegador al ' + pct + '%'}
            </span>
            <span className="text-xs opacity-80">
                {critical
                    ? 'Las nuevas órdenes offline pueden no guardarse. Conecta a internet para sincronizar y liberar espacio.'
                    : 'Sincroniza pronto para liberar espacio (uso ' + pct + '%).'}
            </span>
        </div>
    );
}

export default StorageQuotaWarning;
