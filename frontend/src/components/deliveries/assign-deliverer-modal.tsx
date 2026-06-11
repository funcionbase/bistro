import { DelivererPickerList } from '@/components/deliveries/deliverer-picker-list';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AvailableDeliverer } from '@/types';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';

interface AssignDelivererModalProps {
    orderId?: string;
    deliverers: AvailableDeliverer[];
    loadingDeliverers: boolean;
    onAssign: (userId: string, reason: string) => Promise<void>;
    onClose: () => void;
    submitting: boolean;
}

/**
 * Modal para asignar un repartidor a una orden de delivery sin courier.
 * Permite elegir de la lista de repartidores disponibles y un motivo
 * opcional. Extraído de la página de ventas del día para limpiarla.
 */
export function AssignDelivererModal({ orderId, deliverers, loadingDeliverers, onAssign, onClose, submitting }: AssignDelivererModalProps) {
    const [selectedUserId, setSelectedUserId] = useState<string | null>(null);
    const [reason, setReason] = useState('');

    return (
        <BottomSheetDialog isOpen={orderId !== undefined} onClose={onClose} title={`Asignar repartidor — Orden #${orderId}`}>
            <div className="space-y-4 py-2">
                <DelivererPickerList
                    deliverers={deliverers}
                    selectedId={selectedUserId}
                    onSelect={setSelectedUserId}
                    loading={loadingDeliverers}
                    disabled={submitting}
                />
                <Input type="text" placeholder="Motivo (opcional)" value={reason} onChange={(e) => setReason(e.target.value)} />
                <div className="flex gap-2 pt-2">
                    <Button variant="outline" onClick={onClose} disabled={submitting} className="flex-1">
                        Cancelar
                    </Button>
                    <Button
                        onClick={() => selectedUserId && onAssign(selectedUserId, reason)}
                        disabled={!selectedUserId || submitting}
                        className="flex-1"
                    >
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Asignar
                    </Button>
                </div>
            </div>
        </BottomSheetDialog>
    );
}
