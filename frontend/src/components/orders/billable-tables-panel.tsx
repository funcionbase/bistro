import { AppLink } from '@/components/app-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { apiFetch } from '@/lib/api';
import { Receipt, Users } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface BillableOrder {
    id: string;
    status: string;
    total: number;
    ordered_at: string | null;
}

interface BillableSession {
    session_id: string;
    table: { id: string | null; number: string | null };
    guests_count: number;
    guests_preview: string[];
    opened_at: string | null;
    orders_count: number;
    total_due: number;
    orders: BillableOrder[];
}

const POLL_INTERVAL_MS = 30_000;

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        maximumFractionDigits: 0,
    }).format(value);
}

/**
 * Panel para el cajero que lista las mesas con órdenes pendientes de cobro.
 * Cada mesa enlaza a `/caja/table-session/{id}` donde el cajero hace el cobro
 * (todo de una o dividido por comensal).
 *
 * Una sesión puede tener N órdenes (una por tanda aprobada por el mesero) —
 * el total a cobrar suma todas las órdenes operativas (no `completed` /
 * `cancelled` / `refunded`).
 *
 * Filtro de scope: sede activa estricta (heredado del endpoint backend).
 */
export default function BillableTablesPanel() {
    const [sessions, setSessions] = useState<BillableSession[]>([]);
    const [loading, setLoading] = useState(true);
    const mountedRef = useRef(true);

    const fetchData = useCallback(async () => {
        try {
            const res = await apiFetch('/api/v1/table-sessions/billable', {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' },
            });
            if (!res.ok) {
                if (mountedRef.current) setLoading(false);
                return;
            }
            const json = (await res.json()) as { data: BillableSession[] };
            if (mountedRef.current) {
                setSessions(Array.isArray(json.data) ? json.data : []);
                setLoading(false);
            }
        } catch {
            if (mountedRef.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        mountedRef.current = true;
        void fetchData();
        const id = window.setInterval(() => void fetchData(), POLL_INTERVAL_MS);
        return () => {
            mountedRef.current = false;
            window.clearInterval(id);
        };
    }, [fetchData]);

    if (loading) {
        return (
            <div className="border-border bg-card rounded-2xl border p-4">
                <p className="text-muted-foreground text-sm">Cargando mesas…</p>
            </div>
        );
    }

    if (sessions.length === 0) {
        return (
            <Alert variant="default">
                <Receipt className="h-4 w-4" />
                <AlertTitle className="font-semibold">Mesas para cobrar</AlertTitle>
                <AlertDescription className="text-muted-foreground">No hay mesas con cuentas pendientes en este momento.</AlertDescription>
            </Alert>
        );
    }

    const totalAcrossTables = sessions.reduce((acc, s) => acc + s.total_due, 0);

    return (
        <section className="space-y-3">
            <header className="flex items-baseline justify-between gap-3">
                <h2 className="text-foreground text-base font-semibold tracking-tight">
                    Mesas para cobrar
                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                        · {sessions.length} {sessions.length === 1 ? 'mesa' : 'mesas'}
                    </span>
                </h2>
                <p className="text-foreground text-sm font-semibold tabular-nums">{formatCurrency(totalAcrossTables)}</p>
            </header>

            <ul className="space-y-2">
                {sessions.map((s) => (
                    <li key={s.session_id}>
                        <AppLink
                            href={`/caja/table-sessions/${s.session_id}`}
                            className="border-border bg-card hover:bg-muted/40 focus:ring-ring flex items-start gap-3 rounded-xl border p-3 transition-colors focus:ring-2 focus:outline-none"
                        >
                            <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-xl font-semibold tabular-nums">
                                {s.table.number ?? '?'}
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-foreground text-sm font-semibold">
                                    Mesa {s.table.number ?? '?'}
                                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                                        · {s.orders_count} {s.orders_count === 1 ? 'orden' : 'órdenes'}
                                    </span>
                                </p>
                                <p className="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs">
                                    <Users className="size-3" />
                                    {s.guests_count > 0
                                        ? s.guests_preview.slice(0, 2).join(', ') + (s.guests_count > 2 ? ` y ${s.guests_count - 2} más` : '')
                                        : 'Sin comensales registrados'}
                                </p>
                            </div>
                            <div className="text-right">
                                <p className="text-foreground text-sm font-semibold tabular-nums">{formatCurrency(s.total_due)}</p>
                                <Button size="sm" variant="outline" className="mt-1 h-7 text-[11px]">
                                    Cobrar
                                </Button>
                            </div>
                        </AppLink>
                    </li>
                ))}
            </ul>
        </section>
    );
}
