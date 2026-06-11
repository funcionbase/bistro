import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { AvailableDeliverer } from '@/types';
import { AlertCircle, Loader2, UserCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

const REASONS = [
    { key: 'client_request', label: 'Cliente pidió cambio' },
    { key: 'not_available', label: 'Repartidor no disponible' },
    { key: 'route_change', label: 'Cambio de ruta' },
    { key: 'other', label: 'Otro (especificar)' },
];

interface ReassignModalProps {
    orderId: string;
    currentUserId: string;
    isOpen: boolean;
    onClose: () => void;
    onConfirm: (newCourierId: string, reason: string) => Promise<void>;
    couriers: AvailableDeliverer[];
    loading: boolean;
}

export function ReassignModal({ orderId, currentUserId, isOpen, onClose, onConfirm, couriers, loading }: ReassignModalProps) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [reasonKey, setReasonKey] = useState('');
    const [detail, setDetail] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (isOpen) {
            setSelectedId(null);
            setReasonKey('');
            setDetail('');
            setError(null);
        }
    }, [isOpen]);

    const available = couriers.filter((c) => c.id !== currentUserId);
    const isOther = reasonKey === 'other';
    const isValid = selectedId !== null && reasonKey !== '' && (!isOther || detail.trim() !== '');

    async function handleSubmit() {
        if (!isValid || !selectedId) return;
        setSubmitting(true);
        setError(null);
        try {
            const reason = isOther ? detail.trim() : (REASONS.find((r) => r.key === reasonKey)?.label ?? reasonKey);
            await onConfirm(selectedId, reason);
            onClose();
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : 'Error al reasignar.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <UserCheck className="h-5 w-5" />
                        Reasignar Entrega — Orden #{orderId}
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div>
                        <p className="text-foreground mb-2 text-sm font-medium">Nuevo repartidor</p>
                        {loading ? (
                            <div className="flex items-center justify-center py-6">
                                <Loader2 className="text-muted-foreground h-6 w-6 animate-spin" />
                            </div>
                        ) : available.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No hay otros repartidores disponibles.</p>
                        ) : (
                            <div className="space-y-2">
                                {available.map((courier) => (
                                    <label
                                        key={courier.id}
                                        className={[
                                            'flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors',
                                            selectedId === courier.id ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
                                        ].join(' ')}
                                    >
                                        <input
                                            type="radio"
                                            name="reassign-courier"
                                            value={courier.id}
                                            checked={selectedId === courier.id}
                                            onChange={() => setSelectedId(courier.id)}
                                            disabled={submitting}
                                            className="mt-0.5"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">{courier.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {courier.active_deliveries_count} activas · {courier.daily_completed_count} completadas hoy
                                            </p>
                                        </div>
                                    </label>
                                ))}
                            </div>
                        )}
                    </div>

                    <div>
                        <label htmlFor="reassign-reason" className="text-foreground mb-1.5 block text-sm font-medium">
                            Motivo <span className="text-destructive">*</span>
                        </label>
                        <select
                            id="reassign-reason"
                            value={reasonKey}
                            onChange={(e) => setReasonKey(e.target.value)}
                            disabled={submitting}
                            className="border-input focus:border-primary w-full rounded-md border px-3 py-2 text-sm focus:outline-none"
                        >
                            <option value="">— Seleccionar motivo —</option>
                            {REASONS.map((r) => (
                                <option key={r.key} value={r.key}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {isOther && (
                        <div className="animate-fade-in">
                            <label htmlFor="reassign-detail" className="text-foreground mb-1.5 block text-sm font-medium">
                                Detalle <span className="text-destructive">*</span>
                            </label>
                            <textarea
                                id="reassign-detail"
                                value={detail}
                                onChange={(e) => setDetail(e.target.value)}
                                disabled={submitting}
                                rows={2}
                                placeholder="Describe el motivo..."
                                className="border-input focus:border-primary w-full resize-none rounded-md border px-3 py-2 text-sm focus:outline-none"
                            />
                        </div>
                    )}

                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={submitting}>
                        Cancelar
                    </Button>
                    <Button onClick={handleSubmit} disabled={!isValid || submitting || loading}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Reasignar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
