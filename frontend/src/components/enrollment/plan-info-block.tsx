import { Skeleton } from '@/components/ui/skeleton';
import { type DefaultPlanInfo } from '@/hooks/use-default-plan';
import { formatCOP } from '@/lib/formatters';

interface PlanInfoBlockProps {
    plan: DefaultPlanInfo | null;
    loading: boolean;
}

/**
 * Bloque informativo del plan default de flexyflow — #246. Se renderiza
 * debajo del HeroPanel cuando NO hay `?promo=` en la URL (el panel hero
 * se mantiene como marketing). Cuando hay promo válido, lo reemplaza
 * `<PromoLandingPanel>`.
 */
export function PlanInfoBlock({ plan, loading }: PlanInfoBlockProps) {
    if (loading) {
        return (
            <div className="border-border bg-card flex flex-col gap-3 rounded-2xl border p-5">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-8 w-40" />
                <Skeleton className="h-3 w-48" />
            </div>
        );
    }

    if (plan === null) {
        return null;
    }

    const taxLabel = plan.tax_regime === 'simple_no_iva' ? '' : `(${formatTaxRegime(plan.tax_regime, plan.tax_rate)} incluido)`;

    return (
        <div className="border-border bg-card flex flex-col gap-2 rounded-2xl border p-5">
            <p className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Plan</p>
            <h3 className="text-foreground font-brand text-2xl">{plan.name}</h3>
            <p className="text-foreground font-brand text-3xl tabular-nums">
                ${formatCOP(plan.price)} <span className="text-muted-foreground text-sm font-normal">{plan.currency}/mes</span>
            </p>
            {taxLabel !== '' && <p className="text-muted-foreground text-xs">{taxLabel}</p>}
            {plan.description !== null && plan.description !== '' && <p className="text-muted-foreground text-sm">{plan.description}</p>}
        </div>
    );
}

function formatTaxRegime(regime: string, rate: number): string {
    switch (regime) {
        case 'iva_19':
        case 'iva_5':
        case 'iva_exento':
            return `IVA ${rate.toFixed(0)}%`;
        case 'inc_8':
            return `INC ${rate.toFixed(0)}%`;
        case 'simple_no_iva':
            return 'sin impuestos';
        default:
            return `impuestos ${rate.toFixed(0)}%`;
    }
}
