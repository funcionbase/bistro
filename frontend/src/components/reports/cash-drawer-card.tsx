import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import type { PaymentReceiptMethod } from '@/types';
import { Banknote, ChevronLeft, ChevronRight, CreditCard, Download, QrCode, RefreshCw, Undo2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Cierre de caja — consultable día por día (default) o por rango.
 *
 * Modo "día específico" (default):
 *   - Un único date picker prominente.
 *   - Flechas ◀ ▶ para navegar al día anterior / siguiente sin salir del modo.
 *   - Atajos rápidos "Hoy" y "Ayer".
 *
 * Modo "rango": permite ver agregados de varios días (cierres semanales, mensuales).
 *
 * Filtra por `payment_receipts.paid_at` en TZ America/Bogota.
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

type Mode = 'day' | 'range';

const MODE_OPTIONS: ReadonlyArray<{ value: Mode; label: string }> = [
    { value: 'day', label: 'Día específico' },
    { value: 'range', label: 'Rango' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

/** YYYY-MM-DD interpretado como fecha local CO sin conversión de TZ. */
function todayInBogota(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Bogota',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

/** Suma o resta días a un YYYY-MM-DD sin caer en problemas de TZ. */
function shiftDate(iso: string, deltaDays: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    dt.setUTCDate(dt.getUTCDate() + deltaDays);
    return dt.toISOString().slice(0, 10);
}

/** "Lunes 7 de mayo de 2026". */
function formatLongDate(iso: string): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d, 12)); // mediodía UTC para evitar drift de TZ
    return new Intl.DateTimeFormat('es-CO', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'America/Bogota',
    }).format(dt);
}

export default function CashDrawerCard() {
    const token = useToken();
    const paymentCatalog = usePaymentMethods();
    const today = useMemo(() => todayInBogota(), []);

    const [mode, setMode] = useState<Mode>('day');
    const [day, setDay] = useState<string>(today);
    const [from, setFrom] = useState<string>(today);
    const [to, setTo] = useState<string>(today);

    const [data, setData] = useState<CashDrawerResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Derivamos los params canónicos según el modo.
    const params = useMemo(() => {
        if (mode === 'day') return { from: day, to: day };
        return { from, to };
    }, [mode, day, from, to]);

    const fetchData = useCallback(async () => {
        if (!token) return;
        setLoading(true);
        setError(null);
        try {
            const qs = new URLSearchParams({ date_from: params.from, date_to: params.to });
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
    }, [params.from, params.to, token]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    const exportPdf = () => {
        const qs = new URLSearchParams({ date_from: params.from, date_to: params.to });
        if (token) qs.set('token', token);
        window.open(`/api/v1/reports/cash-drawer/pdf?${qs.toString()}`, '_blank');
    };

    function handleModeChange(next: Mode) {
        setMode(next);
        if (next === 'range') {
            setFrom(day);
            setTo(day);
        }
    }

    const summary = data?.summary;
    const isFutureDay = mode === 'day' && day > today;

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

            <div className="space-y-4">
                <PeriodTabs options={MODE_OPTIONS} value={mode} onChange={handleModeChange} />

                {mode === 'day' ? (
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setDay((d) => shiftDate(d, -1))}
                                aria-label="Día anterior"
                                title="Día anterior"
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <div className="flex flex-col">
                                <Label htmlFor="cd-day" className="text-xs">
                                    Fecha
                                </Label>
                                <Input
                                    id="cd-day"
                                    type="date"
                                    value={day}
                                    max={today}
                                    onChange={(e) => setDay(e.target.value || today)}
                                    className="h-9 w-44"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setDay((d) => (d >= today ? d : shiftDate(d, +1)))}
                                disabled={day >= today}
                                aria-label="Día siguiente"
                                title="Día siguiente"
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                            <div className="flex gap-1">
                                <Button type="button" variant={day === today ? 'default' : 'outline'} size="sm" onClick={() => setDay(today)}>
                                    Hoy
                                </Button>
                                <Button
                                    type="button"
                                    variant={day === shiftDate(today, -1) ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setDay(shiftDate(today, -1))}
                                >
                                    Ayer
                                </Button>
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs capitalize">{formatLongDate(day)}</p>
                        {isFutureDay && (
                            <p className="text-xs text-[color:var(--color-status-warning)]">
                                La fecha seleccionada es futura. Mostrando datos vacíos.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="cd-from" className="text-xs">
                                Desde
                            </Label>
                            <Input id="cd-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className="h-9 w-40" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="cd-to" className="text-xs">
                                Hasta
                            </Label>
                            <Input
                                id="cd-to"
                                type="date"
                                value={to}
                                min={from}
                                max={today}
                                onChange={(e) => setTo(e.target.value)}
                                className="h-9 w-40"
                            />
                        </div>
                        <div className="flex gap-1">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setFrom(today);
                                    setTo(today);
                                }}
                            >
                                Hoy
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setFrom(shiftDate(today, -6));
                                    setTo(today);
                                }}
                            >
                                Últimos 7 días
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setFrom(shiftDate(today, -29));
                                    setTo(today);
                                }}
                            >
                                Últimos 30 días
                            </Button>
                        </div>
                    </div>
                )}

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
                    <>
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
                                {formatCurrency(summary.by_method.cash.tips)} − Devoluciones en efectivo{' '}
                                {formatCurrency(summary.by_method.cash.refunds)}
                                {summary.cash_expenses_total > 0 && (
                                    <> − Egresos {formatCurrency(summary.cash_expenses_total)}</>
                                )}
                            </p>
                        </div>

                        <p className="text-muted-foreground text-xs">
                            Las propinas son del personal y NO forman parte del ingreso de la empresa. El "Neto" es el ingreso real (cobros −
                            devoluciones).
                        </p>
                    </>
                )}
            </div>
        </DashboardPanel>
    );
}
