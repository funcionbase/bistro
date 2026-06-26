import { KdsStationTicketCard, type KdsSlaState, type KdsStationTicketGroup } from '@/components/kds/kds-station-ticket-card';
import { Separator } from '@/components/ui/separator';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useAutoPolling } from '@/hooks/use-auto-polling';
import KdsStandaloneLayout from '@/layouts/kds-standalone-layout';
import { apiFetch } from '@/lib/api';
import { AlertCircle, ChefHat, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface StationProp {
    id: string;
    slug: string;
    name: string;
    color: string;
    sla_warn_minutes: number;
    sla_alert_minutes: number;
}

interface ApiResponse {
    station: StationProp;
    data: KdsStationTicketGroup[];
}

const PLACEHOLDER_STATION = (slug: string): StationProp => ({
    id: '',
    slug,
    name: 'Cocina',
    color: '#888888',
    sla_warn_minutes: 0,
    sla_alert_minutes: 0,
});

/**
 * #115 — Pantalla del KDS por estación (standalone, kiosk-mode).
 *
 * Layout sin sidebar (kds-standalone-layout). Polling cada 2s con
 * `useAutoPolling`. Items agrupados por orden con SLA visual server-side.
 *
 * Grid responsive:
 *  - mobile portrait (<640px): 1 col, header colapsado.
 *  - tablet portrait (sm, ≥640px): 2 cols.
 *  - tablet landscape (lg, ≥1024px): 3 cols — target original 1280×800.
 *  - desktop (2xl, ≥1536px): 4 cols.
 *
 * Sin DesktopOnlyHint — el KDS es responsive total (DOR del #115).
 */
export default function KdsStationPage() {
    const stationSlug = window.location.pathname.split('/').pop() ?? '';
    const deviceToken = new URLSearchParams(window.location.search).get('device') ?? '';
    const [tickets, setTickets] = useState<KdsStationTicketGroup[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [stationInfo, setStationInfo] = useState<StationProp>(() => PLACEHOLDER_STATION(stationSlug));

    const deviceQ = deviceToken ? `?device=${encodeURIComponent(deviceToken)}` : '';

    const fetchTickets = useCallback(async () => {
        try {
            const resp = await apiFetch(`/api/v1/kds/${encodeURIComponent(stationSlug)}/tickets${deviceQ}`);
            if (!resp.ok) throw new Error('No pudimos cargar los tickets.');
            const json = (await resp.json()) as ApiResponse;
            setTickets(json.data ?? []);
            if (json.station) {
                setStationInfo(json.station);
            }
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error cargando tickets.');
        } finally {
            setLoading(false);
        }
    }, [stationSlug, deviceQ]);

    useEffect(() => {
        void fetchTickets();
    }, [fetchTickets]);

    useAutoPolling({ intervalMs: 30_000, onTick: fetchTickets });

    const mutate = async (itemId: string, action: 'mark-in-kitchen' | 'mark-ready') => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/kds/${encodeURIComponent(stationSlug)}/items/${itemId}/${action}${deviceQ}`, {
                method: 'PATCH',
            });
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

    const counts = useMemo<Record<KdsSlaState, number>>(() => {
        const acc: Record<KdsSlaState, number> = { green: 0, amber: 0, red: 0 };
        for (const t of tickets) acc[t.sla_state]++;
        return acc;
    }, [tickets]);

    /**
     * Split: tickets en preparación (algún item operable: approved/in_kitchen)
     * van arriba grandes; los que ya cocinó pero el mesero no recogió (todos
     * ready/served) van abajo en modo compact.
     *
     * Dentro de cada grupo, FIFO por `oldest_approved_at` ascendente — el
     * más viejo arriba, garantiza que cocina ataque primero lo que lleva
     * más rato esperando.
     */
    const { inProgress, awaitingPickup } = useMemo(() => {
        const inProg: KdsStationTicketGroup[] = [];
        const wait: KdsStationTicketGroup[] = [];
        for (const t of tickets) {
            const hasOperable = t.items.some(
                (i) => i.is_own_station && (i.status === 'approved' || i.status === 'in_kitchen'),
            );
            if (hasOperable) {
                inProg.push(t);
            } else {
                wait.push(t);
            }
        }
        const byOldestFirst = (a: KdsStationTicketGroup, b: KdsStationTicketGroup): number => {
            const aT = a.oldest_approved_at ?? '';
            const bT = b.oldest_approved_at ?? '';
            return aT.localeCompare(bT);
        };
        inProg.sort(byOldestFirst);
        wait.sort(byOldestFirst);
        return { inProgress: inProg, awaitingPickup: wait };
    }, [tickets]);

    return (
        <KdsStandaloneLayout title={`Cocina · ${stationInfo.name}`}>
            <header className="border-border bg-card sticky top-0 z-10 border-b">
                <div className="flex flex-wrap items-center gap-3 px-3 py-3 sm:px-6 sm:py-4">
                    <span
                        aria-hidden
                        className="inline-block h-3 w-3 shrink-0 rounded-full sm:h-4 sm:w-4"
                        style={{ backgroundColor: stationInfo.color }}
                    />
                    <div className="min-w-0 flex-1">
                        <p className="text-foreground truncate text-xl leading-tight font-bold sm:text-2xl">{stationInfo.name}</p>
                        <p className="text-muted-foreground text-xs sm:text-sm">
                            SLA: {stationInfo.sla_warn_minutes}/{stationInfo.sla_alert_minutes} min · {tickets.length}{' '}
                            {tickets.length === 1 ? 'orden' : 'órdenes'}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1.5">
                        <Badge variant="outline" className="bg-critical/15 text-critical border-critical/40">
                            {counts.red}
                        </Badge>
                        <Badge variant="outline" className="bg-warning/15 text-warning border-warning/40">
                            {counts.amber}
                        </Badge>
                        <Badge variant="outline" className="bg-safe/15 text-safe border-safe/40">
                            {counts.green}
                        </Badge>
                        <Button type="button" variant="secondary" size="sm" onClick={() => void fetchTickets()} disabled={loading}>
                            <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                        </Button>
                    </div>
                </div>
            </header>

            <main className="flex-1 p-3 sm:p-4">
                {error && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {actionError && (
                    <Alert variant="destructive" className="mb-4">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{actionError}</AlertDescription>
                    </Alert>
                )}

                {tickets.length === 0 ? (
                    <EmptyState icon={ChefHat} title="Sin tickets activos" description="Cuando el mesero apruebe una tanda, aparecerá acá." />
                ) : (
                    <div className="flex flex-col gap-5">
                        {inProgress.length > 0 && (
                            <section aria-label="En preparación" className="flex flex-col gap-2">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3 2xl:grid-cols-4">
                                    {inProgress.map((ticket) => (
                                        <KdsStationTicketCard
                                            key={ticket.order_id}
                                            ticket={ticket}
                                            onMarkInKitchen={(itemId) => void mutate(itemId, 'mark-in-kitchen')}
                                            onMarkReady={(itemId) => void mutate(itemId, 'mark-ready')}
                                            busy={busy}
                                        />
                                    ))}
                                </div>
                            </section>
                        )}

                        {awaitingPickup.length > 0 && (
                            <section aria-label="Listos esperando entrega del mesero" className="flex flex-col gap-2">
                                {inProgress.length > 0 && <Separator className="my-1" />}
                                <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                    <span>Listos · esperando mesero ({awaitingPickup.length})</span>
                                </div>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                                    {awaitingPickup.map((ticket) => (
                                        <KdsStationTicketCard
                                            key={ticket.order_id}
                                            ticket={ticket}
                                            onMarkInKitchen={(itemId) => void mutate(itemId, 'mark-in-kitchen')}
                                            onMarkReady={(itemId) => void mutate(itemId, 'mark-ready')}
                                            busy={busy}
                                            compact
                                        />
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>
                )}
            </main>
        </KdsStandaloneLayout>
    );
}
