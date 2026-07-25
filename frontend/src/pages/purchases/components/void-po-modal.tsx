import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    onConfirm: (reason: string) => Promise<void>;
    submitting: boolean;
    title?: string;
    description?: string;
    confirmLabel?: string;
    minLength?: number;
}

export function ReasonPromptModal({
    open,
    onClose,
    onConfirm,
    submitting,
    title = 'Anular orden',
    description = 'Esta acción crea una nota crédito y reversa el inventario. No es reversible.',
    confirmLabel = 'Anular y emitir nota crédito',
    minLength = 5,
}: Props) {
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) setReason('');
    }, [open]);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        await onConfirm(reason.trim());
    }

    const valid = reason.trim().length >= minLength;

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="reason">Motivo</Label>
                        <textarea
                            id="reason"
                            value={reason}
                            onChange={(e) => setReason(sanitizePlainText(e.target.value, 500, true, false))}
                            rows={4}
                            required
                            minLength={minLength}
                            maxLength={500}
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm shadow-sm"
                            placeholder="Describe el motivo (mínimo 5 caracteres)…"
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="destructive" disabled={submitting || !valid}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
