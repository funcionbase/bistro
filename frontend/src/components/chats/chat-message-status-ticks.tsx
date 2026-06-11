import { AlertCircle, Check, CheckCheck, Clock } from 'lucide-react';

interface Props {
    status: string | null | undefined;
}

/**
 * Ticks estilo WhatsApp para mensajes salientes (operador/bot):
 *   - null:        reloj (esperando respuesta de Meta)
 *   - sent:        un check gris (recibido por servidores de Meta)
 *   - delivered:   doble check gris (entregado al celular del cliente)
 *   - read:        doble check azul (cliente abrio el mensaje — solo si tiene
 *                  read receipts activado en su WhatsApp)
 *   - failed:      icono de error rojo (rechazado por Meta o numero invalido)
 *
 * El status lo actualiza WhatsappInboundMessageHandler::applyOutboundStatus
 * cuando Meta envia el evento via webhook (value.statuses[]).
 */
export function ChatMessageStatusTicks({ status }: Props) {
    if (status === 'failed') {
        return (
            <span title="No se pudo entregar — verifica el log o reintenta" className="inline-flex">
                <AlertCircle className="h-3 w-3 text-[color:var(--color-status-critical)]" />
            </span>
        );
    }

    if (status === 'read') {
        return (
            <span title="Leido por el cliente" className="inline-flex">
                <CheckCheck className="h-3.5 w-3.5 text-[color:var(--color-status-info)]" />
            </span>
        );
    }

    if (status === 'delivered') {
        return (
            <span title="Entregado al cliente" className="inline-flex">
                <CheckCheck className="h-3.5 w-3.5 opacity-70" />
            </span>
        );
    }

    if (status === 'sent') {
        return (
            <span title="Enviado a WhatsApp" className="inline-flex">
                <Check className="h-3.5 w-3.5 opacity-70" />
            </span>
        );
    }

    return (
        <span title="Esperando confirmacion" className="inline-flex">
            <Clock className="h-3 w-3 opacity-60" />
        </span>
    );
}
