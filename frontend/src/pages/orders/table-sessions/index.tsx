import { AppLink } from '@/components/app-link';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LivePollingToggle } from '@/components/ui/live-polling-toggle';
import { PageHeader } from '@/components/ui/page-header';
import { TableSessionsListSkeleton } from '@/components/ui/table-sessions-list-skeleton';
import { useLivePolling } from '@/hooks/use-live-polling';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, ChefHat, Clock, RefreshCw, Users } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface TableSessionSummary {
    id: string;
    status: 'open' | 'locked';
    opened_at: string | null;
    expires_at: string | null;
    accepts_new_guests: boolean;
    table: { id: string | null; number: string | null; capacity: number | null };
    guests_count: number;
    order: { id: string; status: string; total: string } | null;
    pending_approval_count: number;
    cancellation_requests_open: number;
}


/**
 * Lista de sesiones de mesa activas para el mesero (#191 Fase 4).
 *
 * Cards por sesión con counters de pending_approval y cancelaciones abiertas
 * para que el mesero priorice. Click en una card lleva al detalle.
 */
export default function TableSessionsIndex() {
    const { activeCompany } = useSharedData();
    const [sessions, setSessions] = useState<TableSessionSummary[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchSessions = useCallback(async () => {
        try {
            const resp = await apiFetch('/api/v1/table-sessions');
            if (!resp.ok) throw new Error('No pudimos cargar las sesiones.');
            const json = (await resp.json()) as { data: TableSessionSummary[] };
            setSessions(json.data);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error cargando sesiones.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void fetchSessions();
    }, [fetchSessions]);

    const polling = useLivePolling({ intervalMs: 30_000, onTick: fetchSessions });

    return (
        <PageShell title="Sesiones de mesa">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading ? (
                    <TableSessionsListSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="Mesa con QR"
                            title="Sesiones de mesa"
                            description={activeCompany?.name ? `Mesas con sesión abierta en ${activeCompany.name}.` : 'Mesas con sesión abierta.'}
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
                                        onClick={() => void fetchSessions()}
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

                        {sessions.length === 0 ? (
                            <EmptyState
                                icon={ChefHat}
                                title="Sin sesiones activas"
                                description="Cuando un comensal escanee el QR de una mesa, aparecerá acá."
                            />
                        ) : (
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                {sessions.map((session) => (
                                    <SessionCard key={session.id} session={session} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </PageShell>
    );
}

function SessionCard({ session }: { session: TableSessionSummary }) {
    const hasPending = session.pending_approval_count > 0;
    const hasCancellations = session.cancellation_requests_open > 0;

    return (
        <AppLink
            href={`/orders/table-sessions/${session.id}`}
            className="border-border bg-card text-card-foreground hover:border-primary group flex flex-col gap-3 rounded-2xl border p-4 transition-colors"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-foreground text-lg font-semibold">Mesa {session.table.number ?? '—'}</p>
                    <p className="text-muted-foreground text-xs">
                        {session.status === 'open' ? 'Abierta' : 'En curso'}
                        {session.opened_at ? ` · ${formatRelative(session.opened_at)}` : ''}
                    </p>
                </div>
                {hasPending && (
                    <Badge className="border-transparent bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]">
                        {session.pending_approval_count} por aprobar
                    </Badge>
                )}
            </div>
            <div className="text-muted-foreground flex items-center gap-3 text-xs">
                <span className="inline-flex items-center gap-1">
                    <Users className="h-3 w-3" />
                    {session.guests_count} comensales
                </span>
                {session.expires_at && (
                    <span className="inline-flex items-center gap-1">
                        <Clock className="h-3 w-3" />
                        vence {new Date(session.expires_at).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}
                    </span>
                )}
            </div>
            <div className="border-border flex items-center justify-between border-t pt-2">
                <span className="text-muted-foreground text-xs">Total estimado</span>
                <span className="text-foreground text-sm font-semibold tabular-nums">
                    {session.order ? formatCurrency(Number.parseFloat(session.order.total)) : '—'}
                </span>
            </div>
            {hasCancellations && (
                <Badge
                    variant="secondary"
                    className="border-transparent bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                >
                    {session.cancellation_requests_open} cancelación pendiente
                </Badge>
            )}
        </AppLink>
    );
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function formatRelative(iso: string): string {
    const date = new Date(iso);
    const diffSec = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (diffSec < 60) return 'hace segundos';
    if (diffSec < 3600) return `hace ${Math.floor(diffSec / 60)} min`;
    if (diffSec < 86400) return `hace ${Math.floor(diffSec / 3600)} h`;
    return date.toLocaleString('es-CO');
}
