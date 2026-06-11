import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DesktopOnlyHint } from '@/components/ui/desktop-only-hint';
import { EmptyState } from '@/components/ui/empty-state';
import { KdsSkeleton } from '@/components/ui/kds-skeleton';
import { KdsTicketCard } from '@/components/ui/kds-ticket-card';
import { LivePollingToggle } from '@/components/ui/live-polling-toggle';
import { PageHeader } from '@/components/ui/page-header';
import { useLivePolling } from '@/hooks/use-live-polling';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, ChefHat, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface KdsTicket {
    id: string;
    order_id: string;
    name: string;
    quantity: number;
    notes: string | null;
    status: 'approved' | 'in_kitchen' | 'ready';
    approved_at: string | null;
    in_kitchen_at: string | null;
    ready_at: string | null;
    guest: { id: string; display_name: string } | null;
    table: { id: string | null; number: string | null } | null;
    order_notes: Array<{ id: string; scope: 'group' | 'kitchen_alert'; body: string }>;
}


const statusFilters = [
    { key: 'all', label: 'Todos' },
    { key: 'approved', label: 'Por entrar' },
    { key: 'in_kitchen', label: 'En cocina' },
    { key: 'ready', label: 'Listos' },
] as const;

type StatusFilter = (typeof statusFilters)[number]['key'];

/**
 * Kitchen Display System — pantalla de cocina (#191 Fase 5).
 *
 * Grid responsive de KdsTicketCard ordenado por antigüedad. Filtros por
 * estado y polling agresivo (2s) para reflejar nuevas aprobaciones del
 * mesero en tiempo casi real.
 */
