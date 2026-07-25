import { AddressFields, type AddressValue } from '@/components/clients/address-fields';
import { ChatActivityModal, type ChatAuditEntry } from '@/components/chats/chat-activity-modal';
import { ChatComposer } from '@/components/chats/chat-composer';
import type { SharedContact } from '@/components/chats/chat-contact-card';
import { ChatLightbox } from '@/components/chats/chat-lightbox';
import { ChatMessageBubble } from '@/components/chats/chat-message-bubble';
import { ChatPresence } from '@/components/chats/chat-presence';
import { ChatSourceBadge } from '@/components/chats/chat-source-badge';
import { formatPhoneDisplay } from '@/lib/phone';
import { ClientDetailModal, type ClientDetail } from '@/components/chats/client-detail-modal';
import { ChatCartActions } from '@/components/chats/chat-cart-actions';
import { OrderDetailModal } from '@/components/orders/order-detail-modal';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChatsSkeleton } from '@/components/ui/chats-skeleton';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ReasonTooltip } from '@/components/ui/field-hint';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readSoundPreference, writeSoundPreference } from '@/hooks/use-chat-notifications';
import { useChats, type ChatFilter, type ChatLatestOrder, type ChatSummary } from '@/hooks/use-chats';
import { isFocusInInput } from '@/hooks/use-keyboard-shortcut';
import { useIsMobile } from '@/hooks/use-mobile';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import type { KanbanOrder } from '@/hooks/use-orders';
import { useQuickReplies } from '@/hooks/use-quick-replies';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { APP_LOCALE, APP_TIMEZONE, timeAgo, waitingFor } from '@/lib/datetime';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { shortOrderCode } from '@/lib/order-code';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { useSharedData } from '@/lib/shared-data';
import { cn } from '@/lib/utils';

import { AlertCircle, ArrowLeft, Bot, History, MessageCircle, Pause, Pencil, Play, Search, Send, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

/** Umbrales fijos de §8.4b punto 2. Configurables solo si alguien los pide. */
const WAIT_AMBER_MIN = 5;
const WAIT_RED_MIN = 15;

// El control de bot (pausar/reanudar) y su banner se ocultan por ahora: la
// automatización (n8n) todavía no está operativa, así que el toggle no cambia
// nada y confundía. Volver a `true` cuando el bot responda de verdad.
const SHOW_BOT_CONTROLS = false;

const FILTERS: { value: ChatFilter; label: string }[] = [
    { value: 'pending', label: 'Pendientes' },
    { value: 'all', label: 'Todos' },
    { value: 'closed', label: 'Cerrados' },
];

type PlatformFilter = 'whatsapp' | 'sms';
const PLATFORM_FILTERS: { value: PlatformFilter; label: string }[] = [
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'sms', label: 'SMS' },
];

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
            {/* ID corto del pedido: el UUID completo (36 chars) dominaba el
                header/las cards y se confundía con el id de la conversación. */}
            <span className="bg-muted text-foreground rounded px-1.5 py-0.5 font-mono">#{order.id.slice(0, 8).toUpperCase()}</span>
            <span className={`rounded px-1.5 py-0.5 ${meta.badgeClass}`}>{meta.label}</span>
        </button>
    );
}

/**
 * "esperando hace 12 min" con umbral ámbar a los 5 y rojo a los 15 (§8.4b).
 *
 * El color no viaja solo: el texto dice el tiempo exacto. Un operador daltónico
 * —o cualquiera mirando de reojo— necesita el número, no el tono.
 */
function WaitingBadge({ since }: { since: string }) {
    const minutes = Math.floor((Date.now() - new Date(since).getTime()) / 60_000);
    const tone =
        minutes >= WAIT_RED_MIN
            ? 'text-[color:var(--color-status-critical)]'
            : minutes >= WAIT_AMBER_MIN
              ? 'text-[color:var(--color-status-warning)]'
              : 'text-muted-foreground';

    // El tooltip da la hora exacta a la que el cliente escribió (§8.4c). El color
    // no viaja solo: el texto ya dice el tiempo, el título agrega la hora.
    const at = new Date(since).toLocaleTimeString(APP_LOCALE, { hour: '2-digit', minute: '2-digit', timeZone: APP_TIMEZONE });

    return (
        <span title={`El cliente escribió a las ${at} y todavía no tiene respuesta.`} className={cn('text-[10px] font-medium', tone)}>
            esperando hace {waitingFor(since)}
        </span>
    );
}

