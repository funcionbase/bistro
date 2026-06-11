import SuspendedBlockedView from '@/components/billing/suspended-blocked-view';
import { PageShell } from '@/components/page-shell';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { BillingInvoicesSkeleton, BillingSubscriptionSkeleton } from '@/components/ui/billing-skeleton';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmptyState } from '@/components/ui/empty-state';
import { InvoiceStatusBadge } from '@/components/ui/invoice-status-badge';
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { apiFetch } from '@/lib/api';
import { companyStatusBadgeVariant, isFullyBlocked } from '@/lib/company-status';
import { useSharedData } from '@/lib/shared-data';
import type { Company, Invoice, Subscription } from '@/types';

import { AlertCircle, Download, FileDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface PaginatedInvoices {
    data: Invoice[];
    current_page: number;
    last_page: number;
    total: number;
}


function formatCOP(value: number): string {
    return new Intl.NumberFormat('es-CO', { minimumFractionDigits: 0 }).format(value);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'America/Bogota',
    });
}

function formatPeriod(from: string): string {
    const d = new Date(from + 'T00:00:00');
    const month = d.toLocaleString('es-CO', { month: 'long', year: 'numeric', timeZone: 'America/Bogota' });
    return month.charAt(0).toUpperCase() + month.slice(1);
}

/**
 * Badge contextual de facturación. Usa el variant del catálogo canónico
 * (#205 — `lib/company-status.ts`) pero override del label porque billing
 * añade contexto operativo ("período de gracia", "pago bloqueado") que no
 * aplica fuera de esta página.
 */
function CompanyStatusBadge({ status }: { status: string }) {
    let label: string | null = null;
    if (status === 'active') {
        label = 'Activa';
    } else if (status === 'past_due') {
        label = 'En mora — período de gracia';
    } else if (status === 'suspended') {
        label = 'Suspendida — pago bloqueado';
    }

    if (label === null) {
        return null;
    }

    return <Badge variant={companyStatusBadgeVariant(status)}>{label}</Badge>;
}

