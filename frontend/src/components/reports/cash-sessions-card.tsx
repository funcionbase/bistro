import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DetailRow } from '@/components/ui/detail-row';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CASH_EXPENSE_CATEGORIES, CASH_INCOME_CATEGORIES } from '@/hooks/use-cash-register';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { CheckCircle2, ChevronDown, ChevronRight, RefreshCw, Unlock } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { formatCurrency as formatCurrencyCOP } from '@/lib/formatters';
import { formatDateTimeShort } from '@/lib/datetime';

/**
 * Historial de sesiones de caja (turnos) — el informe de cierre de caja.
 * Muestra apertura, cierre, monto inicial, esperado, contado, diferencia y
 * usuarios. Con `detailed`, agrupa por día y despliega el arqueo del turno
 * (ventas por método, entradas y salidas de efectivo).
 */

interface SessionUser {
    id: string;
    name: string;
}

interface MethodTotals {
    gross: number;
    refunds: number;
    net: number;
    tips: number;
    count: number;
}

interface MovementBucket {
    total: number;
    count: number;
    by_method: Record<string, number>;
    by_category: Record<string, number>;
}

interface SessionBreakdown {
    by_method: Record<string, MethodTotals>;
    expenses: MovementBucket;
    incomes: MovementBucket;
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
    breakdown?: SessionBreakdown;
}

interface SessionsResponse {
    data: CashSessionRow[];
    pagination: { current_page: number; last_page: number; per_page: number; total: number };
}

const METHOD_LABELS: Record<string, string> = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', refund: 'Devoluciones' };

function formatCurrency(value: number | null): string {
    if (value === null) return '—';
    return formatCurrencyCOP(value);
}


function formatTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Bogota' });
}

