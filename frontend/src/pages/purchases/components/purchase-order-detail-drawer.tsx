import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DetailRow } from '@/components/ui/detail-row';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useToast } from '@/components/ui/toast';
import type { PurchaseOrderAttachment, PurchaseOrderDetail, PurchasePaymentMethod, PurchaseStatus } from '@/types/purchases';
import { PAYMENT_LABELS, STATUS_LABELS } from '@/types/purchases';
import { AlertTriangle, CheckCircle2, CreditCard, FileText, PackageCheck, Send, Slash, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { AttachmentsPanel } from './attachments-panel';
import { MarkPaidModal } from './mark-paid-modal';
import { ReasonPromptModal } from './void-po-modal';

const STATUS_VARIANT: Record<PurchaseStatus, NonNullable<BadgeProps['variant']>> = {
    draft: 'secondary',
    pending: 'warning',
    received: 'default',
    paid: 'safe',
    cancelled: 'outline',
    voided: 'critical',
};

function fmt(v: string | number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v));
}

interface Props {
    po: PurchaseOrderDetail | null;
    open: boolean;
    onClose: () => void;
    onEdit: (po: PurchaseOrderDetail) => void;
    onSubmit: (id: string) => Promise<PurchaseOrderDetail>;
    onReceive: (id: string) => Promise<PurchaseOrderDetail>;
    onPay: (id: string, body: { payment_method: PurchasePaymentMethod; payment_reference?: string }) => Promise<PurchaseOrderDetail>;
    onCancel: (id: string, reason?: string) => Promise<PurchaseOrderDetail>;
    onVoid: (id: string, reason: string) => Promise<PurchaseOrderDetail>;
    onSettleRefund: (id: string, reference?: string) => Promise<PurchaseOrderDetail>;
    onUpload: (id: string, file: File, type: string) => Promise<PurchaseOrderAttachment>;
    onDelete: (id: string, attachmentId: string) => Promise<void>;
    getAttachmentUrl: (id: string, attachmentId: string, disposition?: 'inline' | 'attachment') => Promise<string>;
    onChanged: (po: PurchaseOrderDetail) => void;
}

