import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { CheckCircle2, RefreshCw, Unlock } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Historial de sesiones de caja (turnos). Muestra apertura, cierre, monto
 * inicial, esperado, contado, diferencia y usuarios involucrados. Sirve para
 * contraste contable con el reporte "Cierre de Caja" del mismo período.
 */

interface SessionUser {
    id: string;
    name: string;
}

interface CashSessionRow {
    id: string;
    status: 'open' | 'closed';
    cash_register_id: string | null;
    cash_register_name: string | null;
    opened_at: string | null;
    closed_at: string | null;
    opening_amount: number;
    closing_amount: number | null;
    expected_cash: number | null;
    cash_difference: number | null;
    opening_notes: string | null;
    closing_notes: string | null;
    opened_by: SessionUser | null;
    closed_by: SessionUser | null;
}

interface SessionsResponse {
    data: CashSessionRow[];
    pagination: { current_page: number; last_page: number; per_page: number; total: number };
}

function formatCurrency(value: number | null): string {
    if (value === null) return '—';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-CO', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: 'America/Bogota',
    });
}

/**
 * Mapea la diferencia de caja al token de estado:
 *  - |diff| < 0.01 → safe (cuadre perfecto)
 *  - diff > 0      → warning (sobrante, mejor que faltante pero auditable)
 *  - diff < 0      → critical (faltante de efectivo)
 */
function diffTokenClass(diff: number | null): string {
    if (diff === null) return '';
    if (Math.abs(diff) < 0.01) return 'text-[color:var(--color-status-safe)]';
    if (diff > 0) return 'text-[color:var(--color-status-warning)]';
    return 'text-[color:var(--color-status-critical)]';
}

export default function CashSessionsCard({ branchFilter = 'active' }: { branchFilter?: string }) {
    const token = useToken();
    const [data, setData] = useState<CashSessionRow[]>([]);
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState<SessionsResponse['pagination'] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!token) return;
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ page: String(page), per_page: '15' });
            if (branchFilter !== 'active') params.set('branch', branchFilter);
            const res = await apiFetch(`/api/v1/reports/cash-register/sessions?${params.toString()}`);
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo cargar el historial.');
                return;
            }
            const body = json as SessionsResponse;
            setData(body.data);
            setPagination(body.pagination);
        } catch {
            setError('Error de conexión.');
        } finally {
            setLoading(false);
        }
    }, [page, token, branchFilter]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    return (
        <DashboardPanel
            title="Historial de sesiones de caja"
            rightSlot={
                <Button variant="outline" size="sm" onClick={() => void fetchData()} disabled={loading}>
                    <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                    Actualizar
                </Button>
            }
        >
            <p className="text-muted-foreground mb-3 text-xs">
                Aperturas y cierres de caja. Contrasta el efectivo contado contra el esperado para detectar sobrantes o faltantes.
            </p>

            <div className="space-y-3">
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading && data.length === 0 && (
                    <div className="space-y-2">
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-full" />
                    </div>
                )}

                {!loading && data.length === 0 && !error && <p className="text-muted-foreground text-sm">Sin sesiones registradas todavía.</p>}

                {data.length > 0 && (
                    <div className="bg-card overflow-hidden rounded-lg border">
                        <Table bare>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Caja</TableHead>
                                    <TableHead>Apertura</TableHead>
                                    <TableHead>Cierre</TableHead>
                                    <TableHead className="text-right">Inicial</TableHead>
                                    <TableHead className="text-right">Esperado</TableHead>
                                    <TableHead className="text-right">Contado</TableHead>
                                    <TableHead className="text-right">Diferencia</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.map((s) => {
                                    const diff = s.cash_difference;
                                    return (
                                        <TableRow key={s.id} className="align-top">
                                            <TableCell>
                                                {s.status === 'open' ? (
                                                    <Badge variant="safe">
                                                        <Unlock className="mr-1 h-3 w-3" />
                                                        Abierta
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                                        Cerrada
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-xs">
                                                {s.cash_register_name ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                <div>{formatDateTime(s.opened_at)}</div>
                                                {s.opened_by && <div className="text-muted-foreground">{s.opened_by.name}</div>}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                <div>{formatDateTime(s.closed_at)}</div>
                                                {s.closed_by && <div className="text-muted-foreground">{s.closed_by.name}</div>}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(s.opening_amount)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(s.expected_cash)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(s.closing_amount)}</TableCell>
                                            <TableCell className={`text-right font-semibold tabular-nums ${diffTokenClass(diff)}`}>
                                                {diff === null ? '—' : `${diff >= 0 ? '+' : '−'}${formatCurrency(Math.abs(diff))}`}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {pagination && pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Página {pagination.current_page} de {pagination.last_page} · {pagination.total} sesiones
                        </span>
                        <div className="flex gap-1">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={pagination.current_page <= 1 || loading}
                            >
                                Anterior
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setPage((p) => p + 1)}
                                disabled={pagination.current_page >= pagination.last_page || loading}
                            >
                                Siguiente
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </DashboardPanel>
    );
}
