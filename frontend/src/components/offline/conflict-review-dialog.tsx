/**
 * Pantalla de revisión de conflictos del sync offline (plan-off.md §7.5/§8).
 *
 * Lista las ops del outbox que el servidor marcó en `conflict` (cobro ya pagado,
 * dependencia fallida, caja ya cerrada, etc.) y deja al usuario decidir:
 *  - Reintentar: la causa se resolvió (p.ej. se reabrió la caja) → re-encola.
 *  - Descartar: el conflicto es definitivo → borra la op (no borra plata: el
 *    asiento contable ya vive — o no — en el servidor).
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { OutboxOp } from '@/lib/offline/db';
import { discardConflict, listConflicts, retryConflict } from '@/lib/offline/sync-engine';
import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/** Etiquetas legibles por tipo de op. */
const OP_LABELS: Record<string, string> = {
    'order.create': 'Crear orden',
    'order.close': 'Cobro de orden',
    'order.append': 'Agregar ítems',
    'order.status': 'Cambio de estado',
    'order.refund': 'Devolución',
    'cash.open': 'Apertura de caja',
    'cash.expense': 'Egreso de caja',
    'cash.income': 'Entrada de caja',
    'cash.close': 'Cierre de caja',
};

/** Mensajes por código de conflicto del servidor. */
const CONFLICT_MESSAGES: Record<string, string> = {
    already_paid: 'La orden ya fue cobrada en el servidor. No se duplica el cobro.',
    dependency_failed: 'No se encontró la orden asociada (su creación falló o no se sincronizó).',
    order_not_open: 'La orden ya estaba cerrada o cancelada en el servidor.',
    order_not_found: 'La orden no existe en el servidor.',
    session_already_open: 'Ya había una caja abierta en la sede; las ventas se imputan a esa sesión.',
    no_open_cash_session: 'No hay caja abierta para imputar la operación.',
    refund_exceeds: 'La devolución supera lo cobrado.',
    forbidden: 'Tu rol ya no tiene permiso para esta operación.',
    invalid_payment_method: 'Método de pago inválido.',
    no_active_menu: 'No hay menú activo para crear la orden.',
    item_not_found: 'Un ítem ya no existe en el menú activo.',
    unsupported_op_type: 'El servidor no reconoce esta operación. Reintenta tras actualizar la app.',
};

function describeConflict(op: OutboxOp): string {
    const code = op.conflict?.code ?? 'conflict';
    return CONFLICT_MESSAGES[code] ?? op.conflict?.message ?? code;
}

export function ConflictReviewDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [conflicts, setConflicts] = useState<OutboxOp[]>([]);
    const [busy, setBusy] = useState<string | null>(null);

    const load = useCallback(async () => {
        setConflicts(await listConflicts());
    }, []);

    useEffect(() => {
        if (open) void load();
    }, [open, load]);

    const handleRetry = async (opId: string) => {
        setBusy(opId);
        try {
            await retryConflict(opId);
            await load();
        } finally {
            setBusy(null);
        }
    };

    const handleDiscard = async (opId: string) => {
        setBusy(opId);
        try {
            await discardConflict(opId);
            await load();
        } finally {
            setBusy(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-[color:var(--color-status-critical)]" />
                        Conflictos de sincronización
                    </DialogTitle>
                </DialogHeader>
                {conflicts.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">No hay conflictos pendientes. Todo quedó conciliado.</p>
                ) : (
                    <ul className="max-h-[60vh] space-y-3 overflow-y-auto">
                        {conflicts.map((op) => (
                            <li key={op.op_id} className="border-border rounded-md border p-3 text-sm">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="font-medium">{OP_LABELS[op.type] ?? op.type}</div>
                                        <div className="text-muted-foreground text-xs">{describeConflict(op)}</div>
                                        <div className="text-muted-foreground mt-1 text-[11px]">
                                            {new Date(op.created_at_client).toLocaleString('es-CO', { timeZone: 'America/Bogota' })}
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-2 flex justify-end gap-2">
                                    <Button size="sm" variant="outline" disabled={busy === op.op_id} onClick={() => void handleRetry(op.op_id)}>
                                        Reintentar
                                    </Button>
                                    <Button size="sm" variant="destructive" disabled={busy === op.op_id} onClick={() => void handleDiscard(op.op_id)}>
                                        Descartar
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default ConflictReviewDialog;
