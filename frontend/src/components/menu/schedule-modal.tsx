import DaySelector from '@/components/menu/day-selector';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { apiFetch } from '@/lib/api';
import type { RestaurantMenu } from '@/types';
import { AlertCircle } from 'lucide-react';
import { useState } from 'react';

interface ScheduleModalProps {
    menu: RestaurantMenu;
    onClose: () => void;
    onSaved: (menu: RestaurantMenu) => void;
}

export default function ScheduleModal({ menu, onClose, onSaved }: ScheduleModalProps) {
    const [selectedDays, setSelectedDays] = useState<number[]>(menu.active_days ?? []);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleSave() {
        setSubmitting(true);
        setError(null);
        try {
            const res = await apiFetch(`/api/v1/menus/${menu.id}/schedule`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    active_days: selectedDays.length > 0 ? selectedDays : null,
                }),
            });
            const data = await res.json();
            if (!res.ok) {
                setError(data.message ?? 'Error al guardar la programación.');
                return;
            }
            onSaved(data.data);
        } catch {
            setError('Error de conexión.');
        } finally {
            setSubmitting(false);
        }
    }

    function handleClear() {
        setSelectedDays([]);
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Programar menú</DialogTitle>
                    <DialogDescription>
                        {menu.name} · Selecciona los días en que este menú debe activarse automáticamente. Solo puede haber un menú activo por día.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <DaySelector selected={selectedDays} onChange={setSelectedDays} />

                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter className="justify-between sm:justify-between">
                    <Button type="button" variant="ghost" size="sm" onClick={handleClear} disabled={submitting}>
                        Quitar programación
                    </Button>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="button" onClick={handleSave} disabled={submitting}>
                            {submitting ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