/** Día (TZ Bogota) de apertura — clave para agrupar los cierres por jornada. */
function dayLabel(iso: string | null): string {
    if (!iso) return 'Sin fecha';
    return new Date(iso).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Bogota' });
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

function formatDiff(diff: number | null): string {
    if (diff === null) return '—';
    return `${diff >= 0 ? '+' : '−'}${formatCurrency(Math.abs(diff))}`;
}

function StatusBadge({ status }: { status: 'open' | 'closed' }) {
    return status === 'open' ? (
        <Badge variant="safe">
            <Unlock className="mr-1 h-3 w-3" />
            Abierta
        </Badge>
    ) : (
        <Badge variant="secondary">
            <CheckCircle2 className="mr-1 h-3 w-3" />
            Cerrada
        </Badge>
    );
}

/** Lista compacta categoría → monto (entradas o salidas de un turno). */
function CategoryList({ byCategory, labels }: { byCategory: Record<string, number>; labels: Record<string, string> }) {
    const entries = Object.entries(byCategory).filter(([, v]) => v > 0);
    if (entries.length === 0) return <span className="text-muted-foreground">—</span>;
    return (
        <ul className="space-y-0.5">
            {entries.map(([cat, total]) => (
                <li key={cat} className="flex justify-between gap-4">
                    <span className="text-muted-foreground">{labels[cat] ?? cat}</span>
                    <span className="tabular-nums">{formatCurrency(total)}</span>
                </li>
            ))}
        </ul>
    );
}

/** Fila de turno con arqueo expandible (informe de cierre por turno). */
function DetailedSessionRow({ session, turno }: { session: CashSessionRow; turno: number }) {
    const [open, setOpen] = useState(false);
    const b = session.breakdown;

    return (
        <div className="bg-card overflow-hidden rounded-lg border">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="hover:bg-muted/50 flex w-full items-center gap-3 px-3 py-2.5 text-left"
            >
                {open ? <ChevronDown className="h-4 w-4 shrink-0" /> : <ChevronRight className="h-4 w-4 shrink-0" />}
                <span className="text-sm font-medium">Turno {turno}</span>
                <StatusBadge status={session.status} />
                <span className="text-muted-foreground text-xs">
                    {session.cash_register_name ?? 'Caja'} · {formatTime(session.opened_at)}–{formatTime(session.closed_at)}
                    {session.opened_by && <> · {session.opened_by.name}</>}
                </span>
                <span className={`ml-auto text-sm font-semibold tabular-nums ${diffTokenClass(session.cash_difference)}`}>
                    {formatDiff(session.cash_difference)}
                </span>
            </button>

            {open && (
                <div className="grid gap-4 border-t px-3 py-3 text-xs sm:grid-cols-3">
                    {/* Conciliación / arqueo */}
                    <div>
                        <p className="text-muted-foreground mb-1 font-medium uppercase tracking-wide">Conciliación</p>
                        <DetailRow label="Saldo inicial" value={formatCurrency(session.opening_amount)} />
                        <DetailRow label="Efectivo esperado" value={formatCurrency(session.expected_cash)} />
                        <DetailRow label="Dinero contado" value={formatCurrency(session.closing_amount)} />
                        <DetailRow
                            label="Diferencia"
                            value={<span className={`font-semibold ${diffTokenClass(session.cash_difference)}`}>{formatDiff(session.cash_difference)}</span>}
                        />
                    </div>

                    {/* Ventas por método */}
                    <div>
                        <p className="text-muted-foreground mb-1 font-medium uppercase tracking-wide">Ventas por método</p>
                        {b ? (
                            <ul className="space-y-0.5">
                                {['cash', 'card', 'transfer'].map((m) => {
                                    const row = b.by_method[m];
                                    if (!row || row.net === 0) return null;
                                    return (
                                        <li key={m} className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">{METHOD_LABELS[m]}</span>
                                            <span className="tabular-nums">{formatCurrency(row.net)}</span>
                                        </li>
                                    );
                                })}
                                {['cash', 'card', 'transfer'].every((m) => (b.by_method[m]?.net ?? 0) === 0) && (
                                    <li className="text-muted-foreground">Sin ventas</li>
                                )}
                            </ul>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </div>

                    {/* Movimientos de efectivo */}
                    <div className="space-y-2">
                        <div>
                            <p className="text-muted-foreground mb-1 font-medium uppercase tracking-wide">
                                Entradas de efectivo {b && b.incomes.total > 0 && <span className="normal-case">· {formatCurrency(b.incomes.total)}</span>}
                            </p>
                            <CategoryList byCategory={b?.incomes.by_category ?? {}} labels={CASH_INCOME_CATEGORIES} />
                        </div>
                        <div>
                            <p className="text-muted-foreground mb-1 font-medium uppercase tracking-wide">
                                Salidas / gastos {b && b.expenses.total > 0 && <span className="normal-case">· {formatCurrency(b.expenses.total)}</span>}
                            </p>
                            <CategoryList byCategory={b?.expenses.by_category ?? {}} labels={CASH_EXPENSE_CATEGORIES} />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function CashSessionsCard({
    branchFilter = 'active',
    dateFrom,
    dateTo,
    detailed = false,
}: {
    branchFilter?: string;
    dateFrom?: string;
    dateTo?: string;
    detailed?: boolean;
}) {
    const token = useToken();
    const [data, setData] = useState<CashSessionRow[]>([]);
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState<SessionsResponse['pagination'] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Al cambiar el rango de fechas volvemos a la primera página.
    useEffect(() => {
        setPage(1);
    }, [dateFrom, dateTo]);

    const fetchData = useCallback(async () => {
        if (!token) return;
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ page: String(page), per_page: '25' });
            if (branchFilter !== 'active') params.set('branch', branchFilter);
            if (dateFrom) params.set('date_from', dateFrom);
            if (dateTo) params.set('date_to', dateTo);
            if (detailed) params.set('detailed', '1');
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
    }, [page, token, branchFilter, dateFrom, dateTo, detailed]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    // Agrupa por día de apertura (TZ Bogota) preservando el orden desc del backend.
    const dayGroups: { day: string; rows: CashSessionRow[] }[] = [];
    if (detailed) {
        const index = new Map<string, CashSessionRow[]>();
        for (const s of data) {
            const key = dayLabel(s.opened_at);
            if (!index.has(key)) {
                index.set(key, []);
                dayGroups.push({ day: key, rows: index.get(key)! });
            }
            index.get(key)!.push(s);
        }
    }

    return (
        <DashboardPanel
            title="Cierre de caja por turno"
            rightSlot={
                <Button variant="outline" size="sm" onClick={() => void fetchData()} disabled={loading}>
                    <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                    Actualizar
                </Button>
            }
        >
            <p className="text-muted-foreground mb-3 text-xs">
                Concilia el efectivo contado contra el esperado para detectar sobrantes o faltantes antes de abrir el siguiente día. Cada cierre se
                agrupa por su día de apertura.
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

                {!loading && data.length === 0 && !error && <p className="text-muted-foreground text-sm">Sin sesiones registradas en el período.</p>}

                {/* Vista detallada (informe): agrupada por día con arqueo expandible. */}
                {detailed &&
                    data.length > 0 &&
                    dayGroups.map(({ day, rows }) => (
                        <div key={day} className="space-y-2">
                            <h3 className="text-sm font-semibold capitalize">{day}</h3>
                            {rows.map((s, i) => (
                                <DetailedSessionRow key={s.id} session={s} turno={rows.length - i} />
                            ))}
                        </div>
                    ))}

                {/* Vista simple (panel de reportes): tabla plana. */}
                {!detailed && data.length > 0 && (
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
                                {data.map((s) => (
                                    <TableRow key={s.id} className="align-top">
                                        <TableCell>
                                            <StatusBadge status={s.status} />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">{s.cash_register_name ?? '—'}</TableCell>
                                        <TableCell className="text-xs">
                                            <div>{formatDateTimeShort(s.opened_at)}</div>
                                            {s.opened_by && <div className="text-muted-foreground">{s.opened_by.name}</div>}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            <div>{formatDateTimeShort(s.closed_at)}</div>
                                            {s.closed_by && <div className="text-muted-foreground">{s.closed_by.name}</div>}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCurrency(s.opening_amount)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCurrency(s.expected_cash)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatCurrency(s.closing_amount)}</TableCell>
                                        <TableCell className={`text-right font-semibold tabular-nums ${diffTokenClass(s.cash_difference)}`}>
                                            {formatDiff(s.cash_difference)}
                                        </TableCell>
                                    </TableRow>
                                ))}
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
