import { CourierAvatar } from '@/components/deliveries/courier-avatar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { Courier } from '@/types';
import { AlertCircle, Loader2, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';

const MAX_ACTIVE_DISPLAY = 3;

interface AssignCourierModalProps {
    orderId: string;
    isOpen: boolean;
    onClose: () => void;
    onAssign: (courierId: string) => Promise<void>;
    couriers: Courier[];
    loading: boolean;
}

export function AssignCourierModal({ orderId, isOpen, onClose, onAssign, couriers, loading }: AssignCourierModalProps) {
    const [selectedCourierId, setSelectedCourierId] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (isOpen) {
            setSelectedCourierId(null);
            setError(null);
        }
    }, [isOpen]);

    const handleAssign = async () => {
        if (!selectedCourierId) return;
        setSubmitting(true);
        setError(null);
        try {
            await onAssign(selectedCourierId);
            onClose();
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : 'Error al asignar repartidor.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Truck className="h-5 w-5" />
                        Asignar Repartidor — Orden #{orderId}
                    </DialogTitle>
                </DialogHeader>

                <div className="py-2">
                    {loading ? (
                        <div className="flex items-center justify-center py-8">
                            <Loader2 className="text-muted-foreground h-8 w-8 animate-spin" />
                        </div>
                    ) : couriers.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-center text-sm">No hay repartidores disponibles.</p>
                    ) : (
                        <div className="space-y-2">
                            {couriers.map((courier) => {
                                const isFull = !courier.available;
                                return (
                                    <label
                                        key={courier.id}
                                        className={[
                                            'flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors',
                                            isFull
                                                ? 'cursor-not-allowed opacity-50'
                                                : selectedCourierId === courier.id
                                                  ? 'border-primary bg-primary/5'
                                                  : 'hover:bg-muted/50',
                                        ].join(' ')}
                                    >
                                        <input
                                            type="radio"
                                            name="courier"
                                            value={courier.id}
                                            disabled={isFull || submitting}
                                            checked={selectedCourierId === courier.id}
                                            onChange={() => setSelectedCourierId(courier.id)}
                                            className="mt-0.5"
                                        />
                                        <CourierAvatar name={courier.name} size="sm" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{courier.name}</span>
                                                {isFull && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-[color:var(--color-status-critical)]/15 px-1.5 py-0.5 text-xs font-medium text-[color:var(--color-status-critical)]">
                                                        FULL
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground mt-0.5 text-xs">
                                                {courier.active_deliveries_count}/{MAX_ACTIVE_DISPLAY} activas · {courier.daily_completed_count}{' '}
                                                completadas hoy
                                            </div>
                                        </div>
                                    </label>
                                );
                            })}
                        </div>
                    )}

                    {error && (
                        <Alert variant="destructive" className="mt-2">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={submitting}>
                        Cancelar
                    </Button>
                    <Button onClick={handleAssign} disabled={!selectedCourierId || submitting || loading}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Asignar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
