import { ChatMessageMedia } from '@/components/chats/chat-message-media';
import { ChatMessageStatusTicks } from '@/components/chats/chat-message-status-ticks';
import { ChatSourceBadge } from '@/components/chats/chat-source-badge';
import { ClientDetailModal, type ClientDetail } from '@/components/chats/client-detail-modal';
import { OrderDetailModal } from '@/components/orders/order-detail-modal';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChatsSkeleton } from '@/components/ui/chats-skeleton';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useChats, type ChatLatestOrder } from '@/hooks/use-chats';
import { useIsMobile } from '@/hooks/use-mobile';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import type { KanbanOrder } from '@/hooks/use-orders';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, ArrowLeft, Bot, Pause, Pencil, Play, Search, Send } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// Etiquetas y badge classes vienen de useOrderStatuses() / lib/order-status (canónico).

interface OrderBadgeProps {
    order: ChatLatestOrder;
    onClick: (e: React.MouseEvent) => void;
}

function OrderBadge({ order, onClick }: OrderBadgeProps) {
    const orderStatuses = useOrderStatuses();
    const meta = {
        label: statusLabel(orderStatuses, order.status),
        badgeClass: statusBadgeClass(orderStatuses, order.status),
    };
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1 rounded text-[10px] font-medium hover:opacity-80"
            title="Ver detalle de la orden"
        >
            <span className="bg-muted text-foreground rounded px-1.5 py-0.5 font-mono">#{order.id}</span>
            <span className={`rounded px-1.5 py-0.5 ${meta.badgeClass}`}>{meta.label}</span>
        </button>
    );
}

function timeAgo(iso: string | null): string {
    if (!iso) return '';
    const diffMs = Date.now() - new Date(iso).getTime();
    const diffMin = Math.floor(diffMs / 60_000);
    if (diffMin < 1) return 'hace un momento';
    if (diffMin < 60) return `hace ${diffMin} min`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `hace ${diffH}h`;
    return `hace ${Math.floor(diffH / 24)}d`;
}

function formatTime(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Bogota' });
}