export function PurchaseOrderDetailDrawer({
    po,
    open,
    onClose,
    onEdit,
    onSubmit,
    onReceive,
    onPay,
    onCancel,
    onVoid,
    onSettleRefund,
    onUpload,
    onDelete,
    getAttachmentUrl,
    onChanged,
}: Props) {
    const { showToast } = useToast();
    const [busy, setBusy] = useState(false);
    const [showPay, setShowPay] = useState(false);
    const [showVoid, setShowVoid] = useState(false);
    const [showCancel, setShowCancel] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    if (!po) return null;

    function handleErr(err: unknown, fallback: string) {
        const apiErr = err as { errors?: Record<string, string[]>; message?: string };
        if (apiErr?.errors) setErrors(apiErr.errors);
        showToast('error', apiErr?.message ?? fallback);
    }

    async function run(action: () => Promise<PurchaseOrderDetail>, success: string) {
        setBusy(true);
        setErrors({});
        try {
            const fresh = await action();
            showToast('success', success);
            onChanged(fresh);
        } catch (err) {
            handleErr(err, 'No se pudo completar la acción.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Sheet open={open} onOpenChange={(v) => !v && onClose()}>
                <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-2xl">
                    <SheetHeader>
                        <SheetTitle className="flex items-center gap-2">
                            {po.code}
                            <Badge variant={STATUS_VARIANT[po.status]}>{STATUS_LABELS[po.status]}</Badge>
                            {po.pending_supplier_refund && (
                                <Badge variant="critical">
                                    <AlertTriangle className="mr-1 h-3 w-3" /> Reintegro pendiente
                                </Badge>
                            )}
                        </SheetTitle>
                    </SheetHeader>

                    <div className="space-y-6 py-4">
                        <DetailRow className="text-sm" label="Proveedor" value={po.supplier?.name ?? '—'} valueClassName="font-medium" />

                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <DetailRow label="Subtotal" value={fmt(po.subtotal)} valueClassName="tabular-nums" />
                            <DetailRow label="Impuestos" value={fmt(po.tax_amount)} valueClassName="tabular-nums" />
                            <DetailRow label="Total" value={fmt(po.total)} valueClassName="text-lg font-semibold tabular-nums" />
                            <DetailRow
                                label="Pago"
                                value={
                                    <>
                                        {po.payment_method ? `${PAYMENT_LABELS[po.payment_method]}` : '—'}
                                        {po.payment_reference && <span className="text-muted-foreground"> · {po.payment_reference}</span>}
                                    </>
                                }
                            />
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-xs">
                                    <tr className="text-left">
                                        <th className="px-2 py-1.5">Insumo</th>
                                        <th className="px-2 py-1.5">Cant.</th>
                                        <th className="px-2 py-1.5">P. unit.</th>
                                        <th className="px-2 py-1.5">IVA</th>
                                        <th className="px-2 py-1.5 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {po.items.map((i) => (
                                        <tr key={i.id} className="border-t">
                                            <td className="px-2 py-1.5">
                                                {i.description}
                                                {i.unit && <span className="text-muted-foreground ml-1 text-xs">({i.unit})</span>}
                                            </td>
                                            <td className="px-2 py-1.5 tabular-nums">{i.quantity}</td>
                                            <td className="px-2 py-1.5 tabular-nums">{fmt(i.unit_cost)}</td>
                                            <td className="px-2 py-1.5">{i.tax_rate}%</td>
                                            <td className="px-2 py-1.5 text-right tabular-nums">{fmt(i.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {po.credit_notes.length > 0 && (
                            <div className="space-y-2 rounded-lg border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 p-3 text-sm">
                                <div className="font-medium text-[color:var(--color-status-critical)]">Notas crédito emitidas</div>
                                {po.credit_notes.map((n) => (
                                    <div key={n.id} className="text-foreground/80 text-xs">
                                        <span className="font-mono">{n.code}</span> · {fmt(n.total_reversed)} · {n.reason}
                                    </div>
                                ))}
                            </div>
                        )}

                        <AttachmentsPanel
                            po={po}
                            onUpload={onUpload}
                            onDelete={onDelete}
                            getUrl={getAttachmentUrl}
                            onChange={async () => {
                                // re-fetch handled by parent via onChanged after detail refresh.
                                // Caller should re-open with updated PO; for simplicity skip here.
                            }}
                        />

                        <div className="flex flex-wrap gap-2 border-t pt-4">
                            {po.status === 'draft' && (
                                <>
                                    <Button variant="outline" onClick={() => onEdit(po)} disabled={busy}>
                                        <FileText className="mr-1 h-4 w-4" /> Editar
                                    </Button>
                                    <Button onClick={() => run(() => onSubmit(po.id), 'Orden confirmada.')} disabled={busy}>
                                        <Send className="mr-1 h-4 w-4" /> Confirmar
                                    </Button>
                                </>
                            )}
                            {po.status === 'pending' && (
                                <Button onClick={() => run(() => onReceive(po.id), 'Orden recibida — inventario actualizado.')} disabled={busy}>
                                    <PackageCheck className="mr-1 h-4 w-4" /> Recibir
                                </Button>
                            )}
                            {po.status === 'received' && (
                                <Button onClick={() => setShowPay(true)} disabled={busy}>
                                    <CreditCard className="mr-1 h-4 w-4" /> Registrar pago
                                </Button>
                            )}
                            {(po.status === 'received' || po.status === 'paid') && (
                                <Button variant="destructive" onClick={() => setShowVoid(true)} disabled={busy}>
                                    <Undo2 className="mr-1 h-4 w-4" /> Anular (NC)
                                </Button>
                            )}
                            {(po.status === 'draft' || po.status === 'pending') && (
                                <Button variant="outline" onClick={() => setShowCancel(true)} disabled={busy}>
                                    <Slash className="mr-1 h-4 w-4" /> Cancelar
                                </Button>
                            )}
                            {po.pending_supplier_refund && (
                                <Button
                                    variant="outline"
                                    onClick={() => run(() => onSettleRefund(po.id), 'Reintegro marcado como saldado.')}
                                    disabled={busy}
                                >
                                    <CheckCircle2 className="mr-1 h-4 w-4" /> Marcar reintegro saldado
                                </Button>
                            )}
                        </div>
                    </div>
                </SheetContent>
            </Sheet>

            <MarkPaidModal
                open={showPay}
                onClose={() => setShowPay(false)}
                onConfirm={async (method, ref) => {
                    await run(() => onPay(po.id, { payment_method: method, payment_reference: ref }), 'Pago registrado.');
                    setShowPay(false);
                }}
                submitting={busy}
                errors={errors}
            />

            <ReasonPromptModal
                open={showVoid}
                onClose={() => setShowVoid(false)}
                onConfirm={async (reason) => {
                    await run(() => onVoid(po.id, reason), 'Orden anulada — nota crédito emitida.');
                    setShowVoid(false);
                }}
                submitting={busy}
            />

            <ReasonPromptModal
                open={showCancel}
                onClose={() => setShowCancel(false)}
                title="Cancelar borrador"
                description="Cancelar la orden no afecta el inventario. Esta acción es irreversible."
                confirmLabel="Cancelar orden"
                minLength={0}
                onConfirm={async (reason) => {
                    await run(() => onCancel(po.id, reason), 'Orden cancelada.');
                    setShowCancel(false);
                }}
                submitting={busy}
            />
        </>
    );
}
