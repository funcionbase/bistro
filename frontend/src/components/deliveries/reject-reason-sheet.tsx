import { BottomSheet } from '@/components/ui/bottom-sheet';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { Delivery } from '@/types';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RejectReasonSheetProps {
    delivery: Delivery | null;
    onClose: () => void;
    /** Submit del motivo. El parent llama al backend y muestra error/éxito. */
    onSubmit: (reason: string, delivery: Delivery) => Promise<void> | void;
    submitting?: boolean;
    error?: string | null;
}

/**
 * Bottom-sheet de motivo cuando el cliente rechaza la entrega.
 *
 * Razones rápidas preseleccionables (chips) + textarea opcional para
 * detalle adicional. El motivo se envía como string libre al backend
 * (`reason` en `PUT /deliveries/{id}/reject`) — el backend lo persiste
 * como `cancellation_reason` y deriva `status_change_reason='pedido_rechazado'`.
 */
export function RejectReasonSheet({ delivery, onClose, onSubmit, submitting = false, error = null }: RejectReasonSheetProps) {
    const [selected, setSelected] = useState<string>('');
    const [detail, setDetail] = useState('');

    useEffect(() => {
        if (delivery === null) {
            setSelected('');
            setDetail('');
        }
    }, [delivery]);

    if (delivery === null) {
        return null;
    }

    const finalReason = [selected, detail.trim()].filter(Boolean).join(' — ');
    const canSubmit = finalReason !== '' && !submitting;

    async function handleSubmit() {
        if (!canSubmit || delivery === null) return;
        await onSubmit(finalReason, delivery);
    }

    return (
        <BottomSheet isOpen={delivery !== null} onClose={onClose} title={`Rechazar orden #${delivery.order_id}`}>
            <div className="flex flex-col gap-4">
                <p className="text-muted-foreground text-sm">
                    Esto cancela el domicilio y la orden. Si ya hay cobro registrado, pídele a un admin que haga la devolución antes.
                </p>

                <div className="space-y-2">
                    <Label>Motivo rápido</Label>
                    <div className="flex flex-wrap gap-2">
                        {QUICK_REASONS.map((reason) => (
                            <button
                                key={reason}
                                type="button"
                                onClick={() => setSelected(reason)}
                                className={
                                    selected === reason
                                        ? 'bg-primary text-primary-foreground rounded-full px-3 py-1.5 text-xs font-medium'
                                        : 'border-border bg-card text-foreground hover:bg-muted rounded-full border px-3 py-1.5 text-xs font-medium'
                                }
                            >
                                {reason}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="reject-detail">Detalle (opcional)</Label>
                    <textarea
                        id="reject-detail"
                        value={detail}
                        onChange={(e) => setDetail(sanitizePlainText(e.target.value, 255, true, false))}
                        placeholder="Información adicional para el admin"
                        disabled={submitting}
                        rows={3}
                        maxLength={255}
                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                </div>

                {error && (
                    <p className="text-sm text-[color:var(--color-status-critical)]" role="alert">
                        {error}
                    </p>
                )}

                <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <Button variant="outline" onClick={onClose} disabled={submitting} className="sm:order-1">
                        Cancelar
                    </Button>
                    <Button onClick={handleSubmit} disabled={!canSubmit} className="sm:order-2">
                        {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                        Confirmar rechazo
                    </Button>
                </div>
            </div>
        </BottomSheet>
    );
}

const QUICK_REASONS = ['Cliente no estaba', 'Dirección errada', 'Pedido rechazado al recibir', 'Cliente no pagó'];
