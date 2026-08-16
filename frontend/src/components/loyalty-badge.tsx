import type { BadgeProps } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Award, Crown, Medal, type LucideIcon } from 'lucide-react';

export type LoyaltyTier = 'bronze' | 'silver' | 'gold' | string;

interface TierStyle {
    label: string;
    Icon: LucideIcon;
    variant: BadgeProps['variant'];
}

const TIER_STYLES: Record<string, TierStyle> = {
    bronze: { label: 'BRONZE', Icon: Medal, variant: 'warning' },
    silver: { label: 'SILVER', Icon: Award, variant: 'secondary' },
    gold: { label: 'GOLD', Icon: Crown, variant: 'accent' },
};

interface LoyaltyBadgeProps {
    tier: LoyaltyTier;
    size?: 'sm' | 'md';
    className?: string;
}

/**
 * Badge reutilizable para el tier de fidelizacion.
 *
 * Mapeo a variants semanticas del Badge:
 *  - bronze -> `warning` (amber semaforo, primer escalon)
 *  - silver -> `secondary` (gris neutro)
 *  - gold -> `accent` (lime, top de piramide — VIP, cumple §9 lime para
 *    momentos de logro/loyalty)
 *
 * Acepta tiers custom definidos por la empresa; cae a 'bronze' si no se
 * reconoce.
 */
export function LoyaltyBadge({ tier, size = 'sm', className = '' }: LoyaltyBadgeProps) {
    const style = TIER_STYLES[tier] ?? TIER_STYLES.bronze;
    const sizeClasses = size === 'sm' ? 'text-[10px] px-1.5 py-0.5 gap-1' : 'text-xs px-2 py-1 gap-1.5';
    const iconSize = size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5';
    const { Icon } = style;

    return (
        <Badge variant={style.variant} className={cn('tracking-wide uppercase', sizeClasses, className)}>
            <Icon className={iconSize} />
            {style.label}
        </Badge>
    );
}
