import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency, formatDate, maskPhoneNumber } from '@/lib/coupon-helpers';
import type { CouponRedemption } from '@/types/coupon';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface RedemptionHistoryTableProps {
    redemptions: CouponRedemption[];
    loading?: boolean;
    page: number;
    totalPages: number;
    total: number;
    onPageChange: (page: number) => void;
}

export function RedemptionHistoryTable({ redemptions, loading = false, page, totalPages, total, onPageChange }: RedemptionHistoryTableProps) {
    return (
        <div className="bg-card rounded-lg border shadow-sm">
            <div className="border-border flex items-center justify-between border-b px-6 py-4">
                <h2 className="text-foreground font-semibold">
                    Historial de redenciones
                    <span className="bg-muted text-muted-foreground ml-2 rounded-full px-2 py-0.5 text-xs">{total}</span>
                </h2>
            </div>

            {loading ? (
                <div className="space-y-3 p-6">
                    {Array.from({ length: 5 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full" />
                    ))}
                </div>
            ) : redemptions.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-center">
                    <p className="text-muted-foreground text-sm">Aún no hay redenciones para este cupón</p>
                </div>
            ) : (
                <>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 border-border text-foreground border-b text-xs uppercase">
                                    <th className="px-4 py-3 text-left font-semibold">Fecha</th>
                                    <th className="px-4 py-3 text-left font-semibold">Cliente</th>
                                    <th className="px-4 py-3 text-left font-semibold">Orden #</th>
                                    <th className="px-4 py-3 text-left font-semibold">Descuento</th>
                                    <th className="px-4 py-3 text-left font-semibold">Total antes</th>
                                    <th className="px-4 py-3 text-left font-semibold">Total después</th>
                                </tr>
                            </thead>
                            <tbody>
                                {redemptions.map((r) => (
                                    <tr key={r.id} className="hover:bg-muted/40 border-t transition-colors">
                                        <td className="text-muted-foreground px-4 py-3">{formatDate(r.created_at)}</td>
                                        <td className="text-muted-foreground px-4 py-3 font-mono text-xs">{maskPhoneNumber(r.client_phone)}</td>
                                        <td className="text-muted-foreground px-4 py-3 font-mono text-xs">#{r.order_id}</td>
                                        <td className="px-4 py-3 font-semibold text-[color:var(--color-status-safe)] tabular-nums">
                                            −{formatCurrency(r.discount_amount)}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3 text-xs tabular-nums">
                                            {formatCurrency(r.order_total_before)}
                                        </td>
                                        <td className="text-foreground px-4 py-3 text-xs font-medium tabular-nums">
                                            {formatCurrency(r.order_total_after)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {totalPages > 1 && (
                        <div className="border-border flex items-center justify-center gap-4 border-t px-6 py-4">
                            <Button variant="outline" size="sm" onClick={() => onPageChange(page - 1)} disabled={page <= 1}>
                                <ChevronLeft className="mr-1 h-3.5 w-3.5" />
                                Anterior
                            </Button>
                            <span className="text-muted-foreground text-xs">
                                Página {page} de {totalPages}
                            </span>
                            <Button variant="outline" size="sm" onClick={() => onPageChange(page + 1)} disabled={page >= totalPages}>
                                Siguiente
                                <ChevronRight className="ml-1 h-3.5 w-3.5" />
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
