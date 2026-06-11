import { type ReactNode, useMemo } from 'react';

import { cn } from '@/lib/utils';

interface MonthCalendarGridProps {
    /**
     * Fecha que ancla el mes a renderizar (cualquier día dentro del mes objetivo).
     */
    anchor: Date;
    /**
     * Render prop por celda. Recibe la fecha y flags computadas. Retorna el
     * contenido interno (badges, números, indicadores). Si retorna `null` se
     * pinta la celda sin contenido extra (solo número del día).
     */
    renderCell: (day: Date, ctx: { isCurrentMonth: boolean; isToday: boolean; dayKey: string }) => ReactNode;
    /**
     * Callback al click en una celda. Si está definido la celda se vuelve un
     * `<button>` interactivo; si no, se renderiza como `<div>`.
     */
    onDayClick?: (day: Date) => void;
    /** Mostrar leyenda de día (true por defecto). */
    showWeekdays?: boolean;
    /** Día de inicio de la semana — 'monday' (default) o 'sunday'. */
    weekStartsOn?: 'monday' | 'sunday';
    className?: string;
}

const WEEKDAYS_MONDAY = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const WEEKDAYS_SUNDAY = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

function fmtDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function startOfWeek(d: Date, weekStartsOn: 'monday' | 'sunday'): Date {
    const offset = weekStartsOn === 'monday' ? (d.getDay() || 7) - 1 : d.getDay();
    const r = new Date(d);
    r.setHours(0, 0, 0, 0);
    r.setDate(r.getDate() - offset);
    return r;
}

/**
 * Grid de calendario mensual 7×N con tokens del design system v3.1.
 *
 * Reusable para planificador de turnos, vistas de reporte por día, calendarios
 * de asistencia y agendas. La página define el contenido de cada celda vía
 * `renderCell` — el grid solo se encarga del shell visual (today highlight,
 * días fuera del mes con opacidad, hover, cabecera de weekdays).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §10 (tablas y data density).
 */
export function MonthCalendarGrid({
    anchor,
    renderCell,
    onDayClick,
    showWeekdays = true,
    weekStartsOn = 'monday',
    className,
}: MonthCalendarGridProps) {
    const monthStart = useMemo(() => new Date(anchor.getFullYear(), anchor.getMonth(), 1), [anchor]);
    const monthEnd = useMemo(() => new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0), [anchor]);

    const days = useMemo(() => {
        const firstCell = startOfWeek(monthStart, weekStartsOn);
        const result: Date[] = [];
        const cursor = new Date(firstCell);
        while (cursor <= monthEnd || result.length % 7 !== 0 || result.length < 35) {
            result.push(new Date(cursor));
            cursor.setDate(cursor.getDate() + 1);
            if (result.length >= 42) break;
        }
        return result;
    }, [monthStart, monthEnd, weekStartsOn]);

    const weekdays = weekStartsOn === 'monday' ? WEEKDAYS_MONDAY : WEEKDAYS_SUNDAY;
    const todayKey = fmtDate(new Date());

    return (
        <div className={cn('grid grid-cols-7 gap-1 text-xs', className)}>
            {showWeekdays &&
                weekdays.map((d) => (
                    <div
                        key={d}
                        className="text-muted-foreground py-2 text-center text-[11px] font-semibold uppercase tracking-[0.15em]"
                    >
                        {d}
                    </div>
                ))}
            {days.map((day, idx) => {
                const isCurrentMonth = day.getMonth() === anchor.getMonth();
                const dayKey = fmtDate(day);
                const isToday = dayKey === todayKey;
                const content = renderCell(day, { isCurrentMonth, isToday, dayKey });

                const cellClassName = cn(
                    'border-border flex h-24 flex-col items-start gap-1 rounded-md border p-2 text-left transition-colors',
                    isCurrentMonth ? 'bg-card' : 'bg-muted/30 opacity-60',
                    isToday && 'border-primary ring-primary/20 ring-2',
                    onDayClick && 'hover:border-primary hover:bg-muted/50 cursor-pointer',
                );

                const inner = (
                    <>
                        <span className={cn('font-mono text-xs tabular-nums', isToday && 'text-primary font-semibold')}>
                            {day.getDate()}
                        </span>
                        {content}
                    </>
                );

                if (onDayClick) {
                    return (
                        <button key={idx} type="button" onClick={() => onDayClick(day)} className={cellClassName}>
                            {inner}
                        </button>
                    );
                }

                return (
                    <div key={idx} className={cellClassName}>
                        {inner}
                    </div>
                );
            })}
        </div>
    );
}
