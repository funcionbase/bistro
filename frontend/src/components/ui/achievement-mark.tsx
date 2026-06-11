import { type ReactNode } from 'react';

import { Check } from 'lucide-react';

import { cn } from '@/lib/utils';

export type AchievementMarkTone = 'safe' | 'primary';
export type AchievementMarkSize = 'sm' | 'base' | 'lg';

interface AchievementMarkProps {
    /** Titulo corto, en pasado y primera persona del verbo (ej. "Cuadre exacto"). Ver §2 voice. */
    title: string;
    /** Texto secundario opcional, una sola linea. */
    description?: ReactNode;
    /** Tono del check + halo. Default 'safe' (lime soft del DS §3). */
    tone?: AchievementMarkTone;
    /** Tamano del check + tipografia. Default 'base'. */
    size?: AchievementMarkSize;
    className?: string;
}

const toneClasses: Record<AchievementMarkTone, { bg: string; ring: string; icon: string }> = {
    safe: {
        bg: 'bg-[color:var(--color-status-safe)]/10',
        ring: 'ring-[color:var(--color-status-safe)]/20',
        icon: 'text-[color:var(--color-status-safe)]',
    },
    primary: {
        bg: 'bg-primary/10',
        ring: 'ring-primary/20',
        icon: 'text-primary',
    },
};

const sizeClasses: Record<AchievementMarkSize, { circle: string; icon: string; title: string; description: string; gap: string }> = {
    sm: {
        circle: 'h-10 w-10',
        icon: 'h-5 w-5',
        title: 'text-sm font-semibold',
        description: 'text-xs',
        gap: 'space-y-2',
    },
    base: {
        circle: 'h-14 w-14',
        icon: 'h-7 w-7',
        title: 'text-lg font-semibold tracking-[-0.01em]',
        description: 'text-sm',
        gap: 'space-y-3',
    },
    lg: {
        circle: 'h-20 w-20',
        icon: 'h-10 w-10',
        title: 'font-brand text-2xl font-medium leading-[1.05] tracking-[-0.02em] md:text-3xl',
        description: 'text-base md:text-lg',
        gap: 'space-y-4',
    },
};

/**
 * Marca visual de "momento de logro" — check animado + titulo en pasado.
 *
 * Patron canonico para el disparador "Momento de logro" del DS §14: ocurre
 * **una sola vez** tras una accion exitosa (no se anima en cada render). El
 * check entra con `animate-scale-in` y el bloque con `animate-fade-in`; ambos
 * se rinden estaticos cuando el usuario tiene `prefers-reduced-motion: reduce`.
 *
 * Usos canonicos:
 *  - Caja cuadrada sin diferencias (`cash-register-panel`).
 *  - Onboarding completado (`enrollment/company`).
 *  - Turno asignado / publicado (`planner/week`).
 *
 * NO usar para feedback recurrente (toast por cada cobro, etc.) — para eso
 * existen `Toast` y `Alert`. El logro pide peso visual; usarlo en todo lo
 * banaliza el patron.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §14 y §2 (microcopy en pasado, neutro).
 */
export function AchievementMark({
    title,
    description,
    tone = 'safe',
    size = 'base',
    className,
}: AchievementMarkProps) {
    const t = toneClasses[tone];
    const s = sizeClasses[size];

    return (
        <div className={cn('animate-fade-in flex flex-col items-center text-center', s.gap, className)}>
            <span
                className={cn(
                    'animate-scale-in flex items-center justify-center rounded-full ring-4',
                    s.circle,
                    t.bg,
                    t.ring,
                )}
                aria-hidden="true"
            >
                <Check className={cn(s.icon, t.icon)} strokeWidth={2.5} />
            </span>
            <div className={cn('space-y-1', size === 'sm' ? 'space-y-0.5' : undefined)}>
                <p className={cn('text-foreground', s.title)}>{title}</p>
                {description && <p className={cn('text-muted-foreground', s.description)}>{description}</p>}
            </div>
        </div>
    );
}