export default function BillingIndex() {
    const { activeCompany } = useSharedData();

    const [subscription, setSubscription] = useState<Subscription | null>(null);
    const [invoices, setInvoices] = useState<PaginatedInvoices | null>(null);
    const [overdueTotal, setOverdueTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [downloadingPdf, setDownloadingPdf] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const isMounted = useRef(true);

    const fetchData = async (currentPage = 1) => {
        try {
            const [subRes, invRes] = await Promise.all([
                apiFetch('/api/v1/billing/subscription'),
                apiFetch(`/api/v1/billing/invoices?page=${currentPage}`),
            ]);

            if (!isMounted.current) return;

            if (subRes.ok) {
                const subData = await subRes.json();
                setSubscription(subData.subscription ?? null);
                setOverdueTotal(Number(subData.overdue_total ?? 0));
            }

            if (invRes.ok) {
                const invData = await invRes.json();
                setInvoices(invData);
            } else if (invRes.status === 403) {
                setError('No tienes permiso para ver la facturación.');
            }
        } catch {
            if (!isMounted.current) return;
            setError('Error de conexión. Intenta recargar.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    };

    useEffect(() => {
        isMounted.current = true;

        // El JWT vive en cookie HttpOnly seteada por el backend; no hay que extraer de URL.
        fetchData(page);

        return () => {
            isMounted.current = false;
        };
    }, [page]);

    const handleDownloadPdf = async (invoiceId: string) => {
        const win = window.open('', '_blank');
        setDownloadingPdf(invoiceId);
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
            setDownloadingPdf(null);
        }
    };

    const isSuspended = activeCompany ? isFullyBlocked(activeCompany.status) : false;

    return (
        <PageShell title="Facturación">
            <div className="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Facturación"
                    title="Facturación"
                    description="Suscripción de la plataforma y facturas emitidas por flexyflow."
                    showBranchBadge={false}
                    actions={
                        !isSuspended && (
                            <ExportPdfButton
                                endpoint="/api/v1/exports/billing/pdf"
                                filters={{}}
                                filename={`facturas-${new Date().toISOString().slice(0, 10)}.pdf`}
                                disabled={loading}
                                label="Exportar PDF"
                            />
                        )
                    }
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {isSuspended && activeCompany && <SuspendedBlockedView activeCompany={activeCompany} overdueTotal={overdueTotal} />}

                {!isSuspended && (
                    <NormalBillingView
                        loading={loading}
                        subscription={subscription}
                        invoices={invoices}
                        activeCompany={activeCompany ?? null}
                        page={page}
                        setPage={setPage}
                        downloadingPdf={downloadingPdf}
                        handleDownloadPdf={handleDownloadPdf}
                    />
                )}
            </div>
        </PageShell>
    );
}

interface NormalBillingViewProps {
    loading: boolean;
    subscription: Subscription | null;
    invoices: PaginatedInvoices | null;
    activeCompany: Company | null;
    page: number;
    setPage: React.Dispatch<React.SetStateAction<number>>;
    downloadingPdf: string | null;
    handleDownloadPdf: (id: string) => void;
}

function NormalBillingView({
    loading,
    subscription,
    invoices,
    activeCompany,
    page,
    setPage,
    downloadingPdf,
    handleDownloadPdf,
}: NormalBillingViewProps) {
    return (
        <>
            {/* Suscripción activa */}
            {loading ? (
                <BillingSubscriptionSkeleton />
            ) : (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-foreground text-base font-semibold">Suscripción activa</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {subscription ? (
                            <div className="grid grid-cols-2 gap-4 sm:flex sm:flex-wrap sm:gap-8">
                                <div className="min-w-0">
                                    <p className="text-muted-foreground text-sm">Plan</p>
                                    <p className="text-foreground truncate text-lg font-bold md:text-xl">{subscription.plan.name}</p>
                                    <p className="text-muted-foreground text-xs capitalize">
                                        {subscription.plan.billing_cycle === 'monthly' ? 'Mensual' : subscription.plan.billing_cycle}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <p className="text-muted-foreground text-sm">Precio</p>
                                    <p className="text-primary text-lg font-bold tabular-nums md:text-xl">
                                        $ {formatCOP(subscription.plan.price)}{' '}
                                        <span className="text-muted-foreground text-sm font-normal">{subscription.plan.currency}</span>
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <p className="text-muted-foreground text-sm">Activa desde</p>
                                    <p className="text-foreground font-semibold tabular-nums">{formatDate(subscription.starts_at)}</p>
                                </div>
                                {activeCompany && (
                                    <div className="col-span-2 min-w-0 sm:col-span-1">
                                        <p className="text-muted-foreground text-sm">Estado cuenta</p>
                                        <div className="mt-0.5">
                                            <CompanyStatusBadge status={activeCompany.status} />
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                No tienes una suscripción activa. Contacta al soporte para activar tu plan.
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Historial de facturas */}
            {loading ? (
                <BillingInvoicesSkeleton />
            ) : (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-foreground text-base font-semibold">Historial de facturas</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices && invoices.data.length > 0 ? (
                            <>
                                {/* Mobile: card-stack */}
                                <DataCardList
                                    items={invoices.data}
                                    getKey={(invoice) => invoice.id}
                                    className="sm:hidden"
                                    renderCard={(invoice) => (
                                        <DataCard
                                            title={formatPeriod(invoice.period_from)}
                                            subtitle={invoice.type === 'monthly' ? 'Mensual' : 'Prorrateo'}
                                            fields={[
                                                {
                                                    label: 'Total',
                                                    value: <span className="font-semibold tabular-nums">$ {formatCOP(invoice.amount)}</span>,
                                                },
                                                { label: 'Vencimiento', value: formatDate(invoice.due_date) },
                                                {
                                                    label: 'Estado',
                                                    value: <InvoiceStatusBadge status={invoice.status} compact />,
                                                },
                                                {
                                                    label: 'Base',
                                                    value: <span className="tabular-nums">$ {formatCOP(invoice.base_amount)}</span>,
                                                },
                                            ]}
                                            footer={
                                                <Button
                                                    variant="ghost"
                                                    size="default"
                                                    className="ml-auto"
                                                    disabled={downloadingPdf === invoice.id}
                                                    onClick={() => handleDownloadPdf(invoice.id)}
                                                >
                                                    <Download className="mr-1.5 h-4 w-4" aria-hidden />
                                                    PDF
                                                </Button>
                                            }
                                        />
                                    )}
                                />

                                {/* Desktop: tabla densa */}
                                <div className="hidden sm:block">
                                    <Table bare>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Tipo</TableHead>
                                                <TableHead>Período</TableHead>
                                                <TableHead className="text-right">Precio base</TableHead>
                                                <TableHead className="text-right">Descuento</TableHead>
                                                <TableHead className="text-right">Total</TableHead>
                                                <TableHead>Vencimiento</TableHead>
                                                <TableHead>Estado</TableHead>
                                                <TableHead className="text-center">PDF</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {invoices.data.map((invoice) => (
                                                <TableRow key={invoice.id}>
                                                    <TableCell>
                                                        <Badge variant="secondary">{invoice.type === 'monthly' ? 'Mensual' : 'Prorrateo'}</Badge>
                                                    </TableCell>
                                                    <TableCell className="text-foreground text-sm font-medium">
                                                        {formatPeriod(invoice.period_from)}
                                                    </TableCell>
                                                    <TableCell className="text-right text-sm tabular-nums">
                                                        $ {formatCOP(invoice.base_amount)}
                                                    </TableCell>
                                                    <TableCell className="text-right text-sm text-[color:var(--color-status-safe)] tabular-nums">
                                                        {invoice.discount_percent ? (
                                                            <span>
                                                                {invoice.discount_percent}% (-$
                                                                {formatCOP(invoice.discount_amount ?? 0)})
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-foreground text-right text-sm font-bold tabular-nums">
                                                        $ {formatCOP(invoice.amount)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground text-sm tabular-nums">
                                                        {formatDate(invoice.due_date)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <InvoiceStatusBadge status={invoice.status} />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            title="Descargar PDF"
                                                            disabled={downloadingPdf === invoice.id}
                                                            onClick={() => handleDownloadPdf(invoice.id)}
                                                        >
                                                            <Download className="h-3.5 w-3.5" aria-hidden />
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Paginación */}
                                {invoices.last_page > 1 && (
                                    <div className="text-muted-foreground mt-4 flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                        <span className="tabular-nums">
                                            Página {invoices.current_page} de {invoices.last_page} — {invoices.total} facturas
                                        </span>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={page <= 1}
                                                onClick={() => setPage((p) => p - 1)}
                                                className="flex-1 sm:flex-initial"
                                            >
                                                Anterior
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={page >= invoices.last_page}
                                                onClick={() => setPage((p) => p + 1)}
                                                className="flex-1 sm:flex-initial"
                                            >
                                                Siguiente
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </>
                        ) : (
                            <EmptyState
                                icon={FileDown}
                                title="Aún no tienes facturas registradas"
                                description="Las facturas aparecerán acá apenas se generen tras un cobro."
                            />
                        )}
                    </CardContent>
                </Card>
            )}
        </>
    );
}
