import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { FieldHint, ReasonTooltip } from '@/components/ui/field-hint';
import { WHATSAPP_STATUS_HELP, WhatsappStatusPill, type WhatsappStatus } from '@/components/ui/whatsapp-status-pill';
import { formatResponseTime, useChannelMetrics } from '@/hooks/use-channel-metrics';
import type { WhatsappChannel } from '@/hooks/use-whatsapp-channels';
import { timeAgo } from '@/lib/datetime';

import { MessageCircle, MoreHorizontal, Plus, QrCode, RefreshCw, Send, Store, Trash2 } from 'lucide-react';

interface ChannelCardProps {
    channel: WhatsappChannel;
    canManage: boolean;
    onReconnect: (channel: WhatsappChannel) => void;
    onDisconnect: (channel: WhatsappChannel) => void;
    onTestMessage: (channel: WhatsappChannel) => void;
    onOpenChats: (channel: WhatsappChannel) => void;
    testing?: boolean;
}

/** La acción primaria depende del estado — es la tabla de §8.6, no una heurística. */
const PRIMARY_ACTION: Record<WhatsappStatus, { label: string; kind: 'connect' | 'reconnect' | 'retry' | 'none' } | undefined> = {
    pending: { label: 'Conectar', kind: 'connect' },
    verifying: { label: '', kind: 'none' },
    connected: { label: '', kind: 'none' },
    disconnected: { label: 'Reconectar', kind: 'reconnect' },
    banned: { label: 'Ver qué hacer', kind: 'none' },
    error: { label: 'Reintentar', kind: 'retry' },
};

/**
 * Tarjeta de un canal conectado o a medio conectar (§8.2).
 *
 * El estado nunca queda mudo: debajo de la píldora va SIEMPRE la explicación
 * accionable de `WHATSAPP_STATUS_HELP`. Un canal en rojo sin decir qué hacer es
 * el bug de UX que este rediseño viene a cerrar.
 */
export function ChannelCard({ channel, canManage, onReconnect, onDisconnect, onTestMessage, onOpenChats, testing = false }: ChannelCardProps) {
    const status = (channel.status as WhatsappStatus) ?? 'error';
    const primary = PRIMARY_ACTION[status];
    const lastMessage = timeAgo(channel.last_message_at);
    const scopeLabel = channel.scope === 'company' ? 'Toda la empresa' : (channel.branch_name ?? channel.label ?? 'Sede');
    const isConnected = status === 'connected';

    return (
        <Card className="flex flex-col">
            <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 pb-2">
                <div className="min-w-0 space-y-1.5">
                    <WhatsappStatusPill status={status} />
                    <p className="truncate font-medium">{scopeLabel}</p>
                    <p className="text-muted-foreground truncate font-mono text-sm">{channel.phone_e164 ?? 'Número sin detectar'}</p>
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={`Acciones de ${scopeLabel}`}>
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => onReconnect(channel)}>
                                <QrCode className="mr-2 h-4 w-4" />
                                {isConnected ? 'Cambiar número' : 'Ver código QR'}
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => onOpenChats(channel)}>
                                <MessageCircle className="mr-2 h-4 w-4" />
                                Ver conversaciones
                            </DropdownMenuItem>
                            {isConnected && (
                                <DropdownMenuItem onClick={() => onTestMessage(channel)} disabled={testing}>
                                    <Send className="mr-2 h-4 w-4" />
                                    {testing ? 'Enviando…' : 'Enviar mensaje de prueba'}
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => onDisconnect(channel)}>
                                <Trash2 className="mr-2 h-4 w-4" />
                                Desconectar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </CardHeader>

            <CardContent className="flex-1 space-y-2 pb-3 text-sm">
                {/* La explicación va en el texto, no en un tooltip: en móvil no hay
                    hover y esto es justo lo que el usuario necesita para decidir. */}
                <p className="text-muted-foreground text-xs">{WHATSAPP_STATUS_HELP[status] ?? WHATSAPP_STATUS_HELP.error}</p>

                {isConnected && (
                    <p className="text-muted-foreground text-xs">
                        {channel.last_message_at ? `Último mensaje: ${lastMessage}` : 'Todavía no llegó ningún mensaje'}
                        {channel.chats_count > 0 ? ` · ${channel.chats_count} conversación${channel.chats_count === 1 ? '' : 'es'}` : ''}
                    </p>
                )}

                {isConnected && <ChannelMetricsBlock channelId={channel.id} />}
            </CardContent>

            {canManage && primary && primary.kind !== 'none' && (
                <CardFooter className="pt-0">
                    <Button variant="outline" size="sm" className="w-full sm:w-auto" onClick={() => onReconnect(channel)}>
                        <RefreshCw className="mr-2 h-4 w-4" />
                        {primary.label}
                    </Button>
                </CardFooter>
            )}
        </Card>
    );
}

