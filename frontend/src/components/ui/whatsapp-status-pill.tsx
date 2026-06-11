import { cn } from '@/lib/utils';

export type WhatsappStatus = 'connected' | 'disconnected';

interface WhatsappStatusPillProps {
    status: WhatsappStatus;
    className?: string;
}

const STATUS_LABELS: Record<WhatsappStatus, string> = {
    connected: 'Conectado',
    disconnected: 'Sin conectar',
};

/**
 * Píldora de estado para la integración de WhatsApp.
 *
 * Reemplaza al combo hardcoded `bg-emerald-50 text-emerald-700` /
 * `bg-gray-50 text-gray-600` que vivía en `pages/company/whatsapp.tsx` y
 * rompía dark mode. Usa los tokens DS `--color-status-safe` y `muted` para
 * que el render sea coherente entre temas.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (status colors).
 */
export function WhatsappStatusPill({ status, className }: WhatsappStatusPillProps) {
    const isConnected = status === 'connected';
    return (
        <span
            className={cn(
                'inline-flex h-fit items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium',
                isConnected
                    ? 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]'
                    : 'border-border bg-muted text-muted-foreground',
                className,
            )}
            role="status"
        >
            <span
                className={cn(
                    'h-1.5 w-1.5 rounded-full',
                    isConnected
                        ? 'bg-[color:var(--color-status-safe)]'
                        : 'bg-muted-foreground/60',
                )}
                aria-hidden
            />
            {STATUS_LABELS[status]}
        </span>
    );
}
