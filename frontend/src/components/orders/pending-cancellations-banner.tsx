import { AppLink } from '@/components/app-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { Ban } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface PendingCancellationRequest {
    id: string;
    item_name: string;
    quantity: number;
    reason: string | null;
    guest_name: string;
    requested_at: string | null;
}

interface PendingCancellationSession {
    session_id: string;
    table: { id: string | null; number: string | null };
    oldest_requested_at: string | null;
    requests_count: number;
    requests: PendingCancellationRequest[];
}

const POLL_INTERVAL_MS = 30_000;

/**
 * Alerta de cancelaciones pedidas por clientes desde el QR sobre items ya
 * aprobados — el mesero debe resolver (aprobar/negar). Patrón visual idéntico
 * al banner de pedidos pendientes para que se apilen consistentes.
 *
 * Filtro de scope: `active_company_nit` + `active_branch_id` (estricto, sin
 * vista consolidada — la alerta operativa siempre vive en la sede actual).
 *
 * Polling: cada 10s mientras haya `activeBranch`. Si el JWT no tiene sede o
 * el endpoint devuelve `NO_ACTIVE_BRANCH`/`BRANCH_*`, el polling se DETIENE
 * para no saturar logs/red con 422 cada 10s. La UX de "sede inválida / sin
 * sedes" la maneja `MissingBranchBanner` a nivel de `app-layout.tsx` para
 * no duplicar el mensaje en cada banner. Cambiar de sede vía Inertia
 * remonta el componente y reanuda el polling.
 */
export default function PendingCancellationsBanner() {
    const { activeBranch, activeCompany } = useSharedData();
    const hasBranch = Boolean(activeBranch?.id);
    // #193: detener polling si la empresa está suspended (los endpoints
    // operativos retornan 403 company_payment_blocked sin parar).
    const isSuspended = activeCompany?.status === 'suspended';

    const [sessions, setSessions] = useState<PendingCancellationSession[]>([]);
    const [branchStale, setBranchStale] = useState(false);
    const mountedRef = useRef(true);

    const fetchData = useCallback(async () => {
        try {
            // `cache: 'no-store'` evita que el ServiceWorker sirva respuestas
            // viejas — la alerta operativa debe leer siempre la verdad actual.
            const res = await apiFetch('/api/v1/orders/pending-cancellations', {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' },
            });
            if (!res.ok) {
                if (mountedRef.current && (res.status === 401 || res.status === 403)) {
                    setSessions([]);
                }
                // 404 / 422 con código BRANCH_* → sede stale, detener polling.
                if (mountedRef.current && (res.status === 404 || res.status === 422)) {
                    try {
                        const body = await res.clone().json();
                        const code = String(body?.code ?? '');
                        if (code.startsWith('BRANCH_') || code === 'NO_ACTIVE_BRANCH') {
                            setBranchStale(true);
                            setSessions([]);
                        }
                    } catch {
                        // Respuesta no-JSON — ignorar.
                    }
                }
                return;
            }
            const json = (await res.json()) as { data: PendingCancellationSession[] };
            if (mountedRef.current) {
                setSessions(Array.isArray(json.data) ? json.data : []);
                setBranchStale(false);
            }
        } catch {
            // Red intermitente — el siguiente tick reintenta.
        }
    }, []);

    useEffect(() => {
        mountedRef.current = true;

        if (!hasBranch || branchStale || isSuspended) {
            return () => {
                mountedRef.current = false;
            };
        }

        void fetchData();
        const id = window.setInterval(() => void fetchData(), POLL_INTERVAL_MS);
        return () => {
            mountedRef.current = false;
            window.clearInterval(id);
        };
    }, [fetchData, hasBranch, branchStale, isSuspended]);

    if (sessions.length === 0) {
        return null;
    }

    return (
        <Alert variant="warning" role="alert" aria-live="polite">
            <Ban className="h-4 w-4" />
            <AlertTitle className="font-semibold">
                {sessions.length === 1 ? 'Cancelación por resolver' : `${sessions.length} mesas con cancelaciones`}
            </AlertTitle>
            <AlertDescription>
                <ul className="list-disc space-y-0.5 pl-5 text-sm">
                    {sessions.map((s) => (
                        <li key={s.session_id}>
                            <AppLink href={`/orders/table-sessions/${s.session_id}`} className="font-medium underline underline-offset-2">
                                Mesa {s.table.number ?? '?'}
                            </AppLink>
                            <span className="opacity-80">
                                {' · '}
                                {s.requests_count} {s.requests_count === 1 ? 'solicitud' : 'solicitudes'}
                                {' · '}
                                {formatRelative(s.oldest_requested_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

function formatRelative(iso: string | null): string {
    if (!iso) return 'hace un momento';
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return 'hace un momento';
    const diffSec = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (diffSec < 60) return `hace ${diffSec}s`;
    const mins = Math.floor(diffSec / 60);
    if (mins < 60) return `hace ${mins} min`;
    const hrs = Math.floor(mins / 60);
    return `hace ${hrs}h`;
}
