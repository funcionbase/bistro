/**
 * Banner sticky de modo offline (#140).
 *
 * Estados visuales:
 *  - Online + 0 pending → no se renderiza nada.
 *  - Online + N pending → banner amarillo "Sincronizando N..." con CTA reintentar.
 *  - Offline + 0 pending → banner gris "Sin conexión".
 *  - Offline + N pending → banner naranja "Modo offline · N en cola".
 *  - Offline >5min O pending >5min antiguos → banner rojo con riesgo de pérdida.
 */
import ConflictReviewDialog from '@/components/offline/conflict-review-dialog';
import { Button } from '@/components/ui/button';
import { exportPendingsAsJson, runSync, subscribeSyncState, type SyncState } from '@/lib/offline/sync-engine';
import { AlertTriangle, RefreshCw, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';

const RISK_THRESHOLD_MS = 5 * 60 * 1000;

function formatRelative(iso: string | null): string {
    if (!iso) return 'nunca';
    const diff = Date.now() - new Date(iso).getTime();
    const min = Math.floor(diff / 60000);
    if (min < 1) return 'hace segundos';
    if (min < 60) return `hace ${min} min`;
    const hr = Math.floor(min / 60);
    return `hace ${hr} h`;
}

async function downloadExport(): Promise<void> {
    const json = await exportPendingsAsJson();
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `bistro-pendientes-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export function OfflineBanner() {
    const [state, setState] = useState<SyncState | null>(null);
    const [offlineSince, setOfflineSince] = useState<number | null>(null);
    const [reviewOpen, setReviewOpen] = useState(false);
    const [, force] = useState(0);

    useEffect(() => {
        const unsub = subscribeSyncState((s) => {
            setState(s);
            if (!s.online && offlineSince === null) {
                setOfflineSince(Date.now());
            } else if (s.online) {
                setOfflineSince(null);
            }
        });
        const tick = setInterval(() => force((n) => n + 1), 30000);
        return () => {
            unsub();
            clearInterval(tick);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (!state) return null;
    const conflicts = state.conflictCount ?? 0;
    if (state.online && state.pendingCount === 0 && conflicts === 0) return null;

    const offlineDuration = offlineSince ? Date.now() - offlineSince : 0;
    const atRisk = !state.online && offlineDuration > RISK_THRESHOLD_MS;

    // Los conflictos (cobro ya pagado en server, dependencia fallida, etc.)
    // requieren atención: tono crítico aunque haya conexión (plan §8).
    const tone =
        atRisk || conflicts > 0
            ? 'bg-[color:var(--color-status-critical)]/10 border-[color:var(--color-status-critical)]/30 text-[color:var(--color-status-critical)]'
            : 'bg-[color:var(--color-status-warning)]/10 border-[color:var(--color-status-warning)]/30 text-[color:var(--color-status-warning)]';

    return (
        <div className={`sticky top-0 z-40 flex items-center justify-between gap-2 border-b px-4 py-2 text-sm ${tone}`}>
            <div className="flex items-center gap-2">
                {atRisk ? <AlertTriangle className="h-4 w-4 shrink-0" /> : <WifiOff className="h-4 w-4 shrink-0" />}
                <div className="flex flex-col leading-tight">
                    <span className="font-semibold">
                        {atRisk
                            ? `⚠ Sin conexión hace más de 5 min — riesgo de pérdida si borras los datos del navegador`
                            : !state.online
                              ? `Modo offline${state.pendingCount > 0 ? ` · ${state.pendingCount} en cola` : ''}`
                              : state.syncing
                                ? `Sincronizando ${state.pendingCount}...`
                                : `${state.pendingCount} pendiente${state.pendingCount === 1 ? '' : 's'} de sincronizar`}
                    </span>
                    <span className="text-xs opacity-80">
                        Última sync: {formatRelative(state.lastSyncAt)}
                        {state.lastError ? ` · ${state.lastError}` : ''}
                        {conflicts > 0 ? ` · ${conflicts} conflicto${conflicts === 1 ? '' : 's'} requiere${conflicts === 1 ? '' : 'n'} tu atención` : ''}
                    </span>
                </div>
            </div>
            <div className="flex items-center gap-2">
                {conflicts > 0 && (
                    <Button size="sm" variant="outline" onClick={() => setReviewOpen(true)}>
                        Revisar ({conflicts})
                    </Button>
                )}
                {state.pendingCount > 0 && (
                    <Button size="sm" variant="outline" onClick={() => void downloadExport()}>
                        Exportar
                    </Button>
                )}
                <Button size="sm" disabled={state.syncing || !state.online} onClick={() => void runSync()}>
                    <RefreshCw className={`mr-1 h-3 w-3 ${state.syncing ? 'animate-spin' : ''}`} />
                    Reintentar
                </Button>
            </div>
            <ConflictReviewDialog open={reviewOpen} onClose={() => setReviewOpen(false)} />
        </div>
    );
}

export default OfflineBanner;
