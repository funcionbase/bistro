import InvoiceStatusBadge from '@/components/billing/invoice-status-badge';
import InvoiceTypeChip from '@/components/billing/invoice-type-chip';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { formatCOP, formatDate, formatInvoicePeriod } from '@/lib/formatters';
import type { Invoice } from '@/types';
import { Download, LoaderCircle } from 'lucide-react';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    invoice: Invoice | null;
    onDownloadPdf: (invoiceId: string) => Promise<void>;
    isDownloadingPdf: boolean;
}

export default function InvoiceDetailModal({ isOpen, onClose, invoice, onDownloadPdf, isDownloadingPdf }: Props) {
    if (!invoice) return null;

    const payment = invoice.payments?.[0] ?? null;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="gap-0 p-0 sm:max-w-lg">
                {/* Header */}
                <div className="border-border flex items-center gap-2 border-b px-6 py-4 pr-12">
                    <InvoiceTypeChip type={invoice.type} />
                    <DialogTitle className="text-foreground text-sm font-semibold">Factura #{invoice.id}</DialogTitle>
                </div>

                {/* Body */}
                <div className="space-y-4 px-6 py-5">
                    {invoice.subscription?.plan && (
                        <div>
                            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Plan</p>
                            <p className="text-foreground text-sm font-semibold">{invoice.subscription.plan.name}</p>
                        </div>
                    )}

                    <div>
                        <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Período</p>
                        <p className="text-foreground text-sm">
                            {formatInvoicePeriod(invoice)} <span className="text-muted-foreground">— {invoice.days_billed} días</span>
                        </p>
                    </div>

                    <div>
                        <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">Desglose</p>
                        {invoice.discount_percent ? (
                            <div className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Precio base</span>
                                    <span>$ {formatCOP(invoice.base_amount)}</span>
                                </div>
                                <div className="flex justify-between text-[color:var(--color-status-success)]">
                                    <span>Descuento ({invoice.discount_percent}%)</span>
                                    <span>− $ {formatCOP(invoice.discount_amount ?? 0)}</span>
                                </div>
                                <div className="border-border text-foreground flex justify-between border-t pt-1 font-bold">
                                    <span>Total</span>
                                    <span>
                                        $ {formatCOP(invoice.amount)} {invoice.currency}
                                    </span>
                                </div>
                            </div>
                        ) : (
                            <div className="text-foreground flex justify-between text-sm font-bold">
                                <span>Total</span>
                                <span>
                                    $ {formatCOP(invoice.amount)} {invoice.currency}
                                </span>
                            </div>
                        )}
                    </div>

                    <div>
                        <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Vencimiento</p>
                        <p className="text-foreground text-sm">{formatDate(invoice.due_date)}</p>
                    </div>

                    <div>
                        <p className="text-muted-foreground mb-1 text-xs font-semibold tracking-wide uppercase">Estado</p>
                        <div className="flex items-center gap-2">
                            <InvoiceStatusBadge status={invoice.status} />
                            {invoice.status === 'paid' && payment && (
                                <span className="text-muted-foreground text-xs">
                                    {formatDate(payment.payment_date)} — Ref: {payment.payment_reference}
                                </span>
                            )}
                            {invoice.status === 'overdue' && (
                                <span className="text-xs text-[color:var(--color-status-critical)]">Vencida el {formatDate(invoice.due_date)}</span>
                            )}
                            {invoice.status === 'pending' && (
                                <span className="text-xs text-[color:var(--color-status-warning)]">Pago pendiente</span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="border-border flex justify-end border-t px-6 py-4">
                    <Button size="sm" variant="outline" disabled={isDownloadingPdf} onClick={() => onDownloadPdf(invoice.id)} className="gap-2">
                        {isDownloadingPdf ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                        Descargar PDF
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
