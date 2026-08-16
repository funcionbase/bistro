import { BottomSheet } from '@/components/ui/bottom-sheet';
import { Button } from '@/components/ui/button';
import type { Delivery } from '@/types';
import { AlertTriangle, Undo2, X } from 'lucide-react';

export type DeliveryActionId = 'revert' | 'reject';

interface DeliveryActionSheetProps {
    delivery: Delivery | null;
    onClose: () => void;
    onAction: (action: DeliveryActionId, delivery: Delivery) => void;
}

/**
 * Bottom-sheet con acciones secundarias del delivery.
 *
 * Se abre desde el icono "..." del `MyDeliveryCard`. Las opciones varían
 * según el estado del delivery:
 *  - `completed`: solo "Marqué por error" (revert), con copy claro de que
 *    si la orden ya tiene cobro registrado el backend bloquea.
 *  - `pending`: "Cliente rechazó" (reject).
 *
 * Cuando hay otras razones (delivery `cancelled`), no se renderiza
 * ninguna acción mutadora — solo cierre.
 */
export function DeliveryActionSheet({ delivery, onClose, onAction }: DeliveryActionSheetProps) {
    if (delivery === null) {
        return null;
    }

    const canRevert = delivery.status === 'completed';
    const canReject = delivery.status === 'pending';

    return (
        <BottomSheet isOpen={delivery !== null} onClose={onClose} title={`Orden #${delivery.order_id}`}>
            <div className="flex flex-col gap-2">
                {canRevert && (
                    <Button variant="outline" size="lg" className="justify-start" onClick={() => onAction('revert', delivery)}>
                        <Undo2 className="mr-2 h-4 w-4" aria-hidden="true" />
                        <span className="text-left">
                            Marqué entregada por error
                            <span className="text-muted-foreground block text-xs font-normal">
                                Vuelve a "en tránsito". Bloqueado si ya hay cobro registrado.
                            </span>
                        </span>
                    </Button>
                )}
                {canReject && (
                    <Button variant="outline" size="lg" className="justify-start" onClick={() => onAction('reject', delivery)}>
                        <X className="mr-2 h-4 w-4" aria-hidden="true" />
                        <span className="text-left">
                            El cliente rechazó la entrega
                            <span className="text-muted-foreground block text-xs font-normal">
                                Cancela la orden. Si ya hay cobro, pídele a un admin que haga la devolución.
                            </span>
                        </span>
                    </Button>
                )}
                {!canRevert && !canReject && (
                    <p className="bg-muted text-muted-foreground rounded-md px-3 py-3 text-sm">
                        <AlertTriangle className="mr-2 inline h-4 w-4 align-text-bottom" aria-hidden="true" />
                        No hay acciones disponibles para una entrega en este estado.
                    </p>
                )}
            </div>
        </BottomSheet>
    );
}
