import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CheckCircle, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ConfirmCompleteModalProps {
    deliveryId: string;
    isOpen: boolean;
    onClose: () => void;
    onConfirm: (id: string) => Promise<void>;
}

export function ConfirmCompleteModal({ deliveryId, isOpen, onClose, onConfirm }: ConfirmCompleteModalProps) {
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleConfirm() {
        setSubmitting(true);
        setError(null);
        try {
            await onConfirm(deliveryId);
            onClose();
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : 'Error al completar la entrega.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <CheckCircle className="h-5 w-5 text-[color:var(--color-status-success)]" />
                        ¿Completar entrega #{deliveryId}?
                    </DialogTitle>
                </DialogHeader>

                <p className="text-muted-foreground text-sm">Esta acción marcará la entrega como completada y no podrá revertirse.</p>

                {error && <p className="text-sm text-[color:var(--color-status-critical)]">{error}</p>}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={submitting}>
                        Cancelar
                    </Button>
                    <Button onClick={handleConfirm} disabled={submitting}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Confirmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
