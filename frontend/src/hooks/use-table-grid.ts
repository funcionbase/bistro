import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

/** Sesión grupal activa de una mesa (clientes pidiendo desde sus celulares). */
export interface ActiveSession {
    id: string;
    status: string;
    guests_count: number;
    order_id: string | null;
    order_status: string | null;
    items_consumable_count: number;
    pending_approval_count: number;
}

/** Mesa definida por el admin en /company/tables. */
export interface DefinedTable {
    number: string;
    active_session: ActiveSession | null;
}

interface ReleaseConfirm {
    sessionId: string;
    tableNumber: string;
    canRelease: boolean;
    reason?: string;
}

interface UseTableGridArgs {
    token: string | null;
    /** Refresca las órdenes de mesa tras liberar una sesión. */
    refreshOrders: () => Promise<void> | void;
}

interface UseTableGridReturn {
    definedTables: DefinedTable[];
    tablesLoaded: boolean;
    tablesEndpointFailed: boolean;
    releaseConfirm: ReleaseConfirm | null;
    setReleaseConfirm: (next: ReleaseConfirm | null) => void;
    releaseBusy: boolean;
    releaseError: string | null;
    setReleaseError: (next: string | null) => void;
    releaseTable: () => Promise<void>;
}

const POLL_INTERVAL_MS = 30_000;

/**
 * Carga y mantiene actualizada la lista de mesas definidas por el admin
 * (fuente de verdad), más el manejo del flujo de "liberar mesa" para
 * sesiones grupales. La lista se consulta a `GET /api/v1/tables` (filtrada
 * por sede vía branch.access) y se hace polling cada 30s — intervalo
 * canónico del frontend, sincronizado con `useTables`. Si se necesita un
 * refresh inmediato, el botón "Refrescar" del header dispara fetch manual.
 */
export function useTableGrid({ token, refreshOrders }: UseTableGridArgs): UseTableGridReturn {
    const [definedTables, setDefinedTables] = useState<DefinedTable[]>([]);
    const [tablesLoaded, setTablesLoaded] = useState(false);
    const [tablesEndpointFailed, setTablesEndpointFailed] = useState(false);

    const [releaseConfirm, setReleaseConfirm] = useState<ReleaseConfirm | null>(null);
    const [releaseBusy, setReleaseBusy] = useState(false);
    const [releaseError, setReleaseError] = useState<string | null>(null);

    const fetchDefinedTables = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/tables');
            if (!res.ok) throw new Error('no admin tables');
            const json = (await res.json()) as {
                data: Array<{
                    number: string;
                    archived_at: string | null;
                    active_session: ActiveSession | null;
                }>;
            };
            setDefinedTables(
                json.data.filter((t) => !t.archived_at).map((t) => ({ number: t.number, active_session: t.active_session ?? null })),
            );
            setTablesLoaded(true);
        } catch {
            setTablesEndpointFailed(true);
            setTablesLoaded(true);
        }
    }, [token]);

    useEffect(() => {
        let cancelled = false;
        void fetchDefinedTables();
        // Polling para reflejar sesiones que abren/cierran sin tener que
        // recargar la página. 8s es lo mismo que useTables — quedan sincros.
        const id = window.setInterval(() => {
            if (!cancelled) void fetchDefinedTables();
        }, POLL_INTERVAL_MS);
        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [fetchDefinedTables]);

    const releaseTable = useCallback(async () => {
        if (!releaseConfirm) return;
        setReleaseBusy(true);
        setReleaseError(null);
        try {
            const res = await apiFetch(`/api/v1/table-sessions/${releaseConfirm.sessionId}/close-empty`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
            });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string };
                setReleaseError(json.message ?? 'No se pudo liberar la mesa.');
                return;
            }
            setReleaseConfirm(null);
            await fetchDefinedTables();
            await refreshOrders();
        } catch {
            setReleaseError('Error de conexión al liberar la mesa.');
        } finally {
            setReleaseBusy(false);
        }
    }, [releaseConfirm, fetchDefinedTables, refreshOrders]);

    return {
        definedTables,
        tablesLoaded,
        tablesEndpointFailed,
        releaseConfirm,
        setReleaseConfirm,
        releaseBusy,
        releaseError,
        setReleaseError,
        releaseTable,
    };
}
