import { cn } from '@/lib/utils';

/**
 * Contrato de estados de §8.6 del plan. Es la MISMA lista que produce el
 * backend (`CompanyWhatsappAccount.status`): mantenerlas alineadas es lo que
 * evita que la UI invente un estado que el servidor nunca manda, o al revés.
 */
export type WhatsappStatus = 'pending' | 'verifying' | 'connected' | 'disconnected' | 'banned' | 'error';

interface WhatsappStatusPillProps {
    status: WhatsappStatus | string;
    className?: string;
}

interface StatusStyle {
    label: string;
    pill: string;
    dot: string;
    /** El punto late solo mientras algo está en curso: en el resto sería ruido. */
    pulse?: boolean;
}

const STATUS_STYLES: Record<WhatsappStatus, StatusStyle> = {
    pending: {
        label: 'Sin conectar',
        pill: 'border-border bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground/60',
    },
    verifying: {
        label: 'Conectando…',
        pill: 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]',
        dot: 'bg-[color:var(--color-status-warning)]',
        pulse: true,
    },
    connected: {
        label: 'Conectado',
        pill: 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]',
        dot: 'bg-[color:var(--color-status-safe)]',
    },
    disconnected: {
        label: 'Desconectado',
        pill: 'border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)]',
        dot: 'bg-[color:var(--color-status-critical)]',
    },
    banned: {
        label: 'Bloqueado por WhatsApp',
        // Sólido y no translúcido: es el único estado del que no se vuelve solo
        // y tiene que distinguirse de "desconectado" de un vistazo.
        pill: 'border-[color:var(--color-status-critical)] bg-[color:var(--color-status-critical)] text-white',
        dot: 'bg-current',
    },
    error: {
        label: 'Problema de conexión',
        pill: 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]',
        dot: 'bg-[color:var(--color-status-warning)]',
    },
};

/**
 * Píldora de estado del canal de WhatsApp.
 *
 * Un `status` desconocido cae en `error` en vez de romper el render: el backend
 * puede agregar un estado antes de que el frontend se entere, y una pantalla en
 * blanco es peor que una etiqueta imprecisa.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (status colors) y el plan §8.6.
 */
export function WhatsappStatusPill({ status, className }: WhatsappStatusPillProps) {
    const style = STATUS_STYLES[status as WhatsappStatus] ?? STATUS_STYLES.error;

    return (
        <span
            className={cn('inline-flex h-fit items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium', style.pill, className)}
            role="status"
        >
            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', style.dot, style.pulse && 'animate-pulse')} aria-hidden />
            {style.label}
        </span>
    );
}

/** Copy accionable por estado (§8.2): qué pasó y qué hacer, nunca un toast genérico. */
export const WHATSAPP_STATUS_HELP: Record<WhatsappStatus, string> = {
    pending: 'Todavía no escaneaste el código QR con el celular.',
    verifying: 'Estamos terminando de vincular el número. No cierres esta ventana.',
    connected: 'El número está recibiendo y enviando mensajes con normalidad.',
    disconnected: 'Se cerró la sesión desde el celular. Escaneá el QR otra vez para volver a conectar.',
    // Honestidad antes que consuelo: apelar ante WhatsApp no depende de flexyflow.
    banned: 'WhatsApp bloqueó este número. La apelación se hace ante el soporte de WhatsApp y no depende de flexyflow; mientras tanto podés conectar otro número.',
    error: 'No podemos contactar el servidor de mensajería. El problema es nuestro, no tuyo.',
};
