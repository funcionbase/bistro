import { type ComponentType, type ReactNode } from 'react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface DashboardPanelProps {
    /** Título del panel (h3 dentro de CardTitle). */
    title: string;
    /** Icono opcional de lucide-react, a la izquierda del título. */
    icon?: ComponentType<{ className?: string }>;
    /** Slot a la derecha del título (badge, timestamp, toggle). */
    rightSlot?: ReactNode;
    /** Contenido del panel — pasa por CardContent. */
    children: ReactNode;
    /** Hace el padding del CardContent más compacto (p-3) si true. Default p-6. */
    dense?: boolean;
    className?: string;
    contentClassName?: string;
}

/**
 * Wrapper estándar para paneles del dashboard (ver FRONTEND_UI_GUIDELINES §6.1, §7).
 *
 * Card con `rounded-2xl shadow-sm`, header con icon + title + rightSlot opcional,
 * y CardContent. Reemplaza el boilerplate de `<Card className="rounded-2xl ..."><CardHeader>
 * <CardTitle><Icon />...</CardTitle></CardHeader>` que se repetía en cada panel.
 */
export function DashboardPanel({
    title,
    icon: Icon,
    rightSlot,
    children,
    dense = false,
    className,
    contentClassName,
}: DashboardPanelProps) {
    return (
        <Card className={cn('rounded-2xl shadow-sm', className)}>
            <CardHeader className="pb-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-base font-semibold">
                        {Icon && <Icon className="h-4 w-4" />}
                        {title}
                    </CardTitle>
                    {rightSlot && <div className="flex shrink-0 items-center gap-2">{rightSlot}</div>}
                </div>
            </CardHeader>
            <CardContent className={cn(dense && 'p-3 pt-0', contentClassName)}>{children}</CardContent>
        </Card>
    );
}