/**
 * Métricas de la tarjeta (§8.4b punto 11): mini-barras de mensajes por día
 * (7 días, sin librería) + tiempo medio de respuesta. El número que le dice al
 * dueño si el módulo funciona.
 */
function ChannelMetricsBlock({ channelId }: { channelId: string }) {
    const { metrics, loading } = useChannelMetrics(channelId, true);

    if (loading || !metrics) {
        return null;
    }

    const days = metrics.messages_per_day;
    const total = days.reduce((sum, d) => sum + d.count, 0);
    const max = Math.max(1, ...days.map((d) => d.count));
    const responseTime = formatResponseTime(metrics.avg_response_seconds);

    if (total === 0 && responseTime === null) {
        return null;
    }

    return (
        <div className="border-border/60 flex items-end justify-between gap-3 border-t pt-2">
            <div className="flex h-8 items-end gap-0.5" aria-label={`${total} mensajes en los últimos 7 días`}>
                {days.map((d) => (
                    <div
                        key={d.date}
                        title={`${d.date}: ${d.count}`}
                        className="w-2 rounded-sm bg-[color:var(--color-status-info)]/60"
                        style={{ height: `${Math.max(8, (d.count / max) * 100)}%` }}
                    />
                ))}
            </div>
            <div className="text-muted-foreground space-y-0.5 text-right text-xs">
                <p>{total} msj · 7 días</p>
                {responseTime && (
                    <p className="flex items-center justify-end gap-1">
                        Responde en {responseTime}
                        <FieldHint text="Promedio entre el mensaje del cliente y la primera respuesta, últimos 7 días." side="left" />
                    </p>
                )}
            </div>
        </div>
    );
}

interface BranchPlaceholderCardProps {
    branchName: string;
    onConnect: () => void;
    disabledReason?: string;
}

/**
 * Sede sin WhatsApp (§8.2). No es un canal: es un hueco, y por eso se ve
 * distinto —borde punteado, sin píldora— en vez de disfrazarse de tarjeta.
 *
 * Existe porque convierte "configurar" en una acción evidente. Escondida en un
 * menú, la mitad de las sedes se quedaría sin conectar sin que nadie lo note.
 */
export function BranchPlaceholderCard({ branchName, onConnect, disabledReason }: BranchPlaceholderCardProps) {
    return (
        <Card className="border-border flex flex-col items-start justify-center gap-3 border-dashed bg-transparent p-6 shadow-none">
            <div className="text-muted-foreground flex items-center gap-2">
                <Store className="h-5 w-5" />
                <p className="font-medium">{branchName} — sin WhatsApp</p>
            </div>
            <ReasonTooltip reason={disabledReason}>
                <Button variant="outline" size="sm" onClick={onConnect} disabled={Boolean(disabledReason)}>
                    <Plus className="mr-2 h-4 w-4" />
                    Conectar esta sede
                </Button>
            </ReasonTooltip>
        </Card>
    );
}
