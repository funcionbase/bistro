import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

export type ChatMediaType = 'sticker' | 'image' | 'video' | 'audio' | 'document' | null;

export interface ChatMessage {
    id: string;
    sender: 'client' | 'bot' | 'operator';
    body: string;
    status?: string | null;
    media_type?: ChatMediaType;
    media_mime?: string | null;
    media_url?: string | null;
    sent_at: string | null;
}

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
    status: 'open' | 'closed';
    source: ChatSource;
    bot_paused: boolean;
    handoff_requested_at: string | null;
    handoff_reason: string | null;
    last_message_at: string | null;
    last_message: ChatMessage | null;
    latest_order: ChatLatestOrder | null;
}

export interface ChatDetail extends ChatSummary {
    messages: ChatMessage[];
}

export interface ContactPayload {
    name?: string | null;
    phone?: string | null;
    notes?: string | null;
}

interface UseChatsReturn {
    chats: ChatSummary[];
    selectedChat: ChatDetail | null;
    selectedChatId: string | null;
    selectChat: (id: string | null) => void;
    sendMessage: (body: string) => Promise<void>;
    setBotPaused: (paused: boolean) => Promise<void>;
    updateContact: (payload: ContactPayload) => Promise<void>;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
}

const POLL_INTERVAL_MS = 30_000;

export function useChats(token: string | null, search: string = ''): UseChatsReturn {
    const [chats, setChats] = useState<ChatSummary[]>([]);
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
            const url = search.trim() !== '' ? `/api/v1/chats?q=${encodeURIComponent(search.trim())}` : '/api/v1/chats';
            const res = await apiFetch(url);
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar chats.');
                return;
            }
            const json = await res.json();
            const list = (json as { data: ChatSummary[] }).data ?? [];
            setChats(list);
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
    }, [token, selectedChatId, search]);

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
            const body: Record<string, string> = {};
            if (payload.name !== undefined && payload.name !== null) body.name = payload.name;
            if (payload.phone !== undefined && payload.phone !== null) body.phone = payload.phone;
            if (payload.notes !== undefined && payload.notes !== null) body.notes = payload.notes;
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
        selectedChat,
        selectedChatId,
        selectChat: setSelectedChatId,
        sendMessage,
        setBotPaused,
        updateContact,
        loading,
        error,
        refresh: fetchChats,
    };
}
