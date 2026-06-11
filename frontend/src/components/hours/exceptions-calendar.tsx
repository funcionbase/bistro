import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { cn } from '@/lib/utils';
import type { BusinessHourException } from '@/types/business-hours';
import { CalendarOff, ChevronLeft, ChevronRight, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

const MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

interface ExceptionsCalendarProps {
    exceptions: BusinessHourException[];
    readOnly: boolean;
    onAdd: (date: string) => void;
    onEdit: (exc: BusinessHourException) => void;
    onDelete: (exc: BusinessHourException) => void;
}

export function ExceptionsCalendar({ exceptions, onAdd, onEdit, onDelete, readOnly }: ExceptionsCalendarProps) {
    const today = new Date();
    const [viewYear, setViewYear] = useState(today.getFullYear());
    const [viewMonth, setViewMonth] = useState(today.getMonth());

    const exceptionByDate = Object.fromEntries(exceptions.map((e) => [e.exception_date, e]));

    function prevMonth() {
        if (viewMonth === 0) {
            setViewYear((y) => y - 1);
            setViewMonth(11);
        } else {
            setViewMonth((m) => m - 1);
        }
    }

    function nextMonth() {
        if (viewMonth === 11) {
            setViewYear((y) => y + 1);
            setViewMonth(0);
        } else {
            setViewMonth((m) => m + 1);
        }
    }

    const firstDay = new Date(viewYear, viewMonth, 1).getDay();
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
    const cells: (number | null)[] = [...Array(firstDay).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => i + 1)];

    function cellDate(day: number): string {
        return `${viewYear}-${String(viewMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function isPast(day: number): boolean {
        const d = new Date(viewYear, viewMonth, day);
        d.setHours(0, 0, 0, 0);
        const t = new Date();
        t.setHours(0, 0, 0, 0);
        return d < t;
    }

    function isToday(day: number): boolean {
        return today.getFullYear() === viewYear && today.getMonth() === viewMonth && today.getDate() === day;
    }

    const monthNavSlot = (
        <div className="flex items-center gap-1">
            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={prevMonth} aria-label="Mes anterior">
                <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="text-foreground min-w-[140px] text-center text-sm font-medium">
                {MONTH_NAMES[viewMonth]} {viewYear}
            </span>
            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={nextMonth} aria-label="Mes siguiente">
                <ChevronRight className="h-4 w-4" />
            </Button>
        </div>
    );

    return (
        <DashboardPanel title="Excepciones" icon={CalendarOff} rightSlot={monthNavSlot} contentClassName="px-4 pb-0">
            <div>
                <div className="mb-1 grid grid-cols-7 text-center">
                    {['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'].map((d) => (
                        <div key={d} className="text-muted-foreground py-1 text-xs font-semibold">
                            {d}
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-7 gap-0.5">
                    {cells.map((day, i) => {
                        if (!day) return <div key={`empty-${i}`} />;
                        const dateStr = cellDate(day);
                        const exc = exceptionByDate[dateStr];
                        const past = isPast(day);
                        const todayCell = isToday(day);

                        return (
                            <button
                                key={day}
                                type="button"
                                disabled={readOnly || past}
                                onClick={() => {
                                    if (exc) onEdit(exc);
                                    else if (!past) onAdd(dateStr);
                                }}
                                title={
                                    exc ? `${exc.is_open ? 'Abierto especial' : 'Cerrado'}: ${exc.reason}` : past ? undefined : 'Agregar excepción'
                                }
                                className={cn(
                                    'relative flex h-10 flex-col items-center justify-center rounded-md text-sm transition-colors',
                                    todayCell && 'ring-primary font-bold ring-2 ring-offset-1',
                                    exc && exc.is_open && 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]',
                                    exc && !exc.is_open && 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]',
                                    !exc && !past && !readOnly && 'hover:bg-muted/60 text-foreground',
                                    past && 'text-muted-foreground/40 cursor-default',
                                )}
                            >
                                <span>{day}</span>
                                {exc && (
                                    <span className="absolute bottom-1 left-1/2 -translate-x-1/2">
                                        <span
                                            className={cn(
                                                'inline-block h-1 w-1 rounded-full',
                                                exc.is_open ? 'bg-[color:var(--color-status-safe)]' : 'bg-[color:var(--color-status-critical)]',
                                            )}
                                        />
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            {exceptions.length > 0 && (
                <div className="border-border -mx-4 mt-4 border-t">
                    <div className="divide-border max-h-52 divide-y overflow-y-auto">
                        {exceptions.map((exc) => {
                            const [y, m, d] = exc.exception_date.split('-');
                            const label = new Date(Number(y), Number(m) - 1, Number(d)).toLocaleDateString('es-CO', {
                                weekday: 'short',
                                day: 'numeric',
                                month: 'short',
                            });
                            return (
                                <div key={exc.id} className="hover:bg-muted/40 flex items-center justify-between px-4 py-2.5 transition-colors">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Badge variant={exc.is_open ? 'safe' : 'critical'} className="shrink-0">
                                            {exc.is_open ? 'Especial' : 'Cerrado'}
                                        </Badge>
                                        <span className="text-foreground truncate text-sm font-medium">{label}</span>
                                        <span className="text-muted-foreground truncate text-xs">{exc.reason}</span>
                                    </div>
                                    {!readOnly && (
                                        <div className="ml-2 flex shrink-0 gap-0.5">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground hover:text-foreground h-7 w-7"
                                                onClick={() => onEdit(exc)}
                                                aria-label="Editar excepción"
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground hover:text-destructive h-7 w-7"
                                                onClick={() => onDelete(exc)}
                                                aria-label="Eliminar excepción"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {exceptions.length === 0 && (
                <div className="flex flex-col items-center justify-center py-10 text-center">
                    <p className="text-muted-foreground text-sm">Sin excepciones próximas</p>
                    {!readOnly && (
                        <Button variant="link" size="sm" onClick={() => onAdd('')} className="mt-1 h-auto p-0">
                            Agregar excepción
                        </Button>
                    )}
                </div>
            )}
        </DashboardPanel>
    );
}
