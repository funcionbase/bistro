import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}

/**
 * Empty state estandar de la plataforma (ver FRONTEND_UI_GUIDELINES §10/§13).
 *
 * - Centrado, padding generoso.
 * - Icono opcional (lucide-react) en muted-foreground.
 * - h3 con titulo, descripcion en muted, CTA siguiente paso opcional.
 *
 * Para "sin coincidencias" usar `description` que sugiera limpiar filtros.
 * Para "sin datos aun" usar `description` que oriente al siguiente paso.
 */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center px-4 py-12 text-center md:py-14', className)}>
            {Icon && <Icon className="text-muted-foreground size-10" aria-hidden="true" />}
            <h3 className={cn('font-brand text-base font-semibold tracking-tight md:text-lg', Icon && 'mt-4')}>{title}</h3>
            {description && (
                <p className="text-muted-foreground mt-1 max-w-md text-sm">{description}</p>
            )}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
