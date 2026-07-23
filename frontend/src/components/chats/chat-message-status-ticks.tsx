import { AlertCircle, Check, CheckCheck, Clock } from 'lucide-react';

interface Props {
    status: string | null | undefined;
    /** Código de `chat_messages.failure_reason`. Solo aplica a `failed`. */
    failureReason?: string | null;
}

/** El copy en español se arma acá: el backend guarda un código, no una frase. */
const FAILURE_COPY: Record<string, string> = {
    recipient_not_on_whatsapp: 'Ese número no tiene WhatsApp. Revisá el teléfono del cliente.',
    evolution_api_error: 'El servidor de mensajería rechazó el envío.',
    channel_disconnected: 'El número está desconectado.',
    media_path_missing: 'El archivo adjunto no se pudo leer.',
};

/**
 * Ticks estilo WhatsApp para mensajes salientes (operador/bot):
 *   - null:        reloj (esperando confirmacion del proveedor)
 *   - sent:        un check gris (recibido por el servidor de mensajeria)
 *   - delivered:   doble check gris (entregado al celular del cliente)
 *   - read:        doble check azul (cliente abrio el mensaje — solo si tiene
 *                  read receipts activado en su WhatsApp)
 *   - failed:      icono de error rojo, con el motivo REAL
 *
 * Son cinco estados con la misma forma, distinguibles solo por color: por eso
 * cada uno lleva `aria-label` ademas del `title`. El color solo no comunica, y
 * un lector de pantalla no tiene ninguna forma de leer un tick gris vs. azul.
 */
export function ChatMessageStatusTicks({ status, failureReason }: Props) {
    if (status === 'failed') {
        const detail = failureReason ? (FAILURE_COPY[failureReason] ?? 'No se pudo entregar.') : 'No se pudo entregar.';

        return (
            <span title={detail} aria-label={`Falló: ${detail}`} role="img" className="inline-flex">
                <AlertCircle className="h-3 w-3 text-[color:var(--color-status-critical)]" />
            </span>
        );
    }

    if (status === 'read') {
        return (
            <span title="Leído por el cliente" aria-label="Leído" role="img" className="inline-flex">
                <CheckCheck className="h-3.5 w-3.5 text-[color:var(--color-status-info)]" />
            </span>
        );
    }

    if (status === 'delivered') {
        return (
            <span title="Entregado al cliente" aria-label="Entregado" role="img" className="inline-flex">
                <CheckCheck className="h-3.5 w-3.5 opacity-70" />
            </span>
        );
    }

    if (status === 'sent') {
        return (
            <span title="Enviado a WhatsApp" aria-label="Enviado" role="img" className="inline-flex">
                <Check className="h-3.5 w-3.5 opacity-70" />
            </span>
        );
    }

    return (
        <span title="Esperando confirmación" aria-label="Esperando confirmación" role="img" className="inline-flex">
            <Clock className="h-3 w-3 opacity-60" />
        </span>
    );
}

export { FAILURE_COPY };
