import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Los 9 tipos de §6.7. `location` y `contact` no tienen archivo asociado. */
export type ChatMediaType = 'sticker' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact' | null;

/**
 * Contenido de `chat_messages.media_payload` (jsonb). La forma depende del
 * tipo: {lat,lng,name,address} para ubicación, {contacts[]} para contacto,
 * {file_name,size_bytes,duration_s,ptt,caption} para archivos.
 */
export interface ChatMediaPayload {
    lat?: number;
    lng?: number;
    name?: string;
    address?: string;
    contacts?: { name?: string | null; phones?: string[] | null }[];
    file_name?: string;
    size_bytes?: number;
    duration_s?: number;
    ptt?: boolean;
    caption?: string;
    [key: string]: unknown;
}

export interface ChatMessage {
    id: string;
    sender: 'client' | 'bot' | 'operator';
    body: string;
    status?: string | null;
    /** Código corto del backend; el copy en español lo arma el frontend. */
    failure_reason?: string | null;
    /** Lo mandó el dueño desde su celular, no desde el panel. */
    from_device?: boolean;
    /** Nombre del operador que lo envió. Null en cliente, bot e históricos. */
    author?: string | null;
    media_type?: ChatMediaType;
    media_mime?: string | null;
    media_url?: string | null;
    media_payload?: ChatMediaPayload | null;
    sent_at: string | null;
}

export interface ChatChannel {
    id: string;
    label: string | null;
    status: string;
    phone_e164: string | null;
    can_send?: boolean;
}

/** Chips de la bandeja (§8.4b punto 2). */
export type ChatFilter = 'pending' | 'all' | 'closed';

export interface ChatLatestOrder {
    id: string;
    status: string;
}

export type ChatSource = 'whatsapp' | 'instagram' | 'facebook' | string;

export interface ChatSummary {
    id: string;
    client_phone: string;
    client_name: string | null;
    contact_id: string | null;
    contact_notes?: string | null;
    contact_address?: string | null;
    contact_neighborhood?: string | null;
    contact_municipality_dane_code?: string | null;
    status: 'open' | 'closed';
    source: ChatSource;
    bot_paused: boolean;
    handoff_requested_at: string | null;
    handoff_reason: string | null;
    last_message_at: string | null;
    /** Desde cuándo el cliente espera respuesta. Null = ya se le respondió. */
    pending_reply_since: string | null;
    /** Por cuál de los números de la empresa entró la conversación. */
    channel?: ChatChannel | null;
    last_message: ChatMessage | null;
    latest_order: ChatLatestOrder | null;
}

/** Orden ligada a la sesión de carta (F4) con el guard del recibo térmico. */
export interface ChatCartFlowOrder {
    id: string;
    short_code: string;
    status: string;
    status_label: string;
    order_type: string;
    total: number;
    tip_amount: number;
    payment_preference: string | null;
    cash_pays_with: number | null;
    customer_notes: string | null;
    delivery_address: string | null;
    receipt_sent_at: string | null;
    /** El total cambió después del último recibo enviado → sugerir reenviar. */
    receipt_stale: boolean;
    ordered_at: string | null;
}

/** Panel de próxima acción del chat (F4): última carta enviada + sus órdenes. */
export interface ChatCartFlow {
    session: {
        token: string;
        status: 'active' | 'abandoned' | 'converted';
        viewed_at: string | null;
        last_activity_at: string | null;
        expired_at: string | null;
        created_at: string | null;
    };
    orders: ChatCartFlowOrder[];
}

export interface ChatDetail extends ChatSummary {
    messages: ChatMessage[];
    /** Otros operadores con este chat abierto en los últimos 90 s (§5.7). */
    viewers?: string[];
    cart_flow?: ChatCartFlow | null;
}

export interface ContactPayload {
    name?: string | null;
    phone?: string | null;
    address?: string | null;
    neighborhood?: string | null;
    municipality_dane_code?: string | null;
}

interface UseChatsReturn {
    chats: ChatSummary[];
    channels: ChatChannel[];
    pendingCount: number;
    selectedChat: ChatDetail | null;
    selectedChatId: string | null;
    selectChat: (id: string | null) => void;
    sendMessage: (body: string) => Promise<void>;
    sendAttachment: (file: File, kind: string, caption: string) => Promise<void>;
    retryMessage: (messageId: string) => Promise<void>;
    setBotPaused: (paused: boolean) => Promise<void>;
    updateContact: (payload: ContactPayload) => Promise<void>;
    /** Recibo térmico virtual (F4, CA2). Lanza Error con el motivo si el backend rechaza (409). */
    sendReceipt: (orderId: string, expectedTotal: number) => Promise<void>;
    /** Rechazo de comprobante de transferencia (F4, CA3). */
    rejectProof: (orderId: string) => Promise<void>;
    /** Aprobación con guard de carrera (F4, CA3/CA4): expected_total → 409 ORDER_CHANGED. */
    approveOrder: (orderId: string, expectedTotal: number) => Promise<void>;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    /** Refetch inmediato del chat seleccionado (para acciones externas al hook). */
    refreshSelected: () => Promise<void>;
}

