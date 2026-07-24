import type { SharedContact } from '@/components/chats/chat-contact-card';
import { ChatMessageMedia } from '@/components/chats/chat-message-media';
import { ChatMessageStatusTicks, FAILURE_COPY } from '@/components/chats/chat-message-status-ticks';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { ChatMessage } from '@/hooks/use-chats';
import { APP_LOCALE, APP_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';

import { RotateCw, Smartphone } from 'lucide-react';

interface ChatMessageBubbleProps {
    message: ChatMessage;
    canRetry: boolean;
    retrying: boolean;
    onRetry: (messageId: string) => void;
    onOpenImage: (url: string, caption?: string | null) => void;
    onWriteToContact?: (phone: string) => void;
    onSaveContact?: (contact: SharedContact) => void;
}

function formatTime(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString(APP_LOCALE, { hour: '2-digit', minute: '2-digit', timeZone: APP_TIMEZONE });
}

/** Fecha + hora al segundo, para el tooltip de autoría (§8.4c): el minuto en la burbuja, el segundo exacto al pasar el mouse. */
function formatExact(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString(APP_LOCALE, {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: APP_TIMEZONE,
    });
}

/**
 * Una burbuja de la conversación.
 *
 * Vive en su propio archivo porque acumula seis reglas que juntas hacían
 * ilegible el `map()` de la página: autoría, microtag de celular, ticks con
 * texto alternativo, reintento con motivo, sticker sin fondo y área táctil de
 * 44 px.
 *
 * **Autoría** (§5.7): con varios operadores en la misma bandeja, `sender =
 * 'operator'` a secas no dice quién le respondió qué al cliente. Los mensajes
 * anteriores a F1 no tienen autor y muestran "Operador" a secas — sin backfill,
 * como decidió el plan.
 */
export function ChatMessageBubble({ message, canRetry, retrying, onRetry, onOpenImage, onWriteToContact, onSaveContact }: ChatMessageBubbleProps) {
    const isClient = message.sender === 'client';
    const isBot = message.sender === 'bot';
    const failed = message.status === 'failed';
    // El sticker no lleva caja: con fondo de burbuja se ve como un error de
    // render en vez de como un sticker.
    const bare = message.media_type === 'sticker';

    const authorLabel = isBot
        ? 'bot'
        : message.from_device
          ? null // el microtag ya lo explica mejor que un nombre
          : (message.author ?? (isClient ? null : 'Operador'));

    return (
        <TooltipProvider delayDuration={150}>
            <div className={cn('flex', isClient ? 'justify-start' : 'justify-end')}>
                <div
                    className={cn(
                        'max-w-xs rounded-xl text-sm',
                        bare ? 'bg-transparent p-0' : 'px-3 py-2',
                        bare
                            ? ''
                            : isClient
                              ? 'bg-card border-border text-foreground border text-left'
                              : isBot
                                ? 'bg-secondary text-secondary-foreground text-right'
                                : 'bg-primary text-primary-foreground text-right',
                        failed && 'ring-1 ring-[color:var(--color-status-critical)]/50',
                    )}
                >
                    {message.media_type || message.body.startsWith('[location]') ? (
                        <ChatMessageMedia
                            type={message.media_type ?? null}
                            url={message.media_url}
                            mime={message.media_mime}
                            body={message.body}
                            payload={message.media_payload}
                            onOpenImage={onOpenImage}
                            onWriteToContact={onWriteToContact}
                            onSaveContact={onSaveContact}
                        />
                    ) : (
                        <span className="[overflow-wrap:anywhere] whitespace-pre-wrap">{message.body}</span>
                    )}

                    {/* El microtag evita la confusión más cara de la bandeja: creer
                    que respondió otro operador cuando en realidad contestó el
                    dueño desde su celular, fuera del panel. */}
                    {message.from_device && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <p className="mt-1 flex items-center justify-end gap-1 text-[10px] opacity-70">
                                    <Smartphone className="h-3 w-3" />
                                    desde el celular
                                </p>
                            </TooltipTrigger>
                            <TooltipContent className="text-xs">Respondido desde el WhatsApp del celular, no desde el panel.</TooltipContent>
                        </Tooltip>
                    )}

                    {!bare && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <div
                                    className={cn(
                                        'mt-1 flex items-center justify-end gap-1 text-[10px]',
                                        isClient ? 'text-muted-foreground' : 'opacity-80',
                                    )}
                                >
                                    {authorLabel && <span className="truncate">{authorLabel} ·</span>}
                                    <span>{formatTime(message.sent_at)}</span>
                                    {!isClient && <ChatMessageStatusTicks status={message.status} failureReason={message.failure_reason} />}
                                </div>
                            </TooltipTrigger>
                            {/* El minuto en la burbuja; el nombre completo y el segundo exacto en el hover (§8.4c). */}
                            <TooltipContent className="text-xs">
                                {authorLabel ? `${authorLabel} · ` : ''}
                                {formatExact(message.sent_at)}
                            </TooltipContent>
                        </Tooltip>
                    )}

                    {failed && (
                        <div className="mt-1 flex items-center justify-end gap-2">
                            <span className="text-[10px] opacity-80">
                                {message.failure_reason ? (FAILURE_COPY[message.failure_reason] ?? 'No se pudo entregar.') : 'No se pudo entregar.'}
                            </span>
                            {canRetry && (
                                // 44 px de área táctil: reintentar con el pulgar no
                                // puede depender de acertarle a un ícono de 12 px.
                                <button
                                    type="button"
                                    onClick={() => onRetry(message.id)}
                                    disabled={retrying}
                                    className="hover:bg-background/20 inline-flex h-11 min-w-11 items-center justify-center gap-1 rounded px-2 text-[10px] font-medium disabled:opacity-50"
                                    aria-label="Reintentar el envío de este mensaje"
                                >
                                    <RotateCw className={cn('h-3.5 w-3.5', retrying && 'animate-spin')} />
                                    {retrying ? 'Enviando…' : 'Reintentar'}
                                </button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </TooltipProvider>
    );
}
