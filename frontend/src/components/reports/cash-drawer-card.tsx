import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import type { PaymentReceiptMethod } from '@/types';
import { Banknote, CreditCard, Download, QrCode, RefreshCw, Undo2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Cierre de caja — muestra cobros, devoluciones y propinas por método de pago.
 * Las fechas se controlan desde el filtro principal de la página (dateFrom/dateTo).
 */

interface MethodTotals {
    gross: number;
    refunds: number;
    net: number;
    tips: number;
    count: number;
}

interface CashDrawerSummary {
    by_method: Record<PaymentReceiptMethod, MethodTotals>;
    totals: { gross: number; refunds: number; net: number; tips: number };
    cash_drawer_expected: number;
    cash_opening_amount: number;
    cash_expenses_total: number;
    cash_incomes_total: number;
    cash_incomes_by_category: Record<string, number>;
    orders_count: number;
}

interface CashDrawerResponse {
    period: { from: string; to: string; timezone: string };
    summary: CashDrawerSummary;
}

const METHOD_ICONS: Record<PaymentReceiptMethod, typeof Banknote> = {
    cash: Banknote,
    card: CreditCard,
    transfer: QrCode,
    refund: Undo2,
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

export default function CashDrawerCard({
    branchFilter = 'active',
    dateFrom,
    dateTo,
}: {
    branchFilter?: string;
    dateFrom: string;
    dateTo: string;
}) {
    const token = useToken();
    const paymentCatalog = usePaymentMethods();

    const [data, setData] = useState<CashDrawerResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!token || !dateFrom || !dateTo) return;
        setLoading(true);
        setError(null);
        try {
            const qs = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
            if (branchFilter !== 'active') qs.set('branch', branchFilter);
            const res = await apiFetch(`/api/v1/reports/cash-drawer?${qs.toString()}`);
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo cargar el cierre de caja.');
                return;
            }
            setData(json as CashDrawerResponse);
        } catch {
            setError('Error de conexión.');
        } finally {
            setLoading(false);
        }
    }, [dateFrom, dateTo, token, branchFilter]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    const exportPdf = () => {
        const qs = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
        if (token) qs.set('token', token);
        if (branchFilter !== 'active') qs.set('branch', branchFilter);
        window.open(`/api/v1/reports/cash-drawer/pdf?${qs.toString()}`, '_blank');
    };

    const summary = data?.summary;

    return (
        <DashboardPanel
            title="Cierre de caja"
            rightSlot={
                <>
                    <Button variant="outline" size="sm" onClick={() => void fetchData()} disabled={loading}>
                        <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                        Actualizar
                    </Button>
                    <Button size="sm" onClick={exportPdf} disabled={!summary || loading}>
                        <Download className="mr-1.5 h-3.5 w-3.5" />
                        Exportar PDF
                    </Button>
                </>
            }
        >
            <p className="text-muted-foreground mb-4 text-xs">
                Cobros, devoluciones y propinas por método de pago. Aplica sobre la fecha de cobro (paid_at) en zona horaria America/Bogota.
            </p>

            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {loading && !summary && (
                <div className="space-y-2">
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-full" />
                </div>
            )}

            {summary && (
                <div className="space-y-4">
                    <div className="bg-card overflow-hidden rounded-lg border">
                        <Table bare>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Método</TableHead>
                                    <TableHead className="text-right">Cobros</TableHead>
                                    <TableHead className="text-right">Devoluciones</TableHead>
                                    <TableHead className="text-right">Neto</TableHead>
                                    <TableHead className="text-right">Propinas</TableHead>
                                    <TableHead className="text-right">N°</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {paymentCatalog.receipt_methods.map((key) => {
                                    const row = summary.by_method[key];
                                    if (!row) return null;
                                    const Icon = METHOD_ICONS[key];
                                    const visible = row.count > 0 || row.gross > 0 || row.refunds > 0 || row.tips > 0;
                                    if (!visible) return null;
                                    return (
                                        <TableRow key={key}>
                                            <TableCell>
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Icon className="text-muted-foreground h-3.5 w-3.5" />
                                                    {paymentCatalog.labels[key]}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(row.gross)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(row.refunds)}</TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">{formatCurrency(row.net)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{formatCurrency(row.tips)}</TableCell>
                                            <TableCell className="text-muted-foreground text-right">{row.count}</TableCell>
                                        </TableRow>
                                    );
                                })}
                                {summary.orders_count === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground text-center text-xs">
                                            Sin movimientos en el período seleccionado.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                            <tfoot className="bg-muted/60">
                                <tr className="border-t font-bold">
                                    <td className="px-4 py-3">Total general</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(summary.totals.gross)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(summary.totals.refunds)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(summary.totals.net)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(summary.totals.tips)}</td>
                                    <td className="text-muted-foreground px-4 py-3 text-right">{summary.orders_count}</td>
                                </tr>
                            </tfoot>
                        </Table>
                    </div>

                    <div className="rounded-lg border-2 border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 p-3">
                        <div className="text-xs tracking-wide text-[color:var(--color-status-safe)] uppercase">
                            Efectivo esperado en caja física
                        </div>
                        <div className="text-foreground text-2xl font-bold tabular-nums">{formatCurrency(summary.cash_drawer_expected)}</div>
                        <p className="text-muted-foreground mt-1 text-xs">
                            = Saldo inicial {formatCurrency(summary.cash_opening_amount)} + Cobros en efectivo{' '}
                            {formatCurrency(summary.by_method.cash.gross)} + Propinas en efectivo{' '}
                            {formatCurrency(summary.by_method.cash.tips)}
                            {summary.cash_incomes_total > 0 && (
                                <> + Entradas {formatCurrency(summary.cash_incomes_total)}</>
                            )}{' '}
                            − Devoluciones en efectivo {formatCurrency(summary.by_method.cash.refunds)}
                            {summary.cash_expenses_total > 0 && (
                                <> − Egresos {formatCurrency(summary.cash_expenses_total)}</>
                            )}
                        </p>
                    </div>

                    <p className="text-muted-foreground text-xs">
                        Las propinas son del personal y NO forman parte del ingreso de la empresa. El "Neto" es el ingreso real (cobros −
                        devoluciones).
                    </p>
                </div>
            )}
        </DashboardPanel>
    );
}
