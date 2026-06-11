import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { type ActiveCompanyPromoCode } from '@/hooks/use-company-promo-code';
import { formatCOP, formatMonthYear } from '@/lib/formatters';
import { Gift, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ActivePromoCodeCardProps {
    active: ActiveCompanyPromoCode;
    canCancel: boolean;
    onCancel: () => Promise<{ ok: boolean; errorCode: string | null; message: string | null }>;
}

/**
 * Card que muestra el promo activo de la empresa + invoices afectadas. — #246.
 *
 * Permite cancelar si `canCancel` (owner/admin). El cancel no afecta
 * invoices ya emitidas — solo deja de aplicar en futuros períodos.
 */
export function ActivePromoCodeCard({ active, canCancel, onCancel }: ActivePromoCodeCardProps) {
    const [cancelling, setCancelling] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const handleCancel = async () => {
        setCancelling(true);
        setErrorMessage(null);
        const result = await onCancel();
        if (!result.ok) {
            setErrorMessage(result.message ?? 'No se pudo cancelar el código.');
        }
        setCancelling(false);
        setConfirmOpen(false);
    };

    const formatDateLong = (iso: string): string => {
        try {
            return new Date(iso).toLocaleDateString('es-CO', {
                day: '2-digit',
                month: 'long',
                year: 'numeric',
                timeZone: 'America/Bogota',
            });
        } catch {
            return iso;
        }
    };

    const appliedViaLabel: Record<ActiveCompanyPromoCode['applied_via'], string> = {
        enrollment: 'Aplicado al registrarse',
        github_action: 'Aplicado por flexyflow',
        self_service: 'Aplicado desde tu panel',
    };

    return (
        <DashboardPanel
            title="Código promocional activo"
            icon={Gift}
            rightSlot={
                canCancel ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirmOpen(true)}
                        disabled={cancelling}
                    >
                        {cancelling && <Loader2 className="mr-2 h-3 w-3 animate-spin" />}
                        Cancelar código
                    </Button>
                ) : undefined
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-3">
                    <Badge variant="secondary" className="font-mono text-xs uppercase">
                        {active.code}
                    </Badge>
                    <span className="text-foreground text-sm font-medium">{active.name}</span>
                    <span className="text-muted-foreground text-xs">{appliedViaLabel[active.applied_via] ?? active.applied_via}</span>
                </div>

                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Descuento</dt>
                        <dd className="font-brand text-foreground mt-1 text-xl tabular-nums">{active.discount_percent}%</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Duración</dt>
                        <dd className="font-brand text-foreground mt-1 text-xl tabular-nums">{active.months_duration} meses</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Vigencia</dt>
                        <dd className="text-foreground mt-1 text-sm">
                            {formatDateLong(active.starts_at)} → {formatDateLong(active.ends_at)}
                        </dd>
                    </div>
                </dl>

                {active.invoices.length > 0 && (
                    <div className="border-border border-t pt-4">
                        <p className="text-muted-foreground mb-2 text-[11px] uppercase tracking-[0.15em]">
                            Facturas con descuento aplicado ({active.invoices.length})
                        </p>
                        <ul className="space-y-1.5">
                            {active.invoices.slice(0, 5).map((inv) => (
                                <li key={inv.id} className="flex items-center justify-between text-sm">
                                    <span className="text-foreground">{formatMonthYear(inv.period_from)}</span>
                                    <span className="text-muted-foreground tabular-nums">
                                        ${formatCOP(inv.amount)}
                                        {inv.discount_amount !== null && inv.discount_amount > 0 && (
                                            <span className="text-success-foreground ml-2 text-xs">
                                                −${formatCOP(inv.discount_amount)}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {errorMessage !== null && <p className="text-destructive text-sm">{errorMessage}</p>}
            </div>

            <ConfirmDialog
                open={confirmOpen}
                title="¿Cancelar el código promocional?"
                message="No afecta las facturas ya emitidas. A partir del próximo período se factura el precio completo. Esta acción no se puede deshacer."
                confirmLabel="Sí, cancelar"
                cancelLabel="No, conservar"
                loading={cancelling}
                onConfirm={handleCancel}
                onCancel={() => setConfirmOpen(false)}
            />
        </DashboardPanel>
    );
}
