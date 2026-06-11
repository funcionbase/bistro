import InvoiceDetailModal from '@/components/billing/invoice-detail-modal';
import InvoiceStatusBadge from '@/components/billing/invoice-status-badge';
import InvoiceTypeChip from '@/components/billing/invoice-type-chip';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { apiFetch } from '@/lib/api';
import { formatCOP, formatDate, formatInvoicePeriod } from '@/lib/formatters';
import type { Invoice, InvoicePagination } from '@/types';
import { Download, FileDown, LoaderCircle, Tag } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    token: string;
    companyNit: string;
}

export default function InvoiceList({ token, companyNit }: Props) {
    const [invoices, setInvoices] = useState<InvoicePagination | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [downloadingPdfId, setDownloadingPdfId] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!token || !companyNit) return;

        let isMounted = true;
        setIsLoading(true);
        setError(null);

        apiFetch(`/api/v1/billing/invoices?page=${page}`)
            .then((res) => res.json())
            .then((data) => {
                if (isMounted) setInvoices(data);
            })
            .catch(() => {
                if (isMounted) setError('Error al cargar el historial de facturas.');
            })
            .finally(() => {
                if (isMounted) setIsLoading(false);
            });

        return () => {
            isMounted = false;
        };
    }, [token, companyNit, page]);

    const handleViewDetail = async (invoice: Invoice) => {
        try {
            const res = await apiFetch(`/api/v1/billing/invoices/${invoice.id}`);
            if (!res.ok) return;
            const data = await res.json();
            setSelectedInvoice(data.invoice ?? invoice);
            setIsModalOpen(true);
        } catch {
            setSelectedInvoice(invoice);
            setIsModalOpen(true);
        }
    };

    const handleDownloadPdf = async (invoiceId: string) => {
        const win = window.open('', '_blank');
        setDownloadingPdfId(invoiceId);
        try {
            const res = await apiFetch(`/api/v1/billing/invoices/${invoiceId}/download`);
            if (!res.ok) {
                win?.close();
                const data = await res.json().catch(() => ({}));
                setError(data.message ?? 'Error al generar el PDF.');
                return;
            }
            const data = await res.json();
            if (win) win.location.href = data.url;
            else window.open(data.url, '_blank');
        } catch {
            win?.close();
            setError('Error de conexión al generar el PDF.');
        } finally {
            setDownloadingPdfId(null);
        }
    };

    return (
        <>
            <DashboardPanel
                title="Historial de facturas"
                rightSlot={
                    <ExportPdfButton
                        endpoint="/api/v1/exports/billing/pdf"
                        filters={{}}
                        filename={`facturas-${new Date().toISOString().slice(0, 10)}.pdf`}
                        disabled={isLoading}
                        label="Exportar PDF"
                    />
                }
            >
                <div className="space-y-3">
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    {isLoading ? (
                        <div className="space-y-2">
                            {Array.from({ length: 3 }).map((_, i) => (
                                <Skeleton key={i} className="h-10 w-full rounded-md" />
                            ))}
                        </div>
                    ) : invoices && invoices.data.length > 0 ? (
                        <div className="bg-card overflow-hidden rounded-lg border">
                            <Table bare>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tipo</TableHead>
                                        <TableHead>Período</TableHead>
                                        <TableHead className="text-right">Días</TableHead>
                                        <TableHead className="text-right">Monto</TableHead>
                                        <TableHead>Vencimiento</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-center">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.data.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell>
                                                <InvoiceTypeChip type={invoice.type} />
                                            </TableCell>
                                            <TableCell className="text-foreground">{formatInvoicePeriod(invoice)}</TableCell>
                                            <TableCell className="text-muted-foreground text-right tabular-nums">{invoice.days_billed}</TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    <span className="text-foreground font-semibold tabular-nums">$ {formatCOP(invoice.amount)}</span>
                                                    {invoice.discount_percent ? (
                                                        <span
                                                            title={`Descuento del ${invoice.discount_percent}% aplicado`}
                                                            className="cursor-default text-[color:var(--color-status-safe)]"
                                                        >
                                                            <Tag className="h-3.5 w-3.5" />
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{formatDate(invoice.due_date)}</TableCell>
                                            <TableCell>
                                                <InvoiceStatusBadge status={invoice.status} />
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <div className="flex items-center justify-center gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 px-2 text-xs"
                                                        onClick={() => handleViewDetail(invoice)}
                                                    >
                                                        Ver detalle
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-7 w-7"
                                                        title="Descargar PDF"
                                                        disabled={downloadingPdfId === invoice.id}
                                                        onClick={() => handleDownloadPdf(invoice.id)}
                                                    >
                                                        {downloadingPdfId === invoice.id ? (
                                                            <LoaderCircle className="h-3.5 w-3.5 animate-spin" />
                                                        ) : (
                                                            <Download className="h-3.5 w-3.5" />
                                                        )}
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <FileDown className="text-muted-foreground/40 mb-3 h-10 w-10" />
                            <p className="text-muted-foreground text-sm font-medium">Aún no tienes facturas registradas.</p>
                        </div>
                    )}

                    {invoices && invoices.last_page > 1 && (
                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                            <span>
                                Página {invoices.current_page} de {invoices.last_page} — {invoices.total} facturas
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-7 text-xs"
                                    disabled={page <= 1}
                                    onClick={() => setPage((p) => p - 1)}
                                >
                                    Anterior
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-7 text-xs"
                                    disabled={page >= invoices.last_page}
                                    onClick={() => setPage((p) => p + 1)}
                                >
                                    Siguiente
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </DashboardPanel>

            <InvoiceDetailModal
                isOpen={isModalOpen}
                onClose={() => {
                    setIsModalOpen(false);
                    setSelectedInvoice(null);
                }}
                invoice={selectedInvoice}
                onDownloadPdf={handleDownloadPdf}
                isDownloadingPdf={downloadingPdfId === selectedInvoice?.id}
            />
        </>
    );
}
