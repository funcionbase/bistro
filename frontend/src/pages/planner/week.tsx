import { AppLink } from '@/components/app-link';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { PlannerViewTabs } from '@/components/planner/planner-view-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DesktopOnlyHint } from '@/components/ui/desktop-only-hint';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodNavigator } from '@/components/ui/period-navigator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { todayInBogota } from '@/lib/datetime';
import { useSharedData } from '@/lib/shared-data';
import { cn } from '@/lib/utils';

import { AlertCircle, Lock, Plus, UsersRound, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Shift = {
    id: string;
    employee_id: string;
    employee_name: string;
    branch_id: string;
    starts_at: string;
    ends_at: string;
    status: string;
    position: { slug: string; label: string; color: string | null } | null;
    cancellation_reason: string | null;
};

type Employee = { id: string; full_name: string };


function startOfWeek(date: Date): Date {
    const d = new Date(date);
    const day = d.getDay() || 7;
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - (day - 1));
    return d;
}

function addDays(d: Date, n: number): Date {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}

function fmtDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function fmtDayLabel(d: Date): string {
    return d.toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric' });
}

function fmtDateHuman(iso: string): string {
    // Acepta yyyy-mm-dd o ISO8601 completo; toma solo la parte de fecha para evitar desfase de zona.
    const d = new Date(`${iso.slice(0, 10)}T00:00:00`);
    return d.toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short' });
}

function fmtWeekRange(start: Date, end: Date): string {
    const sameMonth = start.getMonth() === end.getMonth();
    const startFmt = start.toLocaleDateString('es-CO', { day: 'numeric', month: sameMonth ? undefined : 'short' });
    const endFmt = end.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
    return `Semana del ${startFmt} al ${endFmt}`;
}

function diffHours(start: string, end: string): number {
    return (new Date(end).getTime() - new Date(start).getTime()) / 3600000;
}

const CANCEL_REASONS = [
    { value: 'sick', label: 'Enfermedad' },
    { value: 'personal', label: 'Personal' },
    { value: 'emergency', label: 'Emergencia' },
    { value: 'other', label: 'Otro' },
] as const;

