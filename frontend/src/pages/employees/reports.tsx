import { AppLink } from '@/components/app-link';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { ReportsTableSkeleton } from '@/components/ui/reports-table-skeleton';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { todayInBogota } from '@/lib/datetime';

import { AlertCircle, ArrowLeft, Download, FileBarChart, FileText, Info, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { formatCurrency } from '@/lib/formatters';

type Row = {
    employee_id: string;
    full_name: string;
    doc_number: string;
    position: string;
    primary_branch: string;
    scheduled_hours: number;
    executed_hours: number;
    cancelled_hours: number;
    cancellations: { sick: number; vinculation_state: number; other: number };
    estimated_cost: number;
};

type Totals = {
    scheduled_hours: number;
    executed_hours: number;
    cancelled_hours: number;
    estimated_cost: number;
};

type Period = 'daily' | 'weekly' | 'monthly' | 'custom';

const PERIOD_OPTIONS: ReadonlyArray<{ value: Period; label: string }> = [
    { value: 'daily', label: 'Hoy' },
    { value: 'weekly', label: 'Semana' },
    { value: 'monthly', label: 'Mes' },
    { value: 'custom', label: 'Personalizado' },
];


function isoDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function formatHours(value: number): string {
    return value.toFixed(2);
}

function formatCop(value: number): string {
    return formatCurrency(value);
}

function computeRange(period: Period, customFrom: string, customTo: string): { from: string; to: string } {
    const todayIso = todayInBogota();
    const today = new Date(`${todayIso}T00:00:00`);
    switch (period) {
        case 'daily':
            return { from: todayIso, to: todayIso };
        case 'weekly': {
            const day = today.getDay() || 7;
            const monday = new Date(today);
            monday.setDate(today.getDate() - (day - 1));
            return { from: isoDate(monday), to: todayIso };
        }
        case 'monthly': {
            const first = new Date(today.getFullYear(), today.getMonth(), 1);
            return { from: isoDate(first), to: todayIso };
        }
        case 'custom':
            return { from: customFrom, to: customTo };
    }
}

export default function EmployeesReports() {
    useToken();

    const todayStr = todayInBogota();
    const today = new Date(`${todayStr}T00:00:00`);
    const monthAgo = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate());

    const [period, setPeriod] = useState<Period>('monthly');
    const [customFrom, setCustomFrom] = useState(isoDate(monthAgo));
    const [customTo, setCustomTo] = useState(todayStr);
    const [rows, setRows] = useState<Row[]>([]);
    const [totals, setTotals] = useState<Totals | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [appliedRange, setAppliedRange] = useState<{ from: string; to: string } | null>(null);

    const range = useMemo(() => computeRange(period, customFrom, customTo), [period, customFrom, customTo]);

    const load = useCallback(async (from: string, to: string) => {
        if (!from || !to) return;
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch(`/api/v1/reports/workforce?from=${from}&to=${to}`);
            if (!res.ok) {
                setError('No pudimos cargar el informe. Reintenta en unos segundos.');
                setLoading(false);
                return;
            }
            const json = await res.json();
            setRows(json.data ?? []);
            setTotals(json.totals ?? null);
            setAppliedRange({ from, to });
        } catch {
            setError('Error de conexión. Verifica tu red e intenta de nuevo.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (period === 'custom') return;
        load(range.from, range.to);
    }, [period, range.from, range.to, load]);

    const handleApplyCustom = () => load(range.from, range.to);
    const handleRefresh = () => load(range.from, range.to);

    const exportUrl = (ext: 'csv' | 'pdf') => `/api/v1/reports/workforce.${ext}?from=${range.from}&to=${range.to}`;

    const exportsDisabled = !appliedRange || loading;

    const headerActions = (
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <Button variant="outline" size="sm" asChild className="flex-1 sm:flex-initial">
                <AppLink href="/employees">
                    <ArrowLeft className="h-4 w-4" />
                    Volver al listado
                </AppLink>
            </Button>
            <Button variant="outline" size="sm" asChild disabled={exportsDisabled} className="flex-1 sm:flex-initial">
                <a
                    href={exportUrl('csv')}
                    target="_blank"
                    rel="noreferrer"
                    aria-disabled={exportsDisabled}
                    onClick={(e) => exportsDisabled && e.preventDefault()}
                >
                    <Download className="h-4 w-4" />
                    CSV
                </a>
            </Button>
            <Button variant="outline" size="sm" asChild disabled={exportsDisabled} className="flex-1 sm:flex-initial">
                <a
                    href={exportUrl('pdf')}
                    target="_blank"
                    rel="noreferrer"
                    aria-disabled={exportsDisabled}
                    onClick={(e) => exportsDisabled && e.preventDefault()}
                >
                    <FileText className="h-4 w-4" />
                    PDF
                </a>
            </Button>
        </div>
    );

    const description = appliedRange ? `Período aplicado: ${appliedRange.from} — ${appliedRange.to}` : undefined;

    return (
        <PageShell title="Informes de colaboradores">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="COLABORADORES"
                    title="Informes de colaboradores"
                    description={description ?? 'Horas asignadas, ejecutadas y costo estimado del equipo en el período seleccionado.'}
                    variant="editorial"
                    actions={headerActions}
                />

                <div className="bg-card flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <PeriodTabs
                        options={PERIOD_OPTIONS}
                        value={period}
                        onChange={setPeriod}
                        customValue="custom"
                        dateFrom={customFrom}
                        dateTo={customTo}
                        onDateFromChange={setCustomFrom}
                        onDateToChange={setCustomTo}
                        onApplyCustom={handleApplyCustom}
                        applyDisabled={!customFrom || !customTo || loading}
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        onClick={handleRefresh}
                        disabled={loading}
                        aria-label="Actualizar"
                        className="self-end sm:ml-auto"
                    >
                        <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                    </Button>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading && !totals ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-24 w-full rounded-lg" />
                        ))}
                    </div>
                ) : totals && rows.length > 0 ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile value={formatHours(totals.scheduled_hours)} label="Horas asignadas" size="lg" />
                        <StatTile
                            value={formatHours(totals.executed_hours)}
                            label="Horas ejecutadas"
                            tone={totals.executed_hours > 0 ? 'safe' : 'default'}
                            size="lg"
                        />
                        <StatTile
                            value={formatHours(totals.cancelled_hours)}
                            label="Horas canceladas"
                            tone={totals.cancelled_hours > 0 ? 'warning' : 'default'}
                            size="lg"
                        />
                        <StatTile value={formatCop(totals.estimated_cost)} label="Costo estimado (COP)" tone="primary" size="lg" />
                    </div>
                ) : null}

                <DashboardPanel title="Detalle por colaborador" icon={FileBarChart} contentClassName="p-0 pt-0">
                    {loading && rows.length === 0 ? (
                        <ReportsTableSkeleton rows={6} />
                    ) : rows.length === 0 ? (
                        <div className="p-6">
                            <EditorialEmpty
                                eyebrow="Sin datos"
                                icon={<FileBarChart className="h-10 w-10" />}
                                title="No hay turnos en este período"
                                description="Ajusta el rango de fechas o verifica que existan turnos registrados para los colaboradores activos."
                            />
                        </div>
                    ) : (
                        <>
                            {/* Mobile: card-stack */}
                            <ul className="space-y-3 px-4 pb-4 sm:hidden">
                                {rows.map((r) => (
                                    <li key={r.employee_id} className="border-border bg-card space-y-3 rounded-2xl border p-4">
                                        <div className="space-y-1">
                                            <p className="text-foreground text-sm font-medium">{r.full_name}</p>
                                            <p className="text-muted-foreground font-mono text-xs tabular-nums">{r.doc_number}</p>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 text-xs">
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Cargo</p>
                                                <p className="text-foreground mt-0.5">{r.position || '—'}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Sede</p>
                                                <p className="text-foreground mt-0.5">{r.primary_branch || '—'}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Asignadas</p>
                                                <p className="font-mono font-medium tabular-nums">{formatHours(r.scheduled_hours)}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Ejecutadas</p>
                                                <p className="font-mono font-medium tabular-nums">{formatHours(r.executed_hours)}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Canceladas</p>
                                                <p
                                                    className={`font-mono font-medium tabular-nums ${r.cancelled_hours > 0 ? 'text-[color:var(--color-status-warning)]' : ''}`}
                                                >
                                                    {formatHours(r.cancelled_hours)}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] tracking-wide uppercase">Costo</p>
                                                <p className="font-mono font-semibold tabular-nums">{formatCop(r.estimated_cost)}</p>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                                {totals && (
                                    <li className="bg-muted/50 border-border space-y-2 rounded-2xl border p-4">
                                        <p className="text-foreground text-[11px] font-semibold tracking-[0.1em] uppercase">Totales</p>
                                        <div className="grid grid-cols-2 gap-3 text-xs">
                                            <div>
                                                <p className="text-muted-foreground text-[10px] uppercase">Asignadas</p>
                                                <p className="font-mono font-semibold tabular-nums">{formatHours(totals.scheduled_hours)}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] uppercase">Ejecutadas</p>
                                                <p className="font-mono font-semibold tabular-nums">{formatHours(totals.executed_hours)}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] uppercase">Canceladas</p>
                                                <p className="font-mono font-semibold tabular-nums">{formatHours(totals.cancelled_hours)}</p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-[10px] uppercase">Costo</p>
                                                <p className="font-mono font-semibold tabular-nums">{formatCop(totals.estimated_cost)}</p>
                                            </div>
                                        </div>
                                    </li>
                                )}
                            </ul>

                            {/* Desktop: tabla densa */}
                            <div className="hidden overflow-x-auto sm:block">
                                <Table bare>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Colaborador</TableHead>
                                            <TableHead>Cargo</TableHead>
                                            <TableHead>Sede</TableHead>
                                            <TableHead className="text-right">Asignadas</TableHead>
                                            <TableHead className="text-right">Ejecutadas</TableHead>
                                            <TableHead className="text-right">Canceladas</TableHead>
                                            <TableHead className="text-right">Costo (COP)</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((r) => (
                                            <TableRow key={r.employee_id}>
                                                <TableCell>
                                                    <div className="font-medium">{r.full_name}</div>
                                                    <div className="text-muted-foreground font-mono text-xs tabular-nums">{r.doc_number}</div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">{r.position || '—'}</TableCell>
                                                <TableCell className="text-muted-foreground">{r.primary_branch || '—'}</TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">{formatHours(r.scheduled_hours)}</TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">{formatHours(r.executed_hours)}</TableCell>
                                                <TableCell
                                                    className={`text-right font-mono tabular-nums ${
                                                        r.cancelled_hours > 0 ? 'text-[color:var(--color-status-warning)]' : ''
                                                    }`}
                                                    title={
                                                        r.cancelled_hours > 0
                                                            ? `Enfermedad: ${formatHours(r.cancellations.sick)}h · Vinculación: ${formatHours(r.cancellations.vinculation_state)}h · Otras: ${formatHours(r.cancellations.other)}h`
                                                            : undefined
                                                    }
                                                >
                                                    {formatHours(r.cancelled_hours)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono font-medium tabular-nums">
                                                    {formatCop(r.estimated_cost)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {totals && (
                                            <TableRow className="bg-muted/50 font-semibold">
                                                <TableCell colSpan={3} className="text-[11px] tracking-[0.1em] uppercase">
                                                    Totales
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatHours(totals.scheduled_hours)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatHours(totals.executed_hours)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatHours(totals.cancelled_hours)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatCop(totals.estimated_cost)}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </>
                    )}
                </DashboardPanel>

                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertDescription>
                        Este costo es una <strong>estimación operativa</strong>: tomamos la tarifa de cada empleado y la multiplicamos por las horas que
                        trabajó en el período (ajustada según su tipo de pago: hora, día o mes). Sirve como guía rápida, no como liquidación.{' '}
                        <strong>No</strong> incluye prestaciones, parafiscales, seguridad social ni retención en la fuente — para liquidar nómina
                        formal usa una herramienta de nómina dedicada.
                    </AlertDescription>
                </Alert>
            </div>
        </PageShell>
    );
}
