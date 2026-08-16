import { HeroPanel } from '@/components/ui/hero-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { type PromoCodePreview } from '@/hooks/use-promo-code-from-url';
import { formatCOP } from '@/lib/formatters';
import { CheckCircle2, Gift } from 'lucide-react';

interface PromoLandingPanelProps {
    preview: PromoCodePreview | null;
    loading: boolean;
    className?: string;
}

/**
 * Reemplaza el HeroPanel del enrollment cuando llega un `?promo=` válido
 * desde URL. Muestra el código, % descuento, meses, precio normal
 * tachado y precio con descuento. CTA implícito: continuar el wizard.
 */
export function PromoLandingPanel({ preview, loading, className }: PromoLandingPanelProps) {
    if (loading) {
        return (
            <HeroPanel eyebrow="Aplicando código" className={className}>
                <Skeleton className="bg-foreground/20 h-8 w-2/3" />
                <Skeleton className="bg-foreground/20 h-4 w-3/4" />
                <Skeleton className="bg-foreground/20 h-6 w-1/2" />
            </HeroPanel>
        );
    }

    if (preview === null) {
        return null;
    }

    const savingsPct = preview.discount_percent;
    const monthsLabel = preview.months_duration === 1 ? '1 mes' : `${preview.months_duration} meses`;

    return (
        <HeroPanel
            eyebrow={`Código ${preview.code}`}
            className={className}
            tone="accent"
            footer={
                <div className="space-y-3 text-sm">
                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>El descuento aplica desde tu primera factura. Sin trampas, sin tarjeta.</span>
                    </div>
                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                        <Gift className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Inscríbete ahora para activarlo automáticamente.</span>
                    </div>
                </div>
            }
        >
            <div className="space-y-5">
                <div>
                    <p className="text-[11px] uppercase tracking-[0.2em] opacity-80">{preview.name}</p>
                    <h2 className="font-brand mt-2 text-3xl leading-tight md:text-4xl">
                        {savingsPct}% off
                        <br />
                        por {monthsLabel}.
                    </h2>
                </div>
                {preview.description !== null && preview.description !== '' && (
                    <p className="text-sm leading-relaxed opacity-90">{preview.description}</p>
                )}
                <dl className="border-foreground/15 grid gap-3 border-t pt-4">
                    <div className="flex items-baseline justify-between">
                        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Precio normal</dt>
                        <dd className="font-brand text-base tabular-nums line-through opacity-70">${formatCOP(preview.plan_default_price)}</dd>
                    </div>
                    <div className="flex items-baseline justify-between">
                        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Con descuento</dt>
                        <dd className="font-brand text-2xl tabular-nums md:text-3xl">${formatCOP(preview.discounted_price)}</dd>
                    </div>
                    <div className="flex items-baseline justify-between">
                        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Ahorras al mes</dt>
                        <dd className="font-brand text-base tabular-nums">${formatCOP(preview.monthly_savings)}</dd>
                    </div>
                </dl>
            </div>
        </HeroPanel>
    );
}