const POLL_INTERVAL_MS = 30_000;

interface UseChatsOptions {
    search?: string;
    filter?: ChatFilter;
    channelId?: string | null;
    /** Filtro por plataforma: 'whatsapp' | 'sms'. null = sin filtrar. */
    source?: string | null;
}

export function useChats(token: string | null, options: UseChatsOptions = {}): UseChatsReturn {
    const { search = '', filter = 'all', channelId = null, source = null } = options;
    const [chats, setChats] = useState<ChatSummary[]>([]);
    const [channels, setChannels] = useState<ChatChannel[]>([]);
    const [pendingCount, setPendingCount] = useState(0);
    const [selectedChatId, setSelectedChatId] = useState<string | null>(null);
    const [selectedChat, setSelectedChat] = useState<ChatDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);
    // Secuencia de peticiones del detalle: cada llamada a fetchChatDetail toma
    // un número creciente; al volver, solo aplica el estado si sigue siendo la
    // petición más reciente. Evita que un poll viejo (30s) pise el detalle
    // fresco que devolvió el refetch tras enviar un mensaje (F14).
    const detailReqSeq = useRef(0);
    // Auto-select solo en la PRIMERA carga. Si el usuario despues vuelve al
    // listado (botón atras en mobile o selectChat(null)), no queremos que el
    // polling re-seleccione el primer chat y devuelva al usuario al detalle.
    const hasAutoSelectedRef = useRef(false);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchChats = useCallback(async (): Promise<void> => {
        if (!token) return;
        try {
            const params = new URLSearchParams();
            if (search.trim() !== '') params.set('q', search.trim());
            if (filter !== 'all') params.set('filter', filter);
            if (channelId) params.set('channel_id', channelId);
            if (source) params.set('source', source);
            const query = params.toString();
            const res = await apiFetch(query ? `/api/v1/chats?${query}` : '/api/v1/chats');
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar chats.');
                return;
            }
            const json = await res.json();
            const body = json as { data: ChatSummary[]; meta?: { pending_count?: number; channels?: ChatChannel[] } };
            const list = body.data ?? [];
            setChats(list);
            setChannels(body.meta?.channels ?? []);
            setPendingCount(body.meta?.pending_count ?? 0);
            setError(null);

            if (!hasAutoSelectedRef.current && selectedChatId === null && list.length > 0) {
                hasAutoSelectedRef.current = true;
                setSelectedChatId(list[0].id);
            }
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, selectedChatId, search, filter, channelId, source]);

    const fetchChatDetail = useCallback(
        async (id: string): Promise<void> => {
            if (!token) return;
            const seq = ++detailReqSeq.current;
            try {
                const res = await apiFetch(`/api/v1/chats/${id}`);
                if (!isMounted.current) return;
                // Descarta respuestas obsoletas: si entre el request y su
                // retorno ya se disparó otra petición (ej. el refetch tras
                // enviar un mensaje), no pisamos el detalle fresco con el viejo.
                if (seq !== detailReqSeq.current) return;
                // 404: el chat dejo de existir (re-seed, soft-delete, etc.). Limpiamos
                // la seleccion para parar el polling sobre un id muerto y mostrar la
                // lista nuevamente.
                if (res.status === 404) {
                    setSelectedChatId(null);
                    setSelectedChat(null);
                    return;
                }
                if (!res.ok) return;
                const json = await res.json();
                setSelectedChat((json as { data: ChatDetail }).data);
            } catch {
                // ignorar — la lista de chats sigue actualizandose
            }
        },
        [token],
    );

    const sendMessage = useCallback(
        async (body: string): Promise<void> => {
            if (!token || !selectedChatId || !body.trim()) return;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ body }),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al enviar mensaje.');
            }
            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    /**
     * Adjunto saliente (§6.7). Va como `multipart/form-data`: mandar 16 MB en
     * base64 dentro de un JSON los infla un 33 % y obliga a que PHP los
     * decodifique en memoria.
     *
     * NO se fija `Content-Type` a mano: el browser tiene que generar el boundary
     * del multipart, y ponerlo explícito rompe el parseo del lado del servidor.
     */
    const sendAttachment = useCallback(
        async (file: File, kind: string, caption: string): Promise<void> => {
            if (!token || !selectedChatId) return;

            const form = new FormData();
            form.append('file', file);
            form.append('type', kind);
            if (caption) form.append('caption', caption);

            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/attachments`, { method: 'POST', body: form });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                const body = json as { message?: string; errors?: Record<string, string[]> };
                // El 422 del FormRequest trae el motivo real ("el tipo de archivo
                // no está permitido para «image» (image/svg+xml)"). Mostrar el
                // `message` genérico escondería justo lo que hay que corregir.
                const detail = body.errors ? Object.values(body.errors).flat()[0] : undefined;
                throw new Error(detail ?? body.message ?? 'Error al enviar el adjunto.');
            }

            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const retryMessage = useCallback(
        async (messageId: string): Promise<void> => {
            if (!token || !selectedChatId) return;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/messages/${messageId}/retry`, { method: 'POST' });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'No se pudo reintentar el envío.');
            }
            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const setBotPaused = useCallback(
        async (paused: boolean): Promise<void> => {
            if (!token || !selectedChatId) return;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/bot`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ paused }),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al actualizar el bot.');
            }
            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const updateContact = useCallback(
        async (payload: ContactPayload): Promise<void> => {
            if (!token || !selectedChatId) return;
            // Dirección se envía SIEMPRE que la clave esté presente (incl. null),
            // para poder limpiarla; nombre/teléfono solo si tienen valor.
            const body: Record<string, string | null> = {};
            if (payload.name != null) body.name = payload.name;
            if (payload.phone != null) body.phone = payload.phone;
            if ('address' in payload) body.address = payload.address ?? null;
            if ('neighborhood' in payload) body.neighborhood = payload.neighborhood ?? null;
            if ('municipality_dane_code' in payload) body.municipality_dane_code = payload.municipality_dane_code ?? null;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/contact`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al guardar el contacto.');
            }
            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const sendReceipt = useCallback(
        async (orderId: string, expectedTotal: number): Promise<void> => {
            if (!token || !selectedChatId) return;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/orders/${orderId}/receipt`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ expected_total: expectedTotal }),
            });
            // Refetch SIEMPRE: en 409 (recibo vigente / total cambió) el panel
            // necesita el estado fresco para reflejar el motivo.
            const refetch = Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                await refetch;
                throw new Error((json as { message?: string }).message ?? 'No se pudo enviar el recibo.');
            }
            await refetch;
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const rejectProof = useCallback(
        async (orderId: string): Promise<void> => {
            if (!token || !selectedChatId) return;
            const res = await apiFetch(`/api/v1/chats/${selectedChatId}/orders/${orderId}/reject-proof`, { method: 'POST' });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'No se pudo enviar el aviso.');
            }
            await Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    const approveOrder = useCallback(
        async (orderId: string, expectedTotal: number): Promise<void> => {
            if (!token || !selectedChatId) return;
            const res = await apiFetch(`/api/v1/orders/${orderId}/approve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ expected_total: expectedTotal }),
            });
            const refetch = Promise.all([fetchChatDetail(selectedChatId), fetchChats()]);
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                const body = json as { message?: string; errors?: Record<string, string[]> };
                const detail = body.errors ? Object.values(body.errors).flat()[0] : undefined;
                await refetch;
                throw new Error(detail ?? body.message ?? 'No se pudo aprobar el pedido.');
            }
            await refetch;
        },
        [token, selectedChatId, fetchChatDetail, fetchChats],
    );

    useEffect(() => {
        void fetchChats();
        const interval = setInterval(() => {
            if (document.hidden) return; // pestaña oculta: no gastar backend
            void fetchChats();
        }, POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [fetchChats]);

    useEffect(() => {
        if (selectedChatId === null) {
            setSelectedChat(null);
            return;
        }
        void fetchChatDetail(selectedChatId);
        const interval = setInterval(() => {
            if (document.hidden) return; // pestaña oculta: no gastar backend
            void fetchChatDetail(selectedChatId);
        }, POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [selectedChatId, fetchChatDetail]);

    return {
        chats,
        channels,
        pendingCount,
        selectedChat,
        selectedChatId,
        selectChat: setSelectedChatId,
        sendMessage,
        sendAttachment,
        retryMessage,
        setBotPaused,
        updateContact,
        sendReceipt,
        rejectProof,
        approveOrder,
        loading,
        error,
        refresh: fetchChats,
        refreshSelected: async () => {
            if (selectedChatId) await fetchChatDetail(selectedChatId);
        },
    };
}
