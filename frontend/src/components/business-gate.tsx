import { useBusinessCapability, useBusinessContext } from '@/lib/business-context';
import { type ReactNode } from 'react';

/**
 * Oculta o muestra UI según una capability del vertical de la sede activa.
 *
 * Por defecto NO renderiza nada si la capability no aplica. Pasá `fallback`
 * para mostrar un placeholder explicativo (ej. EmptyState diciendo "Este módulo
 * no aplica al tipo de negocio de la sede").
 *
 * Ejemplo:
 *   <BusinessGate flag="kds">
 *     <KdsBoard ... />
 *   </BusinessGate>
 *
 *   <BusinessGate flag="tables" fallback={<EmptyState ... />}>
 *     <TablesList ... />
 *   </BusinessGate>
 */
export interface BusinessGateProps {
    flag: string;
    fallback?: ReactNode;
    children: ReactNode;
    /**
     * Si true, renderiza children mientras el contexto aún no se carga (evita
     * "flash" de fallback en navegación con cache fresca). Default false.
     */
    optimistic?: boolean;
}

export function BusinessGate({ flag, fallback = null, children, optimistic = false }: BusinessGateProps) {
    const ctx = useBusinessContext();
    const enabled = useBusinessCapability(flag);

    if (!ctx && optimistic) {
        return <>{children}</>;
    }

    if (!enabled) {
        return <>{fallback}</>;
    }

    return <>{children}</>;
}
