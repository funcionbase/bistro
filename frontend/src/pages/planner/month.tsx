import { AppLink } from '@/components/app-link';
import { PageShell } from '@/components/page-shell';
import { PlannerViewTabs } from '@/components/planner/planner-view-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DesktopOnlyHint } from '@/components/ui/desktop-only-hint';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { MonthCalendarGrid } from '@/components/ui/month-calendar-grid';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodNavigator } from '@/components/ui/period-navigator';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { route } from '@/lib/route-compat';

import { AlertCircle, CalendarRange } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';

type Shift = {
    id: string;
    starts_at: string;
    ends_at: string;
    status: string;
};


function fmtDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function diffHours(start: string, end: string): number {
    return (new Date(end).getTime() - new Date(start).getTime()) / 3600000;
}

function startOfMonth(d: Date): Date {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}

function startOfWeek(d: Date): Date {
    const day = d.getDay() || 7;
    const r = new Date(d);
    r.setHours(0, 0, 0, 0);
    r.setDate(r.getDate() - (day - 1));
    return r;
}

function capitalize(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

export default function PlannerMonth() {
    useToken();
    const navigate = useNavigate();
    const [anchor, setAnchor] = useState(() => startOfMonth(new Date()));
    const [shifts, setShifts] = useState<Shift[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const monthEnd = useMemo(() => new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0), [anchor]);

    const load = async () => {
        setLoading(true);
        setError(null);
        const from = fmtDate(startOfWeek(anchor));
        const to = fmtDate(monthEnd);
        try {
            const res = await apiFetch(`/api/v1/shifts?from=${from}&to=${to}`);
            if (!res.ok) {
                setError('No pudimos cargar los turnos. Reintenta en unos segundos.');
                setLoading(false);
                return;
            }
            const json = await res.json();
            setShifts(json.data ?? []);
        } catch {
            setError('Error de conexión. Verifica tu red e intenta de nuevo.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [anchor]);

    const totalsByDay = useMemo(() => {
        const map = new Map<string, { scheduled: number; cancelled: number }>();
        for (const s of shifts) {
            const day = s.starts_at.slice(0, 10);
            const hours = diffHours(s.starts_at, s.ends_at);
            const cur = map.get(day) ?? { scheduled: 0, cancelled: 0 };
            if (s.status === 'scheduled') cur.scheduled += hours;
            else cur.cancelled += hours;
            map.set(day, cur);
        }
        return map;
    }, [shifts]);

    const monthStats = useMemo(() => {
        let scheduled = 0;
        let cancelled = 0;
        for (const s of shifts) {
            const day = s.starts_at.slice(0, 10);
            const d = new Date(day);
            if (d.getMonth() !== anchor.getMonth() || d.getFullYear() !== anchor.getFullYear()) continue;
            const hours = diffHours(s.starts_at, s.ends_at);
            if (s.status === 'scheduled') scheduled += hours;
            else cancelled += hours;
        }
        return { scheduled, cancelled };
    }, [shifts, anchor]);

    const monthHasShifts = useMemo(() => {
        for (const s of shifts) {
            const d = new Date(s.starts_at.slice(0, 10));
            if (d.getMonth() === anchor.getMonth() && d.getFullYear() === anchor.getFullYear()) return true;
        }
        return false;
    }, [shifts, anchor]);

    const goWeek = (d: Date) => {
        const monday = startOfWeek(d);
        navigate(`/planner?week=${fmtDate(monday)}`);
    };

    const monthLabel = capitalize(anchor.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' }));

    const headerActions = (
        <PeriodNavigator
            label={monthLabel}
            onPrev={() => setAnchor(new Date(anchor.getFullYear(), anchor.getMonth() - 1, 1))}
            onNext={() => setAnchor(new Date(anchor.getFullYear(), anchor.getMonth() + 1, 1))}
            onToday={() => setAnchor(startOfMonth(new Date()))}
            disabled={loading}
        />
    );

    const legend = (
        <div className="text-muted-foreground flex flex-wrap items-center gap-3 text-[11px]">
            <span className="inline-flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-[color:var(--color-status-safe)]" />
                Programadas
            </span>
            <span className="inline-flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-[color:var(--color-status-critical)]" />
                Canceladas
            </span>
        </div>
    );

    return (
        <PageShell title="Calendario mensual">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <DesktopOnlyHint
                    title="Calendario mensual"
                    description="El grid de 7 columnas se aprieta en el celular. Para revisar el mes completo, usa tablet o desktop."
                />
                <PageHeader
                    eyebrow="CALENDARIO"
                    title="Calendario mensual"
                    description="Vista de cubrimiento del mes. Toca un día para abrir la semana en el planificador."
                    actions={headerActions}
                />

                <PlannerViewTabs active="month" />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <StatTile value={`${monthStats.scheduled.toFixed(1)}h`} label="Horas programadas" tone="default" size="lg" />
                    <StatTile
                        value={`${monthStats.cancelled.toFixed(1)}h`}
                        label="Horas canceladas"
                        tone={monthStats.cancelled > 0 ? 'warning' : 'default'}
                        size="lg"
                    />
                    <StatTile
                        value={totalsByDay.size}
                        label="Días con turnos"
                        tone={totalsByDay.size > 0 ? 'primary' : 'default'}
                        size="lg"
                        className="col-span-2 md:col-span-1"
                    />
                </div>

                {loading ? (
                    <DashboardPanel title="Cubrimiento del mes" icon={CalendarRange}>
                        <div className="space-y-2" aria-busy="true">
                            <div className="grid grid-cols-7 gap-1">
                                {Array.from({ length: 7 }).map((_, i) => (
                                    <Skeleton key={i} className="h-4 w-full" />
                                ))}
                            </div>
                            <div className="grid grid-cols-7 gap-1">
                                {Array.from({ length: 35 }).map((_, i) => (
                                    <Skeleton key={i} className="aspect-square w-full rounded-md" />
                                ))}
                            </div>
                        </div>
                    </DashboardPanel>
                ) : !monthHasShifts ? (
                    <EditorialEmpty
                        eyebrow="Empezar"
                        icon={<CalendarRange className="h-10 w-10" />}
                        title="No hay turnos programados este mes"
                        description="Cuando asignes turnos en el planificador semanal, aparecerán aquí con sus horas y cubrimiento por día."
                        action={
                            <Button variant="default" size="lg" asChild>
                                <AppLink href={route('planner.week')}>Ir al planificador semanal</AppLink>
                            </Button>
                        }
                    />
                ) : (
                    <DashboardPanel title="Cubrimiento del mes" icon={CalendarRange} rightSlot={legend}>
                        <MonthCalendarGrid
                            anchor={anchor}
                            onDayClick={goWeek}
                            renderCell={(_day, { dayKey }) => {
                                const totals = totalsByDay.get(dayKey);
                                if (!totals) return null;
                                return (
                                    <div className="mt-auto w-full space-y-0.5">
                                        {totals.scheduled > 0 && (
                                            <div className="inline-flex items-center rounded-full bg-[color:var(--color-status-safe)]/15 px-1.5 py-0.5 text-[10px] font-medium text-[color:var(--color-status-safe)] tabular-nums">
                                                {totals.scheduled.toFixed(1)}h
                                            </div>
                                        )}
                                        {totals.cancelled > 0 && (
                                            <div className="inline-flex items-center rounded-full bg-[color:var(--color-status-critical)]/15 px-1.5 py-0.5 text-[10px] font-medium text-[color:var(--color-status-critical)] tabular-nums">
                                                {totals.cancelled.toFixed(1)}h cancel.
                                            </div>
                                        )}
                                    </div>
                                );
                            }}
                        />
                    </DashboardPanel>
                )}
            </div>
        </PageShell>
    );
}
