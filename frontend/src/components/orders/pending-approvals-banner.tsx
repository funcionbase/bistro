import { AppLink } from '@/components/app-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface PendingApprovalItem {
    id: string;
    name: string;
    quantity: number;
    notes: string | null;
    guest_name: string;
    submitted_at: string | null;
}

interface PendingApprovalSession {
    session_id: string;
    order_id: string | null;
    table: { id: string | null; number: string | null };
    oldest_submitted_at: string | null;
    items_count: number;
    items: PendingApprovalItem[];
}

const POLL_INTERVAL_MS = 10_000;

/**
 * Alerta de pedidos pendientes de aprobación.
 *
 * Sigue el patrón visual del banner de inventory low-stock en /dashboard:
 * un `<Alert variant="warning">` con título contado + lista de bullets +
 * link de acción. Como es una alerta estándar del DS, se apila debajo de
 * otras alertas del mismo nivel y acumula sin esfuerzo cuando llegan más
 * mesas simultáneamente.
 *
 * Filtro de scope:
 *  - El endpoint `/api/v1/orders/pending-approvals` ya filtra por
 *    `active_company_nit` + `active_branch_id` (middleware EnsureCompanyAccess
 *    + EnsureBranchAccess). La alerta muestra solo la sede actual.
 *
 * Polling: cada 10s mientras haya `activeBranch`. Si el JWT no tiene sede o
 * el endpoint devuelve `NO_ACTIVE_BRANCH`/`BRANCH_*`, el polling se DETIENE
 * y este banner se queda silencioso — la UX de "sede inválida / sin sedes"
 * la maneja `MissingBranchBanner` (global en `app-layout.tsx`) para que el
 * mensaje no se duplique en cada banner operativo. Reanuda al cambiar de
 * empresa/sede (Inertia remonta el árbol).
 */
export default function PendingApprovalsBanner() {
    const { activeBranch, activeCompany } = useSharedData();
    const hasBranch = Boolean(activeBranch?.id);
    // #193: cuando la empresa está suspended, EnsureCompanyNotBlocked devuelve
    // 403 en cualquier endpoint operativo. Cortar el polling acá evita 403
    // recurrentes que inflarían audit_logs y bloquearían el UI con spinners.
    const isSuspended = activeCompany?.status === 'suspended';

    const [sessions, setSessions] = useState<PendingApprovalSession[]>([]);
    const [branchStale, setBranchStale] = useState(false);
    const mountedRef = useRef(true);

    const fetchData = useCallback(async () => {
        try {
            // `cache: 'no-store'` evita que el ServiceWorker o el http cache
            // intermedio sirvan respuestas viejas — esta alerta debe leer
            // siempre la verdad operativa actual, no el snapshot de hace 1h.
            const res = await apiFetch('/api/v1/orders/pending-approvals', {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' },
            });
            if (!res.ok) {
                if (mountedRef.current && (res.status === 401 || res.status === 403)) {
                    setSessions([]);
                }
                // 404 / 422 con código BRANCH_* significa que la sede del JWT
                // ya no existe (típico tras un reseed o reasignación). En vez
                // de fallar silente, mostramos un fallback accionable.
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
            const json = (await res.json()) as { data: PendingApprovalSession[] };
            if (mountedRef.current) {
                const list = Array.isArray(json.data) ? json.data : [];
                setSessions(list);
                setBranchStale(false);
            }
        } catch {
            // Red intermitente — el próximo tick reintenta.
        }
    }, []);

    useEffect(() => {
        mountedRef.current = true;

        // Sin sede activa, sede stale o empresa suspended: no polling. Se vuelve
        // a montar el efecto si activeBranch o activeCompany.status cambian.
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
        // El caso `!hasBranch || branchStale` lo cubre `MissingBranchBanner`
        // a nivel de layout para no duplicar el mensaje por cada banner
        // operativo. Acá simplemente nos quedamos silenciosos.
        return null;
    }

    return (
        <Alert variant="warning" role="alert" aria-live="polite">
            <Bell className="h-4 w-4" />
            <AlertTitle className="font-semibold">
                {sessions.length === 1 ? 'Pedido esperando aprobación' : `${sessions.length} mesas esperan aprobación`}
            </AlertTitle>
            <AlertDescription>
                <ul className="space-y-1.5 text-sm">
                    {sessions.map((s) => (
                        <li key={s.session_id} className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span className="font-medium">
                                    Mesa {s.table.number ?? 'Sin mesa asignada'}
                                </span>
                                <span className="opacity-80">
                                    {' · '}
                                    {s.items_count} {s.items_count === 1 ? 'plato' : 'platos'}
                                    {' · '}
                                    {formatRelative(s.oldest_submitted_at)}
                                </span>
                            </span>
                            <Button variant="outline" size="sm" asChild>
                                <AppLink href={s.order_id ? `/orders/${s.order_id}` : `/orders/table-sessions/${s.session_id}`}>
                                    Ver detalle
                                </AppLink>
                            </Button>
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
