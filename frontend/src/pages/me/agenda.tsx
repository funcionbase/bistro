import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodNavigator } from '@/components/ui/period-navigator';
import { WeekAgendaSkeleton } from '@/components/ui/week-agenda-skeleton';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { AlertCircle, CalendarDays } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Shift = {
    id: string;
    branch_id: string;
    starts_at: string;
    ends_at: string;
    status: string;
    cancellation_reason: string | null;
};


function startOfWeek(d: Date): Date {
    const day = d.getDay() || 7;
    const r = new Date(d);
    r.setHours(0, 0, 0, 0);
    r.setDate(r.getDate() - (day - 1));
    return r;
}

function addDays(d: Date, n: number): Date {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}

function fmtDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function fmtWeekRange(start: Date, end: Date): string {
    const sameMonth = start.getMonth() === end.getMonth();
    const startFmt = start.toLocaleDateString('es-CO', { day: 'numeric', month: sameMonth ? undefined : 'short' });
    const endFmt = end.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
    return `${startFmt} – ${endFmt}`;
}

export default function MyAgenda() {
    useToken();
    const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()));
    const [shifts, setShifts] = useState<Shift[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
    const weekEnd = useMemo(() => addDays(weekStart, 6), [weekStart]);

    useEffect(() => {
        (async () => {
            setLoading(true);
            setError(null);
            const from = fmtDate(weekStart);
            const to = fmtDate(weekEnd);
            try {
                const res = await apiFetch(`/api/v1/me/shifts?from=${from}&to=${to}`);
                if (!res.ok) {
                    setError('No se pudo cargar tu agenda.');
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
        })();
    }, [weekStart, weekEnd]);

    const byDay = useMemo(() => {
        const map = new Map<string, Shift[]>();
        for (const s of shifts) {
            const day = s.starts_at.slice(0, 10);
            if (!map.has(day)) map.set(day, []);
            map.get(day)!.push(s);
        }
        return map;
    }, [shifts]);

    const headerActions = (
        <PeriodNavigator
            label={fmtWeekRange(weekStart, weekEnd)}
            onPrev={() => setWeekStart(addDays(weekStart, -7))}
            onNext={() => setWeekStart(addDays(weekStart, 7))}
            onToday={() => setWeekStart(startOfWeek(new Date()))}
            disabled={loading}
        />
    );

    return (
        <PageShell title="Mi agenda">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="MI AGENDA"
                    title="Mi agenda"
                    description="Tus turnos asignados de la semana. Toca un turno para ver detalle."
                    actions={headerActions}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <WeekAgendaSkeleton />
                ) : shifts.length === 0 ? (
                    <EditorialEmpty
                        eyebrow="Sin turnos"
                        icon={<CalendarDays className="h-10 w-10" />}
                        title="No tienes turnos asignados esta semana"
                        description="Cuando un administrador te programe turnos, aparecerán aquí. Mientras tanto, puedes revisar otras semanas con las flechas."
                    />
                ) : (
                    <DashboardPanel title="Semana actual" icon={CalendarDays}>
                        <div className="grid gap-3 md:grid-cols-7">
                            {days.map((d) => {
                                const key = fmtDate(d);
                                const dayShifts = byDay.get(key) ?? [];
                                const isToday = key === fmtDate(new Date());
                                return (
                                    <div
                                        key={key}
                                        className={`border-border bg-card space-y-2 rounded-lg border p-3 ${isToday ? 'ring-primary/40 ring-2' : ''}`}
                                    >
                                        <div className="text-muted-foreground mb-1 text-[11px] font-semibold tracking-wide uppercase">
                                            {d.toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric' })}
                                        </div>
                                        {dayShifts.length === 0 ? (
                                            <div className="text-muted-foreground/60 text-xs italic">Sin turnos</div>
                                        ) : (
                                            <div className="space-y-2">
                                                {dayShifts.map((s) => {
                                                    const isCancelled = s.status === 'cancelled';
                                                    return (
                                                        <div
                                                            key={s.id}
                                                            className={`border-border rounded-md border p-2 text-xs ${
                                                                isCancelled ? 'opacity-60' : ''
                                                            }`}
                                                        >
                                                            <div className="font-mono font-semibold tabular-nums">
                                                                {s.starts_at.slice(11, 16)} – {s.ends_at.slice(11, 16)}
                                                            </div>
                                                            {isCancelled && (
                                                                <Badge variant="destructive" className="mt-1 text-[10px]">
                                                                    Cancelado ({s.cancellation_reason ?? '—'})
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </DashboardPanel>
                )}
            </div>
        </PageShell>
    );
}