export default function KdsPage() {
    const { activeCompany } = useSharedData();
    const [tickets, setTickets] = useState<KdsTicket[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [filter, setFilter] = useState<StatusFilter>('all');

    const fetchTickets = useCallback(async () => {
        try {
            const resp = await apiFetch('/api/v1/kds/tickets');
            if (!resp.ok) throw new Error('No pudimos cargar los tickets.');
            const json = (await resp.json()) as { data: KdsTicket[] };
            setTickets(json.data);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error cargando tickets.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void fetchTickets();
    }, [fetchTickets]);

    // Refresca al volver el foco / hacerse visible la pestaña. Así el KDS y el
    // tablero (/orders/board) reflejan los cambios del otro al alternar entre
    // pestañas, sin esperar al tick de polling.
    useEffect(() => {
        const refetchOnReturn = () => {
            if (document.visibilityState === 'visible') void fetchTickets();
        };
        window.addEventListener('focus', refetchOnReturn);
        document.addEventListener('visibilitychange', refetchOnReturn);
        return () => {
            window.removeEventListener('focus', refetchOnReturn);
            document.removeEventListener('visibilitychange', refetchOnReturn);
        };
    }, [fetchTickets]);

    const polling = useLivePolling({ intervalMs: 30_000, onTick: fetchTickets });

    const mutate = async (path: string) => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/${path}`, { method: 'PATCH' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Acción rechazada.');
            }
            await fetchTickets();
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Acción fallida.');
        } finally {
            setBusy(false);
        }
    };

    const filtered = useMemo(() => {
        if (filter === 'all') return tickets;
        return tickets.filter((t) => t.status === filter);
    }, [tickets, filter]);

    const counts = useMemo(() => {
        return {
            approved: tickets.filter((t) => t.status === 'approved').length,
            in_kitchen: tickets.filter((t) => t.status === 'in_kitchen').length,
            ready: tickets.filter((t) => t.status === 'ready').length,
        };
    }, [tickets]);

    /**
     * Split: tickets en preparación (approved/in_kitchen) arriba con tamaño
     * normal y FIFO (más viejo primero); los `ready` esperando entrega del
     * mesero abajo en modo compact. La cocina ataca primero lo que lleva
     * más rato esperando y los listos no compiten visualmente.
     *
     * El filtro del usuario se aplica antes — si filtra `ready`, sólo verá
     * la sección compact con esos. Si filtra `approved` o `in_kitchen`,
     * sólo la sección activa.
     */
    const { activeTickets, readyTickets } = useMemo(() => {
        const sortByApprovedAsc = (a: KdsTicket, b: KdsTicket): number => {
            const aT = a.approved_at ?? '';
            const bT = b.approved_at ?? '';
            return aT.localeCompare(bT);
        };
        const active = filtered.filter((t) => t.status === 'approved' || t.status === 'in_kitchen').sort(sortByApprovedAsc);
        const ready = filtered.filter((t) => t.status === 'ready').sort(sortByApprovedAsc);
        return { activeTickets: active, readyTickets: ready };
    }, [filtered]);

    return (
        <PageShell title="Cocina">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading && tickets.length === 0 ? (
                    <KdsSkeleton />
                ) : (
                    <>
                        <DesktopOnlyHint
                            title="Tablero de cocina"
                            description="Pensado para un monitor fijo en cocina. En el celular igual puedes revisar tickets, pero los CTAs grandes se sienten mejor en pantalla grande."
                        />
                        <PageHeader
                            eyebrow="Cocina"
                            title="Tablero de cocina"
                            description={activeCompany?.name ? `Tickets activos en ${activeCompany?.name}.` : 'Tickets activos.'}
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
                                    <LivePollingToggle
                                        enabled={polling.enabled}
                                        onToggle={polling.toggle}
                                        activatedAt={polling.activatedAt}
                                        autoOffMs={polling.autoOffMs}
                                        intervalMs={polling.intervalMs}
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => void fetchTickets()}
                                        disabled={loading}
                                        className="w-full sm:w-auto"
                                    >
                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                                    </Button>
                                </div>
                            }
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {actionError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{actionError}</AlertDescription>
                            </Alert>
                        )}

                        <div className="flex flex-wrap items-center gap-2">
                            {statusFilters.map((s) => (
                                <Button
                                    key={s.key}
                                    type="button"
                                    size="sm"
                                    variant={filter === s.key ? 'default' : 'outline'}
                                    onClick={() => setFilter(s.key)}
                                >
                                    {s.label}
                                    {s.key !== 'all' && (
                                        <Badge variant="secondary" className="ml-1.5">
                                            {counts[s.key as 'approved' | 'in_kitchen' | 'ready']}
                                        </Badge>
                                    )}
                                </Button>
                            ))}
                        </div>

                        {filtered.length === 0 ? (
                            <EmptyState
                                icon={ChefHat}
                                title={
                                    filter === 'all'
                                        ? 'Sin tickets activos'
                                        : `Sin tickets en "${statusFilters.find((s) => s.key === filter)?.label}"`
                                }
                                description="Cuando el mesero apruebe una tanda, aparecerá acá."
                            />
                        ) : (
                            <div className="flex flex-col gap-5">
                                {activeTickets.length > 0 && (
                                    <section aria-label="En preparación" className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                        {activeTickets.map((t) => (
                                            <KdsTicketCard
                                                key={t.id}
                                                ticket={t}
                                                onMarkInKitchen={() => void mutate(`kds/tickets/${t.id}/mark-in-kitchen`)}
                                                onMarkReady={() => void mutate(`kds/tickets/${t.id}/mark-ready`)}
                                                disabled={busy}
                                            />
                                        ))}
                                    </section>
                                )}

                                {readyTickets.length > 0 && (
                                    <section aria-label="Listos para entregar" className="flex flex-col gap-2">
                                        <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                            <span>Listos · para entregar ({readyTickets.length})</span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                                            {readyTickets.map((t) => (
                                                <KdsTicketCard
                                                    key={t.id}
                                                    ticket={t}
                                                    onMarkInKitchen={() => void mutate(`kds/tickets/${t.id}/mark-in-kitchen`)}
                                                    onMarkReady={() => void mutate(`kds/tickets/${t.id}/mark-ready`)}
                                                    onMarkServed={() => void mutate(`kds/tickets/${t.id}/mark-served`)}
                                                    disabled={busy}
                                                    compact
                                                />
                                            ))}
                                        </div>
                                    </section>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </PageShell>
    );
}
