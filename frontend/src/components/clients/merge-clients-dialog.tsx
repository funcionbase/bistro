import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { ClientListItem } from '@/hooks/use-clients';
import { apiFetch } from '@/lib/api';
import { LoaderCircle, Merge } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MergeClientsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Contactos seleccionados en la lista (≥2). */
    clients: ClientListItem[];
    /** Se llama tras fusionar OK (el caller refresca y limpia la selección). */
    onMerged: () => void;
}

function formatPhone(phone: string | null): string {
    if (!phone) return '—';
    if (phone.startsWith('57') && phone.length === 12) {
        return `+57 ${phone.slice(2, 5)} ${phone.slice(5, 8)} ${phone.slice(8)}`;
    }
    return phone;
}

/**
 * Unifica contactos duplicados: el usuario elige cuál sobrevive (principal) y
 * el resto se absorbe — pedidos, chats, notas y etiquetas pasan al principal,
 * los duplicados se eliminan. POST /api/v1/clients/{principal}/merge.
 */
export function MergeClientsDialog({ open, onOpenChange, clients, onMerged }: MergeClientsDialogProps) {
    const [principalId, setPrincipalId] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Preselección: identidad canónica es el documento → gana quien lo tiene;
    // a igualdad, quien acumula más pedidos.
    useEffect(() => {
        if (!open) return;
        const best = [...clients].sort(
            (a, b) => Number(!!b.doc_number) - Number(!!a.doc_number) || b.total_orders - a.total_orders,
        )[0];
        setPrincipalId(best?.id ?? null);
        setError(null);
    }, [open, clients]);

    async function submit() {
        if (!principalId || submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            const response = await apiFetch(`/api/v1/clients/${principalId}/merge`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    merge_ids: clients.filter((c) => c.id !== principalId).map((c) => c.id),
                }),
            });
            if (response.ok) {
                onOpenChange(false);
                onMerged();
                return;
            }
            if (response.status === 403) {
                setError('No tienes permiso para unificar contactos.');
            } else {
                const body = (await response.json().catch(() => null)) as { message?: string } | null;
                setError(body?.message ?? 'No se pudo unificar. Intenta de nuevo.');
            }
        } catch {
            setError('Error de red. Intenta de nuevo.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !submitting && onOpenChange(v)}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Merge className="h-5 w-5" />
                        Unificar {clients.length} contactos
                    </DialogTitle>
                    <DialogDescription>
                        Elige el contacto principal. Los pedidos, chats, notas y etiquetas de los demás pasan al principal y los duplicados se
                        eliminan. Esta acción no se puede deshacer.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-2" role="radiogroup" aria-label="Contacto principal">
                    {clients.map((client) => (
                        <label
                            key={client.id}
                            className={`flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors ${
                                principalId === client.id ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/50'
                            }`}
                        >
                            <input
                                type="radio"
                                name="merge-principal"
                                className="accent-primary mt-1"
                                checked={principalId === client.id}
                                onChange={() => setPrincipalId(client.id)}
                                disabled={submitting}
                            />
                            <span className="flex min-w-0 flex-col text-sm">
                                <span className="font-medium">{client.name || 'Sin nombre'}</span>
                                <span className="text-muted-foreground text-xs">
                                    {client.doc_number ? `${client.doc_type ?? 'DOC'} ${client.doc_number}` : 'Sin documento'} ·{' '}
                                    <span className="font-mono">{formatPhone(client.phone)}</span> · {client.total_orders} pedidos
                                </span>
                                {principalId === client.id && <span className="text-primary text-xs font-medium">Principal — sobrevive</span>}
                            </span>
                        </label>
                    ))}
                </div>

                {error && <p className="text-destructive text-sm">{error}</p>}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                        Cancelar
                    </Button>
                    <Button onClick={() => void submit()} disabled={submitting || !principalId}>
                        {submitting ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <Merge className="mr-1 h-4 w-4" />}
                        Unificar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
