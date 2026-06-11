import type { OrderStatus, OrderStatusesConfig } from '@/types';

/**
 * Fallback embebido si los shared props de Inertia no están disponibles aún
 * (e.g., primer render antes del hidrate). Debe coincidir con `config/orders.php`.
 * Cualquier cambio aquí debe replicarse allá.
 */
export const ORDER_STATUS_FALLBACK: OrderStatusesConfig = {
    all: ['pending_approval', 'pending', 'in_kitchen', 'ready', 'in_transit', 'completed', 'failed', 'cancelled', 'refunded', 'abandoned'],
    operational: ['pending', 'in_kitchen', 'ready', 'in_transit'],
    terminal_success: ['completed'],
    terminal_failure: ['failed', 'cancelled', 'refunded', 'abandoned'],
    kanban: ['pending', 'in_kitchen', 'ready', 'in_transit', 'completed'],
    revenue: ['completed'],
    labels: {
        pending_approval: 'Pendiente aprobación',
        pending: 'Pendiente',
        in_kitchen: 'En cocina',
        ready: 'Para entrega',
        in_transit: 'En tránsito',
        completed: 'Completado',
        failed: 'Entrega fallida',
        cancelled: 'Cancelado',
        refunded: 'Devolución',
        abandoned: 'Abandonado',
    },
    badges: {
        pending_approval: 'bg-slate-100 text-slate-700',
        pending: 'bg-yellow-100 text-yellow-800',
        in_kitchen: 'bg-orange-100 text-orange-800',
        ready: 'bg-blue-100 text-blue-800',
        in_transit: 'bg-purple-100 text-purple-800',
        completed: 'bg-green-100 text-green-800',
        failed: 'bg-rose-100 text-rose-700',
        cancelled: 'bg-red-100 text-red-700',
        refunded: 'bg-pink-100 text-pink-700',
        abandoned: 'bg-amber-100 text-amber-700',
    },
    category: {
        pending_approval: 'pre_operational',
        pending: 'operational',
        in_kitchen: 'operational',
        ready: 'operational',
        in_transit: 'operational',
        completed: 'terminal_success',
        failed: 'terminal_failure',
        cancelled: 'terminal_failure',
        refunded: 'terminal_failure',
        abandoned: 'terminal_failure',
    },
};

export function statusLabel(config: OrderStatusesConfig | undefined, status: string): string {
    const cfg = config ?? ORDER_STATUS_FALLBACK;
    return (cfg.labels as Record<string, string>)[status] ?? status;
}

export function statusBadgeClass(config: OrderStatusesConfig | undefined, status: string): string {
    const cfg = config ?? ORDER_STATUS_FALLBACK;
    return (cfg.badges as Record<string, string>)[status] ?? 'bg-gray-100 text-gray-800';
}

export function isOperational(config: OrderStatusesConfig | undefined, status: string): boolean {
    const cfg = config ?? ORDER_STATUS_FALLBACK;
    return (cfg.operational as string[]).includes(status);
}

export function isRevenue(config: OrderStatusesConfig | undefined, status: string): boolean {
    const cfg = config ?? ORDER_STATUS_FALLBACK;
    return (cfg.revenue as string[]).includes(status);
}

export function isTerminal(config: OrderStatusesConfig | undefined, status: string): boolean {
    const cfg = config ?? ORDER_STATUS_FALLBACK;
    return (cfg.terminal_success as string[]).includes(status) || (cfg.terminal_failure as string[]).includes(status);
}

export type { OrderStatus, OrderStatusesConfig };
