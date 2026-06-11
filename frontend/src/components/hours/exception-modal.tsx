import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BusinessHourException } from '@/types/business-hours';
import { Info, LoaderCircle } from 'lucide-react';

export interface ExceptionFormState {
    exception_date: string;
    reason: string;
    is_open: boolean;
    open_time: string;
    close_time: string;
}

export const EMPTY_EXCEPTION: ExceptionFormState = {
    exception_date: '',
    reason: '',
    is_open: false,
    open_time: '10:00',
    close_time: '18:00',
};

interface ExceptionModalProps {
    editing: BusinessHourException | null;
    form: ExceptionFormState;
    onChange: (patch: Partial<ExceptionFormState>) => void;
    onSubmit: () => void;
    onCancel: () => void;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function ExceptionModal({ editing, form, onChange, onSubmit, onCancel, submitting, errors }: ExceptionModalProps) {
    return (
        <Dialog open onOpenChange={(open) => !open && onCancel()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Editar excepción' : 'Nueva excepción de horario'}</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="exception-date">Fecha</Label>
                        <Input
                            id="exception-date"
                            type="date"
                            value={form.exception_date}
                            onChange={(e) => onChange({ exception_date: e.target.value })}
                        />
                        {errors.exception_date && <p className="text-destructive text-xs">{errors.exception_date[0]}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="exception-reason">Motivo</Label>
                        <Input
                            id="exception-reason"
                            type="text"
                            value={form.reason}
                            onChange={(e) => onChange({ reason: e.target.value })}
                            placeholder="Ej: Feriado nacional, evento especial…"
                        />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason[0]}</p>}
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center gap-3">
                            <Checkbox id="exception-is-open" checked={form.is_open} onCheckedChange={(v) => onChange({ is_open: v === true })} />
                            <Label htmlFor="exception-is-open" className="cursor-pointer">
                                {form.is_open ? 'Abierto este día (horario especial)' : 'Cerrado este día'}
                            </Label>
                        </div>
                        <Alert variant={form.is_open ? 'default' : 'warning'} className="py-2.5">
                            <Info className="h-3.5 w-3.5" />
                            <AlertDescription className="text-xs">
                                {form.is_open
                                    ? 'El menú público estará disponible solo dentro del rango horario especial.'
                                    : 'El menú público y el carrito estarán ocultos todo este día.'}
                            </AlertDescription>
                        </Alert>
                    </div>

                    {form.is_open && (
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="exception-open-time">Abre</Label>
                                <Input
                                    id="exception-open-time"
                                    type="time"
                                    value={form.open_time}
                                    onChange={(e) => onChange({ open_time: e.target.value })}
                                    className="tabular-nums"
                                />
                                {errors.open_time && <p className="text-destructive text-xs">{errors.open_time[0]}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="exception-close-time">Cierra</Label>
                                <Input
                                    id="exception-close-time"
                                    type="time"
                                    value={form.close_time}
                                    onChange={(e) => onChange({ close_time: e.target.value })}
                                    className="tabular-nums"
                                />
                                {errors.close_time && <p className="text-destructive text-xs">{errors.close_time[0]}</p>}
                            </div>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel}>
                        Cancelar
                    </Button>
                    <Button onClick={onSubmit} disabled={submitting}>
                        {submitting ? <LoaderCircle className="h-4 w-4 animate-spin" /> : editing ? 'Guardar cambios' : 'Crear excepción'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