export default function ChatsPage() {
    const token = useToken();
    const isMobile = useIsMobile();
    const [searchInput, setSearchInput] = useState('');
    const [searchTerm, setSearchTerm] = useState('');

    useEffect(() => {
        const handle = setTimeout(() => setSearchTerm(searchInput), 300);
        return () => clearTimeout(handle);
    }, [searchInput]);

    const { chats, selectedChat, selectedChatId, selectChat, sendMessage, setBotPaused, updateContact, loading, error } = useChats(token, searchTerm);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<string | null>(null);
    const [botBusy, setBotBusy] = useState(false);
    const [botError, setBotError] = useState<string | null>(null);
    const [editingContact, setEditingContact] = useState(false);
    const [contactName, setContactName] = useState('');
    const [contactPhone, setContactPhone] = useState('');
    const [contactNotes, setContactNotes] = useState('');
    const [contactSaving, setContactSaving] = useState(false);
    const [contactError, setContactError] = useState<string | null>(null);
    const [orderDetail, setOrderDetail] = useState<KanbanOrder | null>(null);
    const [orderLoading, setOrderLoading] = useState(false);
    const [orderError, setOrderError] = useState<string | null>(null);
    const [clientDetailOpen, setClientDetailOpen] = useState(false);
    const [clientDetail, setClientDetail] = useState<ClientDetail | null>(null);
    const [clientDetailLoading, setClientDetailLoading] = useState(false);
    const [clientDetailError, setClientDetailError] = useState<string | null>(null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const messagesContainerRef = useRef<HTMLDivElement>(null);

    // Cuando se abre/cambia un chat, saltamos al ultimo mensaje sin animacion.
    // Cuando llegan mensajes nuevos en el mismo chat, hacemos scroll suave si
    // el operador estaba mirando el final (no si scrolleo arriba para leer).
    const lastChatIdRef = useRef<string | null>(null);
    const lastMessageCountRef = useRef<number>(0);

    useEffect(() => {
        if (!selectedChat) return;
        const end = messagesEndRef.current;
        if (!end) return;

        const chatChanged = lastChatIdRef.current !== selectedChat.id;
        const messageCount = selectedChat.messages.length;
        const messagesGrew = messageCount > lastMessageCountRef.current;

        if (chatChanged) {
            // Diferimos al siguiente frame: con justify-end + min-h-full el layout
            // pinea contenido abajo, pero el scrollHeight del padre no esta listo
            // hasta que el browser termina de calcular el flex.
            requestAnimationFrame(() => end.scrollIntoView({ behavior: 'auto', block: 'end' }));
        } else if (messagesGrew) {
            // Cada mensaje nuevo (entrante o saliente) lleva al operador al fondo
            // de la conversacion. Smooth para que se vea de donde aparece.
            requestAnimationFrame(() => end.scrollIntoView({ behavior: 'smooth', block: 'end' }));
        }

        lastChatIdRef.current = selectedChat.id;
        lastMessageCountRef.current = messageCount;
    }, [selectedChat]);

    // Deep-link desde el modal de orden: /chats?chat=<id> selecciona la conversacion.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const requested = params.get('chat');
        if (!requested) return;
        selectChat(requested);
    }, [selectChat]);

    // Marca mensajes como leidos en Meta (doble chulito azul) cuando el operador
    // tiene la conversacion abierta Y la pestana del navegador esta visible. El
    // backend valida internamente el setting `whatsapp_read_receipts` antes de
    // tocar la API de Meta — el frontend invoca a ciegas.
    //
    // Se reactiva cada vez que llega un mensaje nuevo en el chat abierto (porque
    // `selectedChat` cambia con el polling) y cuando la pestana vuelve a primer
    // plano. El backend hace throttle por chat para no saturar la cola.
    const lastMarkedMessageIdRef = useRef<string | null>(null);
    useEffect(() => {
        if (!selectedChat) return;
        if (typeof document === 'undefined') return;
        if (document.visibilityState !== 'visible') return;

        const latestInbound = [...selectedChat.messages].reverse().find((m) => m.sender === 'client');
        if (!latestInbound) return;
        if (lastMarkedMessageIdRef.current === latestInbound.id) return;

        lastMarkedMessageIdRef.current = latestInbound.id;
        void apiFetch(`/api/v1/chats/${selectedChat.id}/mark-read`, { method: 'POST' }).catch(() => {
            // Best-effort: si falla la red, intentamos en el siguiente trigger.
            lastMarkedMessageIdRef.current = null;
        });
    }, [selectedChat]);

    // Cuando el operador deja la pestana y vuelve, reintentamos marcar como
    // leido por si llego un mensaje mientras estaba fuera (el efecto anterior
    // no corre en visibilitychange porque `selectedChat` no cambia).
    useEffect(() => {
        const onVisibility = () => {
            if (document.visibilityState !== 'visible') return;
            if (!selectedChat) return;
            const latestInbound = [...selectedChat.messages].reverse().find((m) => m.sender === 'client');
            if (!latestInbound) return;
            if (lastMarkedMessageIdRef.current === latestInbound.id) return;
            lastMarkedMessageIdRef.current = latestInbound.id;
            void apiFetch(`/api/v1/chats/${selectedChat.id}/mark-read`, { method: 'POST' }).catch(() => {
                lastMarkedMessageIdRef.current = null;
            });
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, [selectedChat]);

    // Reset del tracker al cambiar de chat: cada conversacion comienza con su
    // propio "ultimo marcado".
    useEffect(() => {
        lastMarkedMessageIdRef.current = null;
    }, [selectedChatId]);

    const openOrderDetail = async (orderId: string) => {
        setOrderLoading(true);
        setOrderError(null);
        try {
            const res = await apiFetch(`/api/v1/orders/${orderId}`);
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al cargar la orden.');
            }
            const json = await res.json();
            setOrderDetail((json as { data: KanbanOrder }).data);
        } catch (err) {
            setOrderError(err instanceof Error ? err.message : 'Error al cargar la orden.');
        } finally {
            setOrderLoading(false);
        }
    };

    const openClientDetail = async (chatId: string) => {
        setClientDetailOpen(true);
        setClientDetailLoading(true);
        setClientDetailError(null);
        setClientDetail(null);
        try {
            const res = await apiFetch(`/api/v1/chats/${chatId}/client`);
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al cargar el cliente.');
            }
            const json = await res.json();
            setClientDetail((json as { data: ClientDetail }).data);
        } catch (err) {
            setClientDetailError(err instanceof Error ? err.message : 'Error al cargar el cliente.');
        } finally {
            setClientDetailLoading(false);
        }
    };

    const props = useSharedData();
    const permissions = props.permissions ?? [];
    const isSystem = props.role?.is_system ?? false;
    const canUpdate = isSystem || permissions.includes('chats.update');

    const sortedChats = useMemo(() => chats, [chats]);

    // Inicializamos los campos del modal una sola vez al abrir. NO podemos
    // depender de `selectedChat` porque el polling lo reemplaza cada 5s y
    // borraria lo que el operador esta escribiendo.
    const openContactEditor = () => {
        if (!selectedChat) return;
        setContactName(selectedChat.client_name ?? '');
        setContactPhone(selectedChat.client_phone);
        setContactNotes(selectedChat.contact_notes ?? '');
        setContactError(null);
        setEditingContact(true);
    };

    const handleSend = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!draft.trim() || sending || !canUpdate) return;
        setSending(true);
        setSendError(null);
        try {
            await sendMessage(draft);
            setDraft('');
        } catch (err) {
            setSendError(err instanceof Error ? err.message : 'Error al enviar mensaje.');
        } finally {
            setSending(false);
        }
    };

    const handleToggleBot = async () => {
        if (!selectedChat || botBusy || !canUpdate) return;
        setBotBusy(true);
        setBotError(null);
        try {
            await setBotPaused(!selectedChat.bot_paused);
        } catch (err) {
            setBotError(err instanceof Error ? err.message : 'Error al cambiar estado del bot.');
        } finally {
            setBotBusy(false);
        }
    };

    const handleSaveContact = async (e: React.FormEvent) => {
        e.preventDefault();
        if (contactSaving || !canUpdate) return;
        setContactSaving(true);
        setContactError(null);
        try {
            await updateContact({
                name: contactName.trim() || null,
                phone: contactPhone.trim() || null,
                notes: contactNotes.trim() || null,
            });
            setEditingContact(false);
        } catch (err) {
            setContactError(err instanceof Error ? err.message : 'Error al guardar contacto.');
        } finally {
            setContactSaving(false);
        }
    };

    return (
        <PageShell title="Chats">
            {/* Wrapper con techo de altura explicito y overflow-hidden:
                - Mobile: 100svh - 4rem (solo el AppSidebarHeader h-16)
                - md+: 100svh - 5rem (header + 1rem del margen `m-2` que agrega Sidebar variant="inset")
                Asi el unico scroll posible queda dentro del contenedor de mensajes. */}
            <div className="flex h-[calc(100svh-4rem)] flex-col overflow-hidden md:h-[calc(100svh-5rem)]">
                {loading && chats.length === 0 && !error ? (
                    <ChatsSkeleton />
                ) : (
                    <>
                        {error && (
                            <div className="mx-4 mt-4 flex items-center gap-2 rounded-lg border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 px-4 py-2 text-sm text-[color:var(--color-status-critical)]">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                <span>{error}</span>
                            </div>
                        )}

                        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden p-2 sm:p-4 md:flex-row">
                            <div
                                className={`bg-muted/30 flex min-h-0 w-full flex-col overflow-hidden rounded-lg md:w-1/3 ${
                                    isMobile && selectedChatId !== null ? 'hidden' : ''
                                }`}
                            >
                                <div className="border-b p-2 text-sm font-semibold">Conversaciones</div>
                                <div className="border-b p-2">
                                    <div className="relative">
                                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2" />
                                        <Input
                                            type="search"
                                            value={searchInput}
                                            onChange={(e) => setSearchInput(e.target.value)}
                                            placeholder="Buscar nombre, teléfono, #orden o mensaje"
                                            className="h-9 pl-8 text-sm"
                                        />
                                    </div>
                                </div>
                                <div className="flex-1 overflow-y-auto">
                                    {sortedChats.length === 0 ? (
                                        <p className="text-muted-foreground p-4 text-center text-sm">
                                            {searchTerm ? 'Sin resultados para la búsqueda.' : 'Sin conversaciones'}
                                        </p>
                                    ) : (
                                        sortedChats.map((c) => (
                                            <Card
                                                key={c.id}
                                                className={`m-2 cursor-pointer ${selectedChatId === c.id ? 'ring-primary ring-2' : ''}`}
                                                onClick={() => selectChat(c.id)}
                                            >
                                                <CardHeader className="flex flex-row items-center justify-between gap-2 p-3 pb-0">
                                                    <CardTitle className="flex flex-wrap items-center gap-1 text-base">
                                                        {c.client_name ?? c.client_phone}
                                                        <ChatSourceBadge source={c.source} />
                                                        {c.handoff_requested_at && (
                                                            <span
                                                                title="Bot solicitó intervención humana"
                                                                className="ml-1 inline-block h-2 w-2 rounded-full bg-[color:var(--color-status-warning)]"
                                                            />
                                                        )}
                                                        {c.latest_order && (
                                                            <OrderBadge
                                                                order={c.latest_order}
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    void openOrderDetail(c.latest_order!.id);
                                                                }}
                                                            />
                                                        )}
                                                    </CardTitle>
                                                    <span className="text-muted-foreground text-xs">{timeAgo(c.last_message_at)}</span>
                                                </CardHeader>
                                                <CardContent className="text-muted-foreground truncate p-3 pt-1 text-xs">
                                                    {c.last_message?.body ?? 'Sin mensajes'}
                                                </CardContent>
                                            </Card>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div
                                className={`bg-background flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg ${
                                    isMobile && selectedChatId === null ? 'hidden' : ''
                                }`}
                            >
                                <div className="flex items-center justify-between gap-2 border-b p-2">
                                    <div className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                        {isMobile && selectedChat && (
                                            <button
                                                type="button"
                                                onClick={() => selectChat(null)}
                                                className="hover:bg-muted -ml-1 inline-flex items-center justify-center rounded p-1"
                                                title="Volver al listado"
                                                aria-label="Volver al listado"
                                            >
                                                <ArrowLeft className="h-4 w-4" />
                                            </button>
                                        )}
                                        {selectedChat ? (
                                            <button
                                                type="button"
                                                onClick={() => void openClientDetail(selectedChat.id)}
                                                className="hover:text-primary text-left underline-offset-2 hover:underline"
                                                title="Ver detalle del cliente"
                                            >
                                                {selectedChat.client_name ?? selectedChat.client_phone}
                                            </button>
                                        ) : (
                                            <span>Conversación</span>
                                        )}
                                        {selectedChat && (
                                            <span className="text-muted-foreground text-xs font-normal">{selectedChat.client_phone}</span>
                                        )}
                                        {selectedChat && <ChatSourceBadge source={selectedChat.source} />}
                                        {selectedChat?.latest_order && (
                                            <OrderBadge
                                                order={selectedChat.latest_order}
                                                onClick={() => void openOrderDetail(selectedChat.latest_order!.id)}
                                            />
                                        )}
                                    </div>
                                    {selectedChat && (
                                        <div className="flex items-center gap-2">
                                            {selectedChat.handoff_requested_at && (
                                                <span className="rounded bg-[color:var(--color-status-warning)]/15 px-2 py-1 text-[10px] font-medium text-[color:var(--color-status-warning)]">
                                                    Handoff solicitado
                                                </span>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={!canUpdate}
                                                onClick={openContactEditor}
                                                title={canUpdate ? 'Editar contacto' : 'Necesitas permiso chats.update'}
                                            >
                                                <Pencil className="h-4 w-4" />
                                                Contacto
                                            </Button>
                                            <Button
                                                variant={selectedChat.bot_paused ? 'default' : 'outline'}
                                                size="sm"
                                                disabled={!canUpdate || botBusy}
                                                onClick={handleToggleBot}
                                                title={
                                                    canUpdate
                                                        ? selectedChat.bot_paused
                                                            ? 'Reanudar bot'
                                                            : 'Pausar bot e intervenir'
                                                        : 'Necesitas permiso chats.update'
                                                }
                                            >
                                                {selectedChat.bot_paused ? (
                                                    <>
                                                        <Play className="h-4 w-4" /> Reanudar bot
                                                    </>
                                                ) : (
                                                    <>
                                                        <Pause className="h-4 w-4" /> Pausar bot
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    )}
                                </div>

                                {botError && <p className="text-destructive px-3 pt-2 text-xs">{botError}</p>}

                                {selectedChat?.bot_paused && (
                                    <div className="flex items-center gap-2 border-b bg-[color:var(--color-status-warning)]/10 px-3 py-2 text-xs text-[color:var(--color-status-warning)]">
                                        <Bot className="h-4 w-4" />
                                        <span>
                                            Bot pausado{selectedChat.handoff_reason ? ` — motivo: ${selectedChat.handoff_reason}` : ''}. Las
                                            respuestas automaticas estan detenidas.
                                        </span>
                                    </div>
                                )}

                                <div ref={messagesContainerRef} className="flex-1 overflow-y-auto">
                                    {/* min-h-full + justify-end: cuando hay pocos mensajes el wrapper igual ocupa
                            todo el alto y los mensajes quedan abajo (cerca del input). Cuando hay
                            muchos crece hacia arriba y el scroll se activa en el padre. */}
                                    <div className="flex min-h-full flex-col justify-end gap-2 p-4">
                                        {selectedChat ? (
                                            selectedChat.messages.map((m) => (
                                                <div key={m.id} className={`flex ${m.sender === 'client' ? 'justify-start' : 'justify-end'}`}>
                                                    <div
                                                        className={`max-w-xs rounded-xl px-3 py-2 text-sm ${
                                                            m.sender === 'client'
                                                                ? 'bg-card border-border text-foreground border text-left'
                                                                : m.sender === 'bot'
                                                                  ? 'bg-secondary text-secondary-foreground text-right'
                                                                  : 'bg-primary text-primary-foreground text-right'
                                                        }`}
                                                    >
                                                        {m.media_type ? (
                                                            <ChatMessageMedia
                                                                type={m.media_type}
                                                                url={m.media_url}
                                                                mime={m.media_mime}
                                                                body={m.body}
                                                            />
                                                        ) : m.body.startsWith('[location]') ? (
                                                            <ChatMessageMedia type={null} url={null} mime={null} body={m.body} />
                                                        ) : (
                                                            <span className="whitespace-pre-wrap">{m.body}</span>
                                                        )}
                                                        <div
                                                            className={`mt-1 flex items-center justify-end gap-1 text-[10px] ${
                                                                m.sender === 'client' ? 'text-muted-foreground' : 'opacity-80'
                                                            }`}
                                                        >
                                                            <span>
                                                                {m.sender === 'bot' ? 'bot · ' : m.sender === 'operator' ? 'tú · ' : ''}
                                                                {formatTime(m.sent_at)}
                                                            </span>
                                                            {m.sender !== 'client' && <ChatMessageStatusTicks status={m.status} />}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <p className="text-muted-foreground py-12 text-center text-sm">Selecciona una conversación</p>
                                        )}
                                        <div ref={messagesEndRef} aria-hidden="true" />
                                    </div>
                                </div>

                                {selectedChat && (
                                    <form noValidate onSubmit={handleSend} className="border-t p-3">
                                        {sendError && <p className="text-destructive mb-2 text-xs">{sendError}</p>}
                                        {!canUpdate && (
                                            <p className="text-muted-foreground mb-2 text-xs">
                                                Solo lectura — necesitas el permiso chats.update para responder.
                                            </p>
                                        )}
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="text"
                                                value={draft}
                                                onChange={(e) => setDraft(e.target.value)}
                                                placeholder="Escribe un mensaje..."
                                                className="border-input bg-background focus:ring-primary flex-1 rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:outline-none"
                                                disabled={sending || !canUpdate}
                                            />
                                            <button
                                                type="submit"
                                                disabled={sending || !draft.trim() || !canUpdate}
                                                className="bg-primary text-primary-foreground inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm hover:opacity-90 disabled:opacity-50"
                                            >
                                                <Send className="h-4 w-4" />
                                                Enviar
                                            </button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </div>

            <ClientDetailModal
                isOpen={clientDetailOpen}
                onClose={() => setClientDetailOpen(false)}
                detail={clientDetail}
                loading={clientDetailLoading}
                error={clientDetailError}
                onSelectOrder={(orderId) => void openOrderDetail(orderId)}
            />

            <OrderDetailModal order={orderDetail} isOpen={!!orderDetail} onClose={() => setOrderDetail(null)} />

            {orderLoading && (
                <div className="bg-background fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-lg border px-4 py-2 text-sm shadow-lg">
                    Cargando orden…
                </div>
            )}
            {orderError && !orderDetail && (
                <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-lg border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 px-4 py-2 text-sm text-[color:var(--color-status-critical)] shadow-lg">
                    {orderError}
                </div>
            )}

            <Dialog open={editingContact} onOpenChange={setEditingContact}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Editar contacto</DialogTitle>
                    </DialogHeader>
                    <form noValidate onSubmit={handleSaveContact} className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="contact-name">Nombre</Label>
                            <Input
                                id="contact-name"
                                value={contactName}
                                onChange={(e) => setContactName(sanitizePlainText(e.target.value, 120, false, false))}
                                placeholder="Nombre del cliente"
                                maxLength={120}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="contact-phone">Número de WhatsApp</Label>
                            <Input
                                id="contact-phone"
                                value={contactPhone}
                                onChange={(e) => setContactPhone(e.target.value)}
                                placeholder="573001234567"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="contact-notes">Notas (opcional)</Label>
                            <textarea
                                id="contact-notes"
                                value={contactNotes}
                                onChange={(e) => setContactNotes(sanitizePlainText(e.target.value, 2000, true, false))}
                                placeholder="Datos relevantes del cliente"
                                rows={4}
                                maxLength={2000}
                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none"
                            />
                        </div>
                        {contactError && <p className="text-destructive text-xs">{contactError}</p>}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditingContact(false)} disabled={contactSaving}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={contactSaving}>
                                {contactSaving ? 'Guardando…' : 'Guardar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </PageShell>
    );
}
