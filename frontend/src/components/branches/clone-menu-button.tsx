import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apiFetch } from '@/lib/api';
import type { Branch } from '@/types';
import { Copy, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

interface CloneMenuButtonProps {
    /** Sedes accesibles para el usuario actual (de SharedData). */
    branches: Branch[];
    /** Sede activa actual. Se excluye del dropdown — no tiene sentido clonar sobre sí misma. */
    currentBranchId: string;
    /**
     * Permiso del actor para copiar menús entre sedes (`branches.copy_menu`
     * o ser owner). Si false, el componente no renderiza.
     */
    canCopy: boolean;
    /** Callback opcional tras éxito (refresh de la lista de menús). */
    onCopied?: () => void;
}

/**
 * CTA "Clonar menú de otra sede" para sedes nuevas sin menú (#192 Fase 3.4).
 *
 * Solo aparece cuando:
 *  - El usuario tiene ≥1 sede destino distinta a la actual.
 *  - El usuario tiene permiso `branches.copy_menu` (owner-bypass aplica).
 *
 * Backend: POST /api/v1/company/branches/{branch}/menu/copy con
 * `source_branch_id`. La copia es deep (categorías + items); tras la copia
 * los menús son independientes (sin vínculo persistido).
 */
export function CloneMenuButton({ branches, currentBranchId, canCopy, onCopied }: CloneMenuButtonProps) {
    const candidates = branches.filter((b) => b.id !== currentBranchId);
    const [open, setOpen] = useState(false);
    const [sourceId, setSourceId] = useState<string>('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!canCopy || candidates.length === 0) {
        return null;
    }

    async function handleConfirm() {
        if (sourceId === '') {
            setError('Selecciona la sede origen.');
            return;
        }
        setError(null);
        setLoading(true);
        try {
            const response = await apiFetch(`/api/v1/company/branches/${currentBranchId}/menu/copy`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ source_branch_id: sourceId }),
            });
            if (!response.ok) {
                let message = 'No fue posible clonar el menú.';
                try {
                    const body = await response.clone().json();
                    if (body?.message) {
                        message = body.message;
                    }
                } catch {
                    // dejar caer.
                }
                setError(message);
                return;
            }
            setOpen(false);
            onCopied?.();
        } finally {
            setLoading(false);
        }
    }

    return (
        <>
            <Button variant="outline" size="lg" onClick={() => setOpen(true)}>
                <Copy className="mr-1 h-4 w-4" /> Clonar de otra sede
            </Button>
            <Dialog open={open} onOpenChange={(o) => !loading && setOpen(o)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Clonar menú de otra sede</DialogTitle>
                        <DialogDescription>
                            Copia las categorías y platos de otra sede como punto de partida. Después de clonar, los menús quedan independientes —
                            cualquier cambio aquí no afecta al original.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 py-2">
                        <Select value={sourceId} onValueChange={setSourceId} disabled={loading}>
                            <SelectTrigger aria-label="Sede origen">
                                <SelectValue placeholder="Selecciona la sede origen" />
                            </SelectTrigger>
                            <SelectContent>
                                {candidates.map((branch) => (
                                    <SelectItem key={branch.id} value={branch.id}>
                                        {branch.name}
                                        {branch.is_default ? ' (default)' : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {error && (
                            <p className="text-sm text-[color:var(--color-status-critical)]" role="alert">
                                {error}
                            </p>
                        )}
                    </div>
                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setOpen(false)} disabled={loading}>
                            Cancelar
                        </Button>
                        <Button onClick={handleConfirm} disabled={loading || sourceId === ''}>
                            {loading ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <Copy className="mr-1 h-4 w-4" />}
                            Clonar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
