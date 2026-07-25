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
    // Clases token-based dark-aware (tokens de app.css). Divergen a propósito
    // de `config/orders.php`: las clases del backend son light-only porque
    // alimentan PDFs; la presentación web la define este mapa.
    badges: {
        pending_approval: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning-text)]',
        pending: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning-text)]',
        in_kitchen: 'bg-[color:var(--color-category-amber)]/15 text-[color:var(--color-status-warning-text)]',
        ready: 'bg-[color:var(--color-status-info)]/15 text-[color:var(--color-status-info-text)]',
        in_transit: 'bg-[color:var(--color-category-violet)]/15 text-[color:var(--color-category-violet)]',
        completed: 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe-text)]',
        failed: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical-text)]',
        cancelled: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical-text)]',
        refunded: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical-text)]',
        abandoned: 'bg-muted text-muted-foreground',
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
    // El mapa local manda: las clases que llegan del backend son light-only
    // (alimentan PDFs). El cfg solo cubre estados que el frontend no conozca.
    return (
        (ORDER_STATUS_FALLBACK.badges as Record<string, string>)[status] ??
        (cfg.badges as Record<string, string>)[status] ??
        'bg-muted text-muted-foreground'
    );
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