export default function ChatsPage() {
    const token = useToken();
    const navigate = useNavigate();
    const isMobile = useIsMobile();
    const { replies: quickReplies } = useQuickReplies(token);
    const [searchInput, setSearchInput] = useState('');
    const [searchTerm, setSearchTerm] = useState('');
    const [filter, setFilter] = useState<ChatFilter>('all');
    const [channelFilter, setChannelFilter] = useState<string | null>(null);
    // Plataforma activa. Arranca en WhatsApp (caso común); si la empresa no
    // tiene canal WhatsApp configurado, el efecto de abajo lo baja a SMS.
    const [sourceFilter, setSourceFilter] = useState<PlatformFilter>('whatsapp');
    const platformDefaultRef = useRef(false);
    // El beep lo dispara el hook del sidebar; acá solo se cambia la preferencia
    // (misma clave de localStorage). Arranca apagado: en una cocina un sonido
    // sorpresa es peor que ninguno.
    const [soundOn, setSoundOn] = useState(readSoundPreference);

    useEffect(() => {
        const handle = setTimeout(() => setSearchTerm(searchInput), 300);
        return () => clearTimeout(handle);
    }, [searchInput]);

    const {
        chats,
        channels,
        selectedChat,
        selectedChatId,
        selectChat,
        sendMessage,
        sendAttachment,
        retryMessage,
        setBotPaused,
        updateContact,
        sendReceipt,
        rejectProof,
        approveOrder,
        refreshSelected,
        loading,
        error,
    } = useChats(token, {
        search: searchTerm,
        filter,
        // El filtro por número solo aplica dentro de WhatsApp.
        channelId: sourceFilter === 'whatsapp' ? channelFilter : null,
        source: sourceFilter,
    });

    // Default por plataforma: WhatsApp si hay canal configurado, si no SMS. Se
    // resuelve una sola vez, tras la primera carga (channels viene del meta).
    useEffect(() => {
        if (platformDefaultRef.current || loading) return;
        platformDefaultRef.current = true;
        if (channels.length === 0) setSourceFilter('sms');
    }, [loading, channels]);

    const [sending, setSending] = useState(false);
    const [botBusy, setBotBusy] = useState(false);
    const [botError, setBotError] = useState<string | null>(null);
    const [retryingId, setRetryingId] = useState<string | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const [editingContact, setEditingContact] = useState(false);
    const [contactName, setContactName] = useState('');
    const [contactPhone, setContactPhone] = useState('');
    // Dirección estructurada del contacto (misma que /clients). Las notas se
    // unificaron en client_notes: se ven/agregan en el detalle del cliente.
    const [contactAddress, setContactAddress] = useState<AddressValue>({
        municipality_dane_code: null,
        municipality_label: null,
        neighborhood: null,
        address: null,
    });
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

    // Deep-link: /chats?chat=<id> abre la conversacion (lo usa la notificacion
    // push); /chats?channel=<id> pre-filtra por canal (lo usa la tarjeta de la
    // pantalla de WhatsApp).
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const requestedChat = params.get('chat');
        const requestedChannel = params.get('channel');
        if (requestedChannel) setChannelFilter(requestedChannel);
        if (requestedChat) selectChat(requestedChat);
    }, [selectChat]);

    // Marca mensajes como leidos (doble chulito azul) cuando el operador tiene
    // la conversacion abierta Y la pestana del navegador esta visible. El
    // backend valida internamente el setting `whatsapp_read_receipts` antes de
    // tocar al proveedor — el frontend invoca a ciegas.
    //
    // El MISMO request registra la presencia del operador en el chat (§5.7), asi
    // que se dispara aunque los read receipts esten apagados.
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

    // Foco al compositor al abrir una conversacion: el operador entra a
    // responder, no a mirar. En mobile NO, porque levantaria el teclado y
    // taparia la conversacion que acaba de abrir.
    useEffect(() => {
        if (!selectedChatId || isMobile) return;
        const input = messagesContainerRef.current?.parentElement?.querySelector<HTMLInputElement>('form input[type="text"]');
        input?.focus();
    }, [selectedChatId, isMobile]);

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

    const [clientDetailChatId, setClientDetailChatId] = useState<string | null>(null);

    const openClientDetail = async (chatId: string) => {
        setClientDetailOpen(true);
        setClientDetailChatId(chatId);
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
    // Aprobar pedidos desde el panel del chat (F4) exige permiso de órdenes.
    const canApproveOrders = isSystem || permissions.includes('orders.update');
    // `chats.audit` es owner/admin por template: el operador no administra su
    // propia auditoria. El backend valida igual — esconder el boton no es control.
    const canAudit = isSystem || permissions.includes('chats.audit');

    const [activityOpen, setActivityOpen] = useState(false);
    const [activityEntries, setActivityEntries] = useState<ChatAuditEntry[]>([]);
    const [activityLoading, setActivityLoading] = useState(false);
    const [activityError, setActivityError] = useState<string | null>(null);

    const openActivity = async (chatId: string) => {
        setActivityOpen(true);
        setActivityLoading(true);
        setActivityError(null);
        setActivityEntries([]);
        try {
            const res = await apiFetch(`/api/v1/chats/${chatId}/audit`);
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al cargar la actividad.');
            }
            const json = await res.json();
            setActivityEntries((json as { data: ChatAuditEntry[] }).data);
        } catch (err) {
            setActivityError(err instanceof Error ? err.message : 'Error al cargar la actividad.');
        } finally {
            setActivityLoading(false);
        }
    };

    const sortedChats = useMemo(() => chats, [chats]);
    // El filtro por canal solo aparece con dos o mas: con uno solo seria ruido
    // en la pantalla de alguien que no tiene nada que filtrar (§8.4 punto 1).
    const showChannelFilter = channels.length >= 2;

    // Inicializamos los campos del modal una sola vez al abrir. NO podemos
    // depender de `selectedChat` porque el polling lo reemplaza cada 30s y
    // borraria lo que el operador esta escribiendo.
    const openContactEditor = () => {
        if (!selectedChat) return;
        setContactName(selectedChat.client_name ?? '');
        setContactPhone(selectedChat.client_phone);
        setContactAddress({
            municipality_dane_code: selectedChat.contact_municipality_dane_code ?? null,
            municipality_label: null,
            neighborhood: selectedChat.contact_neighborhood ?? null,
            address: selectedChat.contact_address ?? null,
        });
        setContactError(null);
        setEditingContact(true);
    };

    const handleSendText = async (body: string) => {
        setSending(true);
        try {
            await sendMessage(body);
        } finally {
            setSending(false);
        }
    };

    const handleSendAttachment = async (file: File, kind: string, caption: string) => {
        setSending(true);
        try {
            await sendAttachment(file, kind, caption);
        } finally {
            setSending(false);
        }
    };

    const handleRetry = async (messageId: string) => {
        setRetryingId(messageId);
        try {
            await retryMessage(messageId);
        } catch {
            // El estado del mensaje se refresca en el siguiente poll; un toast
            // acá taparía la burbuja que el operador está mirando.
        } finally {
            setRetryingId(null);
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
                address: contactAddress.address?.trim() || null,
                neighborhood: contactAddress.neighborhood?.trim() || null,
                municipality_dane_code: contactAddress.municipality_dane_code || null,
            });
            setEditingContact(false);
        } catch (err) {
            setContactError(err instanceof Error ? err.message : 'Error al guardar contacto.');
        } finally {
            setContactSaving(false);
        }
    };

    /** Contacto compartido → prellenar el editor de contacto de ESTE chat. */
    const saveSharedContact = (contact: SharedContact) => {
        setContactName(contact.name ?? '');
        setContactPhone(contact.phones?.[0] ?? '');
        setContactAddress({ municipality_dane_code: null, municipality_label: null, neighborhood: null, address: null });
        setContactError(null);
        setEditingContact(true);
    };

    const channel = selectedChat?.channel ?? null;
    const channelDown = Boolean(channel && !channel.can_send);
    // Los chats SMS son de solo aviso: el hilo espejo de las notificaciones que
    // manda la plataforma (SmsChatLogger). No hay respuesta entrante ni forma de
    // contestar un SMS desde acá, así que el compositor va deshabilitado.
    const isSmsChat = selectedChat?.source === 'sms';
    const composerDisabled = !canUpdate || channelDown || isSmsChat;
    const composerReason = !canUpdate
        ? 'Solo lectura — necesitás el permiso «Editar chats» para responder.'
        : isSmsChat
          ? 'Canal de SMS de solo aviso: acá solo se ven los mensajes que envió la plataforma, no se puede responder.'
          : channelDown
            ? `${channel?.label ?? 'Este número'} está desconectado. Los mensajes no se enviarán.`
            : null;

    // Todas las imágenes de la conversación, para que el lightbox pueda
    // recorrerlas con flechas (§8.4b punto 13) en vez de ser un callejón.
    const chatImages = useMemo(() => {
        if (!selectedChat) return [];
        return selectedChat.messages
            .filter((m) => m.media_type === 'image' && m.media_url)
            .map((m) => ({ url: m.media_url as string, caption: m.media_payload?.caption ?? null }));
    }, [selectedChat]);

    const openImage = (url: string) => {
        const idx = chatImages.findIndex((img) => img.url === url);
        setLightboxIndex(idx >= 0 ? idx : null);
    };

    // Variables de las respuestas rápidas (§8.4b punto 14), resueltas al insertar.
    // `{{sede}}` sale de la etiqueta del canal (que suele ser el nombre de la sede).
    const quickReplyVars = useMemo(
        () => ({
            cliente: selectedChat?.client_name ?? null,
            pedido: selectedChat?.latest_order ? `#${shortOrderCode(selectedChat.latest_order.id)}` : null,
            sede: channel?.label ?? null,
        }),
        [selectedChat, channel],
    );

    // Fallback client-side del link de carta (§8.4b punto 8): menú de la sede
    // activa (?branch=CWP) o, sin sede con token, el menú por empresa.
    const nit = props.activeCompany?.nit ?? '';
    const branchToken = props.activeBranch?.menu_qr_token ?? null;
    const menuUrl = branchToken
        ? `${window.location.origin}/menus?branch=${branchToken}`
        : nit
          ? `${window.location.origin}/menus/${nit}`
          : null;

    // Opción unificada "Enviar la carta": link corto con sesión de seguimiento
    // (/menus?cart={uuid}). Cuando el cliente confirma el pedido desde la
    // carta, el backend precarga en esta conversación lo que seleccionó. Si el
    // backend no puede (sede sin carta digital, error transitorio), cae al
    // link estático de siempre — el cliente igual recibe la carta.
    const requestMenuLink = async (): Promise<string | null> => {
        if (!selectedChatId) return menuUrl;
        try {
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/menu-link`, { method: 'POST' });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) return menuUrl;
            const token = (json as { data: { token: string } }).data.token;
            return `${window.location.origin}/menus?cart=${token}`;
        } catch {
            return menuUrl;
        }
    };

    // Cambio de tipo en caliente (F5): PATCH /orders/{id}/order-type. El total
    // se recalcula server-side (fee de domicilio entra/sale como línea).
    const changeOrderType = async (
        order: { id: string },
        to: 'pickup' | 'delivery',
        address?: string,
    ): Promise<void> => {
        const res = await apiFetch(`/api/v1/orders/${order.id}/order-type`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_type: to, delivery_address: to === 'delivery' ? (address ?? null) : null }),
        });
        if (!res.ok) {
            const json = await res.json().catch(() => ({}));
            const body = json as { message?: string; errors?: Record<string, string[]> };
            const detail = body.errors ? Object.values(body.errors).flat()[0] : undefined;
            throw new Error(detail ?? body.message ?? 'No se pudo cambiar el tipo del pedido.');
        }
        await refreshSelected();
    };

    const createOrderForChat = () => {
        if (!selectedChat) return;
        // Reutiliza el flujo de caja (no reimplementa creación de pedido): navega
        // con el teléfono como pedido a domicilio. Caja lee `client_phone` de la
        // URL igual que ya lee `table`.
        // ponytail: solo prellena el teléfono. Nombre y dirección quedan
        // pendientes — caja no tiene campo de nombre y el chat no carga la
        // dirección del Contact (ver pendientes.md). El operador los completa.
        navigate(`/orders/cashier?client_phone=${encodeURIComponent(selectedChat.client_phone)}`);
    };

    // Navegación por teclado de la bandeja (§8.4b punto 12): j/k mover, Enter
    // enfocar el compositor, Esc volver, / buscar. Respeta el foco en inputs para
    // no pisar la escritura del compositor ni la búsqueda.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (isFocusInInput(e.target)) return;
            const key = e.key.toLowerCase();
            if (key === 'j' || key === 'k') {
                if (sortedChats.length === 0) return;
                e.preventDefault();
                const idx = sortedChats.findIndex((c) => c.id === selectedChatId);
                const target =
                    idx === -1
                        ? key === 'j'
                            ? 0
                            : sortedChats.length - 1
                        : key === 'j'
                          ? Math.min(sortedChats.length - 1, idx + 1)
                          : Math.max(0, idx - 1);
                selectChat(sortedChats[target].id);
            } else if (e.key === 'Enter') {
                if (!selectedChatId) return;
                e.preventDefault();
                document.querySelector<HTMLInputElement>('form input[type="text"]')?.focus();
            } else if (e.key === 'Escape') {
                if (selectedChatId) {
                    e.preventDefault();
                    selectChat(null);
                }
            } else if (e.key === '/') {
                e.preventDefault();
                searchInputRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [sortedChats, selectedChatId, selectChat]);

    return (
        <PageShell title="Chats">
            {/* El wrapper FLEXEA para llenar el espacio entre el AppSidebarHeader y
                el AppFooterMeta. SidebarInset es una columna flex `min-h-svh`, así
                que `flex-1 min-h-0` toma exactamente la altura disponible. NO se
                hardcodea `100svh - header`: esa cuenta ignoraba el footer (siempre
                presente) y los banners condicionales, y sumaba un scroll vertical
                de más en toda la página. Con flex-1 el único scroll posible queda
                dentro del contenedor de mensajes. */}
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
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
                                <div className="flex items-center justify-between border-b p-2 text-sm font-semibold">
                                    <span>Conversaciones</span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const next = !soundOn;
                                            setSoundOn(next);
                                            writeSoundPreference(next);
                                        }}
                                        className="hover:bg-muted inline-flex h-8 w-8 items-center justify-center rounded"
                                        aria-pressed={soundOn}
                                        title={soundOn ? 'Silenciar el aviso de mensajes nuevos' : 'Activar el aviso sonoro de mensajes nuevos'}
                                    >
                                        {soundOn ? <Volume2 className="h-4 w-4" /> : <VolumeX className="text-muted-foreground h-4 w-4" />}
                                    </button>
                                </div>
                                <div className="space-y-2 border-b p-2">
                                    <div className="relative">
                                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2" />
                                        <Input
                                            ref={searchInputRef}
                                            type="search"
                                            value={searchInput}
                                            onChange={(e) => setSearchInput(e.target.value)}
                                            placeholder="Buscar nombre, teléfono, #orden o mensaje  ( / )"
                                            className="h-9 pl-8 text-sm"
                                        />
                                    </div>

                                    {/* Filtro por plataforma (WhatsApp / SMS). Mismo look que
                                        los chips de estado; es el filtro primario de la bandeja. */}
                                    <div className="flex flex-wrap gap-1" role="group" aria-label="Filtrar por plataforma">
                                        {PLATFORM_FILTERS.map((p) => (
                                            <button
                                                key={p.value}
                                                type="button"
                                                onClick={() => setSourceFilter(p.value)}
                                                aria-pressed={sourceFilter === p.value}
                                                className={cn(
                                                    'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                                    sourceFilter === p.value
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background hover:bg-muted',
                                                )}
                                            >
                                                {p.label}
                                            </button>
                                        ))}
                                    </div>

                                    <div className="flex flex-wrap gap-1" role="group" aria-label="Filtrar conversaciones">
                                        {FILTERS.map((f) => (
                                            <button
                                                key={f.value}
                                                type="button"
                                                onClick={() => setFilter(f.value)}
                                                aria-pressed={filter === f.value}
                                                className={cn(
                                                    'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                                    filter === f.value
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background hover:bg-muted',
                                                )}
                                            >
                                                {f.label}
                                            </button>
                                        ))}
                                    </div>

                                    {showChannelFilter && sourceFilter === 'whatsapp' && (
                                        <div className="flex flex-wrap gap-1" role="group" aria-label="Filtrar por número">
                                            <button
                                                type="button"
                                                onClick={() => setChannelFilter(null)}
                                                aria-pressed={channelFilter === null}
                                                className={cn(
                                                    'rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                    channelFilter === null
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border bg-background hover:bg-muted',
                                                )}
                                            >
                                                Todos los números
                                            </button>
                                            {channels.map((c) => (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onClick={() => setChannelFilter(c.id)}
                                                    aria-pressed={channelFilter === c.id}
                                                    title={c.phone_e164 ?? undefined}
                                                    className={cn(
                                                        'rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                        channelFilter === c.id
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-background hover:bg-muted',
                                                    )}
                                                >
                                                    {c.label ?? c.phone_e164 ?? 'Canal'}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="flex-1 overflow-y-auto">
                                    {sortedChats.length === 0 ? (
                                        <ChatsEmptyState searchTerm={searchTerm} filter={filter} channels={channels} />
                                    ) : (
                                        sortedChats.map((c) => (
                                            <ChatListItem
                                                key={c.id}
                                                chat={c}
                                                selected={selectedChatId === c.id}
                                                showChannel={showChannelFilter}
                                                onSelect={() => selectChat(c.id)}
                                                onOpenOrder={(orderId) => void openOrderDetail(orderId)}
                                            />
                                        ))
                                    )}
                                </div>
                            </div>

                            <div
                                className={`bg-background flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg ${
                                    isMobile && selectedChatId === null ? 'hidden' : ''
                                }`}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b p-2">
                                    <div className="flex min-w-0 flex-wrap items-center gap-2 text-sm font-semibold">
                                        {isMobile && selectedChat && (
                                            <button
                                                type="button"
                                                onClick={() => selectChat(null)}
                                                className="hover:bg-muted -ml-1 inline-flex h-11 w-11 items-center justify-center rounded"
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
                                            <span className="text-muted-foreground font-mono text-xs font-normal">
                                                {formatPhoneDisplay(selectedChat.client_phone)}
                                            </span>
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
                                        <div className="flex shrink-0 items-center gap-2">
                                            {selectedChat.handoff_requested_at && (
                                                <span className="rounded bg-[color:var(--color-status-warning)]/15 px-2 py-1 text-[10px] font-medium text-[color:var(--color-status-warning)]">
                                                    Handoff solicitado
                                                </span>
                                            )}
                                            {canAudit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => void openActivity(selectedChat.id)}
                                                    title="Ver actividad de la conversación"
                                                >
                                                    <History className="size-4" />
                                                    <span className="sr-only sm:not-sr-only sm:ml-1">Actividad</span>
                                                </Button>
                                            )}
                                            <ReasonTooltip
                                                reason={!canUpdate ? 'Necesitás el permiso «Editar chats» para editar el contacto.' : null}
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={!canUpdate}
                                                    onClick={openContactEditor}
                                                    title={canUpdate ? 'Editar contacto' : undefined}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                    Contacto
                                                </Button>
                                            </ReasonTooltip>
                                            {/* El estado del bot va SIEMPRE explícito en el botón:
                                                "Bot activo" / "Bot pausado — respondés vos". Un
                                                toggle mudo obliga a adivinar quién está contestando.
                                                Deshabilitado sin permiso: el motivo va en el
                                                ReasonTooltip (envuelve un span, así el hover dispara
                                                aunque el botón esté disabled — §8.4c regla 2). */}
                                            {SHOW_BOT_CONTROLS && (
                                            <ReasonTooltip reason={!canUpdate ? 'Necesitás el permiso «Editar chats» para controlar el bot.' : null}>
                                                <Button
                                                    variant={selectedChat.bot_paused ? 'default' : 'outline'}
                                                    size="sm"
                                                    disabled={!canUpdate || botBusy}
                                                    onClick={handleToggleBot}
                                                    title={
                                                        canUpdate
                                                            ? selectedChat.bot_paused
                                                                ? 'El bot está pausado: respondés vos. Reanudá para que conteste solo.'
                                                                : 'El bot responde solo. Si respondés vos, se pausa automáticamente.'
                                                            : undefined
                                                    }
                                                >
                                                    {selectedChat.bot_paused ? (
                                                        <>
                                                            <Play className="h-4 w-4" />
                                                            {/* Texto largo solo en lg: entre md y lg hay 2 columnas
                                                                y la de conversación es angosta — el texto completo
                                                                desbordaba el header. Abajo de lg va el corto. */}
                                                            <span className="hidden lg:inline">Bot pausado — respondés vos</span>
                                                            <span className="lg:hidden">Reanudar</span>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Pause className="h-4 w-4" />
                                                            <span className="hidden lg:inline">Bot activo</span>
                                                            <span className="lg:hidden">Pausar</span>
                                                        </>
                                                    )}
                                                </Button>
                                            </ReasonTooltip>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {botError && <p className="text-destructive px-3 pt-2 text-xs">{botError}</p>}

                                {selectedChat && <ChatPresence viewers={selectedChat.viewers ?? []} />}

                                {/* Panel de próxima acción del flujo de carta (F4): tracking del
                                    link, recibo térmico, aprobación y comprobantes. */}
                                {selectedChat?.cart_flow && (
                                    <ChatCartActions
                                        cartFlow={selectedChat.cart_flow}
                                        canUpdate={canUpdate}
                                        canApprove={canApproveOrders}
                                        onSendReceipt={(order) => sendReceipt(order.id, order.total)}
                                        onRejectProof={(order) => rejectProof(order.id)}
                                        onApprove={(order) => approveOrder(order.id, order.total)}
                                        onChangeOrderType={changeOrderType}
                                        onResendMenuLink={async () => {
                                            const url = await requestMenuLink();
                                            if (url) await sendMessage(`¡Hola! Aquí está nuestra carta 📋 ${url}`);
                                        }}
                                        onOpenOrder={(orderId) => void openOrderDetail(orderId)}
                                    />
                                )}

                                {SHOW_BOT_CONTROLS && selectedChat?.bot_paused && (
                                    <div className="flex items-center gap-2 border-b bg-[color:var(--color-status-warning)]/10 px-3 py-2 text-xs text-[color:var(--color-status-warning)]">
                                        <Bot className="h-4 w-4 shrink-0" />
                                        <span>
                                            Bot pausado{selectedChat.handoff_reason ? ` — motivo: ${selectedChat.handoff_reason}` : ''}. Las
                                            respuestas automáticas están detenidas.
                                        </span>
                                    </div>
                                )}

                                <div ref={messagesContainerRef} className="flex-1 overflow-y-auto">
                                    {/* min-h-full + justify-end: cuando hay pocos mensajes el wrapper igual ocupa
                                        todo el alto y los mensajes quedan abajo (cerca del input). Cuando hay
                                        muchos crece hacia arriba y el scroll se activa en el padre.

                                        `aria-live="polite"`: un lector de pantalla anuncia los mensajes que
                                        entran mientras la conversación está abierta. Sin esto un operador
                                        ciego no se entera de que llegó nada. */}
                                    <div className="flex min-h-full flex-col justify-end gap-2 p-4" aria-live="polite" aria-relevant="additions">
                                        {selectedChat ? (
                                            selectedChat.messages.map((m) => (
                                                <ChatMessageBubble
                                                    key={m.id}
                                                    message={m}
                                                    canRetry={canUpdate}
                                                    retrying={retryingId === m.id}
                                                    onRetry={(id) => void handleRetry(id)}
                                                    onOpenImage={(url) => openImage(url)}
                                                    onWriteToContact={(phone) => setSearchInput(phone)}
                                                    onSaveContact={canUpdate ? saveSharedContact : undefined}
                                                />
                                            ))
                                        ) : (
                                            <p className="text-muted-foreground py-12 text-center text-sm">Selecciona una conversación</p>
                                        )}
                                        <div ref={messagesEndRef} aria-hidden="true" />
                                    </div>
                                </div>

                                {selectedChat && channelDown && (
                                    <div className="flex items-center gap-2 border-t bg-[color:var(--color-status-critical)]/10 px-3 py-2 text-xs text-[color:var(--color-status-critical)]">
                                        <AlertCircle className="h-4 w-4 shrink-0" />
                                        <span className="flex-1">
                                            {channel?.label ?? 'Este número'} está desconectado. Los mensajes no se enviarán.
                                        </span>
                                        <a href="/company/whatsapp" className="font-medium underline underline-offset-2">
                                            Reconectar
                                        </a>
                                    </div>
                                )}

                                {selectedChat && (
                                    <ChatComposer
                                        disabled={composerDisabled}
                                        disabledReason={composerReason}
                                        sending={sending}
                                        onSendText={handleSendText}
                                        onSendAttachment={handleSendAttachment}
                                        quickReplies={quickReplies}
                                        quickReplyVars={quickReplyVars}
                                        onRequestMenuLink={requestMenuLink}
                                        onCreateOrder={createOrderForChat}
                                    />
                                )}
                            </div>
                        </div>
                    </>
                )}
            </div>

            <ChatLightbox images={chatImages} index={lightboxIndex} onIndexChange={setLightboxIndex} onClose={() => setLightboxIndex(null)} />

            <ChatActivityModal
                isOpen={activityOpen}
                onClose={() => setActivityOpen(false)}
                entries={activityEntries}
                loading={activityLoading}
                error={activityError}
            />

            <ClientDetailModal
                isOpen={clientDetailOpen}
                onClose={() => setClientDetailOpen(false)}
                detail={clientDetail}
                loading={clientDetailLoading}
                error={clientDetailError}
                onSelectOrder={(orderId) => void openOrderDetail(orderId)}
                onAddNote={
                    canUpdate
                        ? async (contactId, note) => {
                              const res = await apiFetch(`/api/v1/clients/${contactId}/notes`, {
                                  method: 'POST',
                                  headers: { 'Content-Type': 'application/json' },
                                  body: JSON.stringify({ note }),
                              });
                              if (res.ok && clientDetailChatId) await openClientDetail(clientDetailChatId);
                          }
                        : undefined
                }
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
                        {/* Dirección estructurada (misma que /clients). Las notas
                            se ven/agregan en el detalle del cliente (client_notes). */}
                        <AddressFields idPrefix="chat-contact" value={contactAddress} onChange={setContactAddress} />
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

function ChatListItem({
    chat,
    selected,
    showChannel,
    onSelect,
    onOpenOrder,
}: {
    chat: ChatSummary;
    selected: boolean;
    showChannel: boolean;
    onSelect: () => void;
    onOpenOrder: (orderId: string) => void;
}) {
    return (
        <Card className={`m-2 cursor-pointer ${selected ? 'ring-primary ring-2' : ''}`} onClick={onSelect}>
            <CardHeader className="flex flex-row items-center justify-between gap-2 p-3 pb-0">
                <CardTitle className="flex flex-wrap items-center gap-1 text-base">
                    {chat.client_name ?? chat.client_phone}
                    <ChatSourceBadge source={chat.source} />
                    {/* Badge de canal: responde a "¿por cuál de mis números
                        escribió?". Solo con 2+ canales — con uno es ruido. */}
                    {showChannel && chat.channel && (
                        <span
                            className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-medium"
                            title={`Entró por el WhatsApp de ${chat.channel.label ?? 'este número'}${chat.channel.phone_e164 ? ` (${chat.channel.phone_e164})` : ''}`}
                        >
                            {chat.channel.label ?? chat.channel.phone_e164}
                        </span>
                    )}
                    {chat.handoff_requested_at && (
                        <span
                            title="Bot solicitó intervención humana"
                            className="ml-1 inline-block h-2 w-2 rounded-full bg-[color:var(--color-status-warning)]"
                        />
                    )}
                    {chat.latest_order && (
                        <OrderBadge
                            order={chat.latest_order}
                            onClick={(e) => {
                                e.stopPropagation();
                                onOpenOrder(chat.latest_order!.id);
                            }}
                        />
                    )}
                </CardTitle>
                <span className="text-muted-foreground shrink-0 text-xs">{timeAgo(chat.last_message_at)}</span>
            </CardHeader>
            <CardContent className="space-y-1 p-3 pt-1">
                {/* Teléfono bajo el nombre: identifica la conversación por
                    persona (nombre + número + canal), no por el id del pedido.
                    Solo si hay nombre — sin él, el título ya ES el teléfono. */}
                {chat.client_name && <p className="text-muted-foreground font-mono text-xs">{formatPhoneDisplay(chat.client_phone)}</p>}
                <p className="text-muted-foreground truncate text-xs">{chat.last_message?.body ?? 'Sin mensajes'}</p>
                {chat.pending_reply_since && <WaitingBadge since={chat.pending_reply_since} />}
            </CardContent>
        </Card>
    );
}

/**
 * Estado vacío accionable (§8.4b punto 3).
 *
 * Justo después de conectar, la bandeja está vacía y ese es el momento de mayor
 * duda del cliente ("¿funcionó?"). Dejarlo con un "Sin conversaciones" pelado es
 * desperdiciar el único momento en que va a mirar esta pantalla con atención.
 */
function ChatsEmptyState({ searchTerm, filter, channels }: { searchTerm: string; filter: ChatFilter; channels: { phone_e164: string | null }[] }) {
    if (searchTerm) {
        return <p className="text-muted-foreground p-4 text-center text-sm">Sin resultados para la búsqueda.</p>;
    }

    if (filter === 'pending') {
        return (
            <EmptyState
                icon={MessageCircle}
                title="No hay nadie esperando respuesta"
                description="Todas las conversaciones están al día."
                className="border-none bg-transparent shadow-none"
            />
        );
    }

    if (filter === 'closed') {
        return <p className="text-muted-foreground p-4 text-center text-sm">No hay conversaciones cerradas.</p>;
    }

    const phone = channels.find((c) => c.phone_e164)?.phone_e164;

    return (
        <EmptyState
            icon={MessageCircle}
            title="Todavía nadie te ha escrito"
            description={
                phone
                    ? `Probá vos mismo: escribile al ${phone} desde tu celular y mirá cómo llega acá.`
                    : 'Conectá tu WhatsApp para empezar a recibir mensajes.'
            }
            action={
                <Button variant="outline" size="sm" asChild>
                    <a href="/company/whatsapp">
                        <Send className="mr-2 h-4 w-4" />
                        {phone ? 'Enviar mensaje de prueba' : 'Conectar WhatsApp'}
                    </a>
                </Button>
            }
            className="border-none bg-transparent shadow-none"
        />
    );
}
