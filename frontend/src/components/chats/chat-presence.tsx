import { Eye } from 'lucide-react';

interface ChatPresenceProps {
    /** Nombres de los otros operadores con la conversación abierta. */
    viewers: string[];
}

/**
 * Aviso de quién más está viendo la conversación (§5.7).
 *
 * La señal puede llegar con hasta medio minuto de atraso porque se apoya en el
 * refresco de 30 s de la bandeja. **Alcanza para el objetivo real** —que dos
 * personas no le escriban lo mismo al cliente— y cuesta cero infraestructura.
 * No se promete tiempo real ni el copy lo insinúa.
 */
export function ChatPresence({ viewers }: ChatPresenceProps) {
    if (viewers.length === 0) return null;

    const label =
        viewers.length === 1
            ? `${viewers[0]} también está viendo esta conversación`
            : `${viewers.slice(0, 2).join(', ')}${viewers.length > 2 ? ` y ${viewers.length - 2} más` : ''} también están viendo esta conversación`;

    return (
        <div
            className="flex items-center gap-2 border-b bg-[color:var(--color-status-info)]/10 px-3 py-1.5 text-xs text-[color:var(--color-status-info)]"
            role="status"
            aria-live="polite"
        >
            <Eye className="h-3.5 w-3.5 shrink-0" />
            <span className="truncate">{label}</span>
        </div>
    );
}
