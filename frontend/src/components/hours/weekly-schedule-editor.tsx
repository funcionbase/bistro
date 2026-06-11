import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import type { BusinessHourFormData } from '@/types/business-hours';
import { Clock, Info, LoaderCircle } from 'lucide-react';

const DAY_NAMES = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MON_TO_SUN = [1, 2, 3, 4, 5, 6, 0];

interface WeeklyScheduleEditorProps {
    schedule: BusinessHourFormData[];
    loading: boolean;
    saving: boolean;
    readOnly: boolean;
    onUpdateDay: (idx: number, patch: Partial<BusinessHourFormData>) => void;
    onSave: () => void;
}

export function WeeklyScheduleEditor({ schedule, loading, saving, readOnly, onUpdateDay, onSave }: WeeklyScheduleEditorProps) {
    return (
        <DashboardPanel title="Horario semanal base" icon={Clock} contentClassName="px-0 pb-0">
            {loading ? (
                <div className="space-y-2 px-6 py-2">
                    {Array.from({ length: 7 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full" />
                    ))}
                </div>
            ) : (
                <div className="divide-border divide-y">
                    {MON_TO_SUN.map((dow) => {
                        const day = schedule.find((d) => d.day_of_week === dow);
                        if (!day) return null;
                        return (
                            <div key={day.day_of_week} className="flex flex-col gap-3 px-6 py-3 sm:flex-row sm:items-center">
                                <div className="flex w-36 shrink-0 items-center gap-3">
                                    <Checkbox
                                        id={`hours-day-${day.day_of_week}`}
                                        checked={day.is_enabled}
                                        disabled={readOnly}
                                        onCheckedChange={(v) => onUpdateDay(day.day_of_week, { is_enabled: v === true })}
                                    />
                                    <label
                                        htmlFor={`hours-day-${day.day_of_week}`}
                                        className={`cursor-pointer text-sm font-medium ${
                                            day.is_enabled ? 'text-foreground' : 'text-muted-foreground'
                                        } ${readOnly ? 'cursor-not-allowed' : ''}`}
                                    >
                                        {DAY_NAMES[day.day_of_week]}
                                    </label>
                                </div>

                                {day.is_enabled ? (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="time"
                                            value={day.open_time}
                                            disabled={readOnly}
                                            onChange={(e) => onUpdateDay(day.day_of_week, { open_time: e.target.value })}
                                            className="h-9 w-28 text-sm tabular-nums"
                                        />
                                        <span className="text-muted-foreground">–</span>
                                        <Input
                                            type="time"
                                            value={day.close_time}
                                            disabled={readOnly}
                                            onChange={(e) => onUpdateDay(day.day_of_week, { close_time: e.target.value })}
                                            className="h-9 w-28 text-sm tabular-nums"
                                        />
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground text-sm">Cerrado</span>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {!readOnly && (
                <div className="border-border bg-card sticky bottom-0 border-t px-6 py-4">
                    <Button onClick={onSave} disabled={saving || loading}>
                        {saving && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                        Guardar horario
                    </Button>
                </div>
            )}

            {readOnly && (
                <div className="border-border text-muted-foreground flex items-center gap-2 border-t px-6 py-3 text-xs">
                    <Info className="h-3.5 w-3.5" />
                    Vista de solo lectura. Necesitas permiso <code className="bg-muted rounded px-1 py-0.5">hours.update</code> para editar.
                </div>
            )}
        </DashboardPanel>
    );
}