export default function PlannerWeek() {
    useToken();
    const props = useSharedData();
    const { showToast } = useToast();
    const permissions = (props as { permissions?: string[] })?.permissions ?? [];
    const canManage = permissions.includes('shifts.manage');

    const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()));
    const [shifts, setShifts] = useState<Shift[]>([]);
    const [employees, setEmployees] = useState<Employee[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [cancelTarget, setCancelTarget] = useState<Shift | null>(null);
    const [cancelReason, setCancelReason] = useState<string>('personal');
    const [cancelNote, setCancelNote] = useState('');
    const [cancelSubmitting, setCancelSubmitting] = useState(false);
    const [createSubmitting, setCreateSubmitting] = useState(false);
    const [createErrors, setCreateErrors] = useState<Record<string, string>>({});
    const [justCreatedShiftId, setJustCreatedShiftId] = useState<string | null>(null);

    const [formEmployeeId, setFormEmployeeId] = useState('');
    const [formDates, setFormDates] = useState<string[]>([fmtDate(weekStart)]);
    const [formStart, setFormStart] = useState('09:00');
    const [formEnd, setFormEnd] = useState('17:00');
    const [createSkipped, setCreateSkipped] = useState<string[]>([]);

    const weekEnd = useMemo(() => addDays(weekStart, 6), [weekStart]);
    const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
    const selectedDateCount = useMemo(() => new Set(formDates.filter(Boolean)).size, [formDates]);

    const load = async () => {
        setLoading(true);
        setError(null);
        const from = fmtDate(weekStart);
        const to = fmtDate(weekEnd);
        try {
            const [sRes, eRes] = await Promise.all([apiFetch(`/api/v1/shifts?from=${from}&to=${to}`), apiFetch('/api/v1/employees?status=active')]);
            if (!sRes.ok) {
                setError('No pudimos cargar los turnos. Reintenta en unos segundos.');
                setLoading(false);
                return;
            }
            const sJson = await sRes.json();
            setShifts(sJson.data ?? []);
            if (eRes.ok) {
                const eJson = await eRes.json();
                setEmployees((eJson.data ?? []).map((e: { id: string; full_name: string }) => ({ id: e.id, full_name: e.full_name })));
            }
        } catch {
            setError('Error de conexión. Verifica tu red e intenta de nuevo.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [weekStart]);

    const shiftsByEmployeeDay = useMemo(() => {
        const map = new Map<string, Shift[]>();
        for (const s of shifts) {
            const day = s.starts_at.slice(0, 10);
            const key = `${s.employee_id}|${day}`;
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(s);
        }
        return map;
    }, [shifts]);

    const employeesShown = useMemo(() => {
        const ids = new Set(shifts.map((s) => s.employee_id));
        employees.forEach((e) => ids.add(e.id));
        const byId = new Map<string, string>();
        employees.forEach((e) => byId.set(e.id, e.full_name));
        shifts.forEach((s) => byId.set(s.employee_id, s.employee_name));
        return Array.from(ids).map((id) => ({ id, full_name: byId.get(id) ?? id }));
    }, [shifts, employees]);

    const stats = useMemo(() => {
        let scheduledHours = 0;
        let cancelledCount = 0;
        const activeEmployees = new Set<string>();
        for (const s of shifts) {
            if (s.status === 'scheduled') {
                scheduledHours += diffHours(s.starts_at, s.ends_at);
                activeEmployees.add(s.employee_id);
            } else if (s.status === 'cancelled') {
                cancelledCount += 1;
            }
        }
        return {
            scheduledHours,
            cancelledCount,
            employeesWithShifts: activeEmployees.size,
            totalShifts: shifts.length,
        };
    }, [shifts]);

    const openCreate = () => {
        setCreateErrors({});
        setCreateSkipped([]);
        setFormEmployeeId('');
        setFormDates([fmtDate(weekStart)]);
        setFormStart('09:00');
        setFormEnd('17:00');
        setCreateOpen(true);
    };

    const addDateRow = () => {
        setFormDates((prev) => {
            // Sugiere el día siguiente a la última fecha válida para reducir clics.
            const valid = prev.filter(Boolean).sort();
            const base = valid.length > 0 ? valid[valid.length - 1] : fmtDate(weekStart);
            const next = fmtDate(addDays(new Date(`${base}T00:00:00`), 1));
            return [...prev, next];
        });
    };

    const updateDateRow = (idx: number, value: string) => {
        setFormDates((prev) => prev.map((d, i) => (i === idx ? value : d)));
    };

    const removeDateRow = (idx: number) => {
        setFormDates((prev) => (prev.length <= 1 ? prev : prev.filter((_, i) => i !== idx)));
    };

    /** Expande una fecha (yyyy-mm-dd) + inicio/fin a un turno, aplicando cruce de medianoche. */
    const buildShift = (date: string) => {
        const starts = `${date}T${formStart}:00`;
        let endsDate = date;
        if (formEnd <= formStart) {
            endsDate = fmtDate(addDays(new Date(`${date}T00:00:00`), 1));
        }
        return { starts_at: starts, ends_at: `${endsDate}T${formEnd}:00` };
    };

    const createShift = async () => {
        setCreateErrors({});
        setCreateSkipped([]);
        const uniqueDates = Array.from(new Set(formDates.filter(Boolean)));
        const errs: Record<string, string> = {};
        if (!formEmployeeId) errs.employee_id = 'Selecciona un colaborador.';
        if (uniqueDates.length === 0) errs.date = 'Agrega al menos una fecha.';
        if (Object.keys(errs).length > 0) {
            setCreateErrors(errs);
            return;
        }
        const branchId = (props as { activeBranch?: { id: string } })?.activeBranch?.id;
        if (!branchId) {
            setCreateErrors({ general: 'Sin sede activa. Selecciona una sede antes de asignar turnos.' });
            return;
        }
        setCreateSubmitting(true);
        const shiftsPayload = uniqueDates.map(buildShift);
        try {
            const res = await apiFetch('/api/v1/shifts/bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    employee_id: formEmployeeId,
                    branch_id: branchId,
                    shifts: shiftsPayload,
                }),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                if (res.status === 422 && data.errors) {
                    const mapped: Record<string, string> = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        // Colapsa errores por-índice (shifts.0.ends_at) en un único mensaje legible.
                        const key = field.startsWith('shifts.') ? 'date' : field;
                        if (!mapped[key]) mapped[key] = messages[0];
                    }
                    setCreateErrors(mapped);
                } else {
                    setCreateErrors({ general: data.message ?? 'No se pudieron crear los turnos.' });
                }
                return;
            }
            const json = await res.json().catch(() => null);
            const created: { id: string }[] = json?.data?.created ?? [];
            const skipped: { starts_at: string }[] = json?.data?.skipped ?? [];

            if (created.length === 0) {
                // Todo se solapó: deja el modal abierto y reporta los conflictos.
                setCreateSkipped(skipped.map((s) => fmtDateHuman(s.starts_at)));
                setCreateErrors({ general: 'Ninguna fecha quedó: el colaborador ya tenía turno en todas.' });
                return;
            }

            setCreateOpen(false);
            const msg =
                skipped.length > 0
                    ? `${created.length} turno(s) asignado(s) · ${skipped.length} con conflicto`
                    : `${created.length} turno(s) asignado(s)`;
            showToast('success', msg);

            if (created.length === 1) {
                const newId = created[0].id;
                setJustCreatedShiftId(newId);
                window.setTimeout(() => {
                    setJustCreatedShiftId((cur) => (cur === newId ? null : cur));
                }, 1000);
            }
            await load();
        } catch {
            setCreateErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setCreateSubmitting(false);
        }
    };

    const openCancel = (s: Shift) => {
        setCancelTarget(s);
        setCancelReason('personal');
        setCancelNote('');
    };

    const submitCancel = async () => {
        if (!cancelTarget) return;
        setCancelSubmitting(true);
        try {
            const res = await apiFetch(`/api/v1/shifts/${cancelTarget.id}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: cancelReason, note: cancelNote || null }),
            });
            if (res.ok) {
                showToast('success', 'Turno cancelado');
                setCancelTarget(null);
                await load();
            } else {
                showToast('error', 'No se pudo cancelar el turno.');
            }
        } catch {
            showToast('error', 'Error de conexión. Intenta de nuevo.');
        } finally {
            setCancelSubmitting(false);
        }
    };

    const headerActions = (
        <>
            <PeriodNavigator
                label={fmtWeekRange(weekStart, weekEnd)}
                onPrev={() => setWeekStart(addDays(weekStart, -7))}
                onNext={() => setWeekStart(addDays(weekStart, 7))}
                onToday={() => setWeekStart(startOfWeek(new Date()))}
                disabled={loading}
            />
            {canManage && (
                <Button variant="default" size="sm" onClick={openCreate}>
                    <Plus className="h-4 w-4" />
                    Asignar turno
                </Button>
            )}
        </>
    );

    return (
        <PageShell title="Planificador">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <DesktopOnlyHint
                    title="Planificador semanal"
                    description="La cuadrícula semanal queda apretada en el celular. Para arrastrar/ajustar turnos, usa tablet o desktop."
                />
                <PageHeader
                    eyebrow="PLANIFICADOR"
                    title="Planificador semanal"
                    description="Asigna turnos a tu equipo y revisa el cubrimiento de la semana."
                    actions={headerActions}
                />

                <PlannerViewTabs active="week" />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {!canManage && !loading && (
                    <Alert variant="warning">
                        <Lock className="h-4 w-4" />
                        <AlertDescription>Solo lectura. Pídele a un administrador permisos sobre turnos para asignar o cancelar.</AlertDescription>
                    </Alert>
                )}

                {shifts.length > 0 && (
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <StatTile value={`${stats.scheduledHours.toFixed(1)}h`} label="Horas programadas" size="lg" />
                        <StatTile value={stats.totalShifts} label="Turnos" size="lg" />
                        <StatTile
                            value={stats.employeesWithShifts}
                            label="Colaboradores"
                            tone={stats.employeesWithShifts > 0 ? 'primary' : 'default'}
                            size="lg"
                        />
                        <StatTile value={stats.cancelledCount} label="Cancelados" tone={stats.cancelledCount > 0 ? 'warning' : 'default'} size="lg" />
                    </div>
                )}

                {loading && shifts.length === 0 ? (
                    <Card className="rounded-lg shadow-sm">
                        <div className="overflow-x-auto p-4">
                            <div className="min-w-[640px] space-y-3">
                                {[...Array(5)].map((_, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <Skeleton className="h-9 w-32 shrink-0" />
                                        {[...Array(7)].map((_, j) => (
                                            <Skeleton key={j} className="h-9 flex-1" />
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </Card>
                ) : employeesShown.length === 0 && !loading ? (
                    <EditorialEmpty
                        eyebrow="Empezar"
                        icon={<UsersRound className="h-10 w-10" />}
                        title="Sin colaboradores activos en esta sede"
                        description="Crea colaboradores activos en la sede para empezar a asignarles turnos. Una vez existan, aparecerán aquí."
                        action={
                            <Button variant="default" size="lg" asChild>
                                <AppLink href="/employees">Ir a colaboradores</AppLink>
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="bg-muted/50 sticky left-0 z-10">Colaborador</TableHead>
                                    {days.map((d) => {
                                        const isToday = fmtDate(d) === todayInBogota();
                                        return (
                                            <TableHead
                                                key={d.toISOString()}
                                                className={cn('border-l text-center', isToday && 'bg-accent/30 text-accent-foreground')}
                                            >
                                                {fmtDayLabel(d)}
                                            </TableHead>
                                        );
                                    })}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {employeesShown.map((emp) => (
                                    <TableRow key={emp.id}>
                                        <TableCell className="bg-card sticky left-0 z-10 font-medium">{emp.full_name}</TableCell>
                                        {days.map((d) => {
                                            const dayKey = fmtDate(d);
                                            const dayShifts = shiftsByEmployeeDay.get(`${emp.id}|${dayKey}`) ?? [];
                                            return (
                                                <TableCell key={dayKey} className="border-l align-top">
                                                    <div className="space-y-1.5">
                                                        {dayShifts.map((s) => {
                                                            const isCancelled = s.status === 'cancelled';
                                                            const canCancel = canManage && s.status === 'scheduled';
                                                            return (
                                                                <button
                                                                    key={s.id}
                                                                    type="button"
                                                                    onClick={() => canCancel && openCancel(s)}
                                                                    disabled={!canCancel}
                                                                    className={cn(
                                                                        'border-border block w-full rounded-md border px-2 py-1.5 text-left text-xs transition-colors',
                                                                        canCancel && 'hover:bg-muted cursor-pointer',
                                                                        !canCancel && 'cursor-default',
                                                                        isCancelled && 'opacity-60',
                                                                        justCreatedShiftId === s.id && 'animate-scale-in',
                                                                    )}
                                                                    style={
                                                                        !isCancelled && s.position?.color
                                                                            ? {
                                                                                  borderLeftColor: s.position.color,
                                                                                  borderLeftWidth: '3px',
                                                                              }
                                                                            : undefined
                                                                    }
                                                                    title={
                                                                        isCancelled
                                                                            ? `Cancelado (${s.cancellation_reason ?? '—'})`
                                                                            : canCancel
                                                                              ? 'Cancelar turno'
                                                                              : undefined
                                                                    }
                                                                >
                                                                    <div className="font-mono text-[10px] tabular-nums">
                                                                        {s.starts_at.slice(11, 16)}–{s.ends_at.slice(11, 16)}
                                                                    </div>
                                                                    {s.position && <div className="truncate text-[11px]">{s.position.label}</div>}
                                                                    {isCancelled && (
                                                                        <Badge variant="destructive" className="mt-0.5 text-[9px]">
                                                                            Cancelado
                                                                        </Badge>
                                                                    )}
                                                                </button>
                                                            );
                                                        })}
                                                        {dayShifts.length === 0 && <span className="text-muted-foreground/40 text-[10px]">—</span>}
                                                    </div>
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            <Dialog
                open={createOpen}
                onOpenChange={(o) => {
                    if (!o && !createSubmitting) setCreateOpen(false);
                }}
            >
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Asignar turno</DialogTitle>
                        <DialogDescription>
                            Agrega una o varias fechas con el mismo horario. Se asignan a la sede activa. Si fin ≤ inicio, se asume cruce de
                            medianoche.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {createErrors.general && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{createErrors.general}</AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="shift-employee">Colaborador *</Label>
                            <Select value={formEmployeeId} onValueChange={setFormEmployeeId}>
                                <SelectTrigger id="shift-employee">
                                    <SelectValue placeholder="Selecciona un colaborador" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((e) => (
                                        <SelectItem key={e.id} value={e.id}>
                                            {e.full_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={createErrors.employee_id} className="text-xs" />
                        </div>

                        <div className="grid gap-2">
                            <Label>Fechas *</Label>
                            <div className="space-y-2">
                                {formDates.map((d, idx) => (
                                    <div key={idx} className="flex items-center gap-2">
                                        <Input
                                            type="date"
                                            value={d}
                                            onChange={(e) => updateDateRow(idx, e.target.value)}
                                            aria-label={`Fecha ${idx + 1}`}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeDateRow(idx)}
                                            disabled={formDates.length <= 1}
                                            aria-label="Quitar fecha"
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                            <Button type="button" variant="outline" size="sm" className="w-fit" onClick={addDateRow}>
                                <Plus className="h-4 w-4" />
                                Agregar fecha
                            </Button>
                            <InputError message={createErrors.date ?? createErrors.starts_at} className="text-xs" />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="shift-start">Inicio *</Label>
                                <Input id="shift-start" type="time" value={formStart} onChange={(e) => setFormStart(e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="shift-end">Fin *</Label>
                                <Input id="shift-end" type="time" value={formEnd} onChange={(e) => setFormEnd(e.target.value)} />
                            </div>
                        </div>
                        <InputError message={createErrors.ends_at} className="text-xs" />

                        {createSkipped.length > 0 && (
                            <Alert variant="warning">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>
                                    Con conflicto (ya tenían turno): {createSkipped.join(', ')}.
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setCreateOpen(false)} disabled={createSubmitting}>
                            Cancelar
                        </Button>
                        <Button onClick={createShift} disabled={createSubmitting || !formEmployeeId || selectedDateCount === 0}>
                            {createSubmitting ? 'Asignando…' : selectedDateCount > 1 ? `Asignar ${selectedDateCount} turnos` : 'Asignar turno'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!cancelTarget} onOpenChange={(o) => !o && !cancelSubmitting && setCancelTarget(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Cancelar turno</DialogTitle>
                        <DialogDescription>
                            {cancelTarget &&
                                `${cancelTarget.employee_name} · ${cancelTarget.starts_at.slice(0, 10)} · ${cancelTarget.starts_at.slice(11, 16)}–${cancelTarget.ends_at.slice(11, 16)}`}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="cancel-reason">Motivo *</Label>
                            <Select value={cancelReason} onValueChange={setCancelReason}>
                                <SelectTrigger id="cancel-reason">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CANCEL_REASONS.map((r) => (
                                        <SelectItem key={r.value} value={r.value}>
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cancel-note">Nota (opcional)</Label>
                            <textarea
                                id="cancel-note"
                                value={cancelNote}
                                onChange={(e) => setCancelNote(e.target.value)}
                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-hidden"
                                rows={3}
                                placeholder="Detalles para el registro"
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setCancelTarget(null)} disabled={cancelSubmitting}>
                            Cerrar
                        </Button>
                        <Button variant="destructive" onClick={submitCancel} disabled={cancelSubmitting}>
                            {cancelSubmitting ? 'Cancelando…' : 'Cancelar turno'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PageShell>
    );
}
