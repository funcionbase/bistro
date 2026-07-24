import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ClientSegment } from './use-clients';

export interface ClientKpis {
    total_orders: number;
    completed_orders: number;
    cancelled_orders: number;
    total_spent: number;
    average_ticket: number;
    first_order_at: string | null;
    last_order_at: string | null;
    orders_last_60d: number;
    spent_last_90d: number;
    cancellation_rate: number;
}

export interface ClientOrderHistoryItem {
    id: string;
    status: string;
    order_type: string | null;
    total: number;
    discount_amount: number;
    items_count: number;
    ordered_at: string | null;
}

export interface ClientChatItem {
    id: string;
    source: string;
    status: string;
    last_message_at: string | null;
}

export interface ClientNote {
    id: string;
    note: string;
    created_at: string | null;
    author: { id: string; name: string | null } | null;
}

export interface ClientTag {
    id: string;
    tag: string;
}

export type ContactKind = 'natural' | 'company';

export interface ClientProfile {
    id: string;
    phone: string | null;
    name: string | null;
    kind: ContactKind | null;
    doc_type: string | null;
    doc_number: string | null;
    dv: string | null;
    legal_name: string | null;
    email: string | null;
    address: string | null;
    neighborhood: string | null;
    municipality_dane_code: string | null;
    municipality_label: string | null;
    fiscal_responsibilities: string[];
    dian_profile_completed_at: string | null;
    contact_notes: string | null;
    segment: ClientSegment;
    kpis: ClientKpis;
    orders: ClientOrderHistoryItem[];
    chats: ClientChatItem[];
    notes: ClientNote[];
    tags: ClientTag[];
}

interface UseClientReturn {
    profile: ClientProfile | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    addNote: (note: string) => Promise<ClientNote>;
    deleteNote: (id: string) => Promise<void>;
    addTag: (tag: string) => Promise<ClientTag>;
    deleteTag: (id: string) => Promise<void>;
}

export function useClient(token: string | null, contactId: string | null): UseClientReturn {
    const [profile, setProfile] = useState<ClientProfile | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchProfile = useCallback(async (): Promise<void> => {
        if (!token || !contactId) return;
        try {
            setLoading(true);
            const res = await apiFetch(`/api/v1/clients/${contactId}`);
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar contacto.');
                setLoading(false);
                return;
            }
            const json = (await res.json()) as { data: ClientProfile };
            setProfile(json.data);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, contactId]);

    useEffect(() => {
        void fetchProfile();
    }, [fetchProfile]);

    const addNote = useCallback(
        async (note: string): Promise<ClientNote> => {
            if (!contactId) throw new Error('contactId requerido');
            const res = await apiFetch(`/api/v1/clients/${contactId}/notes`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ note }),
            });
            if (!res.ok) {
                throw await res.json().catch(() => ({ message: 'Error al crear nota.' }));
            }
            const json = (await res.json()) as { data: ClientNote };
            await fetchProfile();
            return json.data;
        },
        [contactId, fetchProfile],
    );

    const deleteNote = useCallback(
        async (id: string): Promise<void> => {
            if (!contactId) throw new Error('contactId requerido');
            const res = await apiFetch(`/api/v1/clients/${contactId}/notes/${id}`, { method: 'DELETE' });
            if (!res.ok && res.status !== 204) {
                throw await res.json().catch(() => ({ message: 'Error al eliminar nota.' }));
            }
            await fetchProfile();
        },
        [contactId, fetchProfile],
    );

    const addTag = useCallback(
        async (tag: string): Promise<ClientTag> => {
            if (!contactId) throw new Error('contactId requerido');
            const res = await apiFetch(`/api/v1/clients/${contactId}/tags`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tag }),
            });
            if (!res.ok) {
                throw await res.json().catch(() => ({ message: 'Error al agregar etiqueta.' }));
            }
            const json = (await res.json()) as { data: ClientTag };
            await fetchProfile();
            return json.data;
        },
        [contactId, fetchProfile],
    );

    const deleteTag = useCallback(
        async (id: string): Promise<void> => {
            if (!contactId) throw new Error('contactId requerido');
            const res = await apiFetch(`/api/v1/clients/${contactId}/tags/${id}`, { method: 'DELETE' });
            if (!res.ok && res.status !== 204) {
                throw await res.json().catch(() => ({ message: 'Error al eliminar etiqueta.' }));
            }
            await fetchProfile();
        },
        [contactId, fetchProfile],
    );

    return { profile, loading, error, refresh: fetchProfile, addNote, deleteNote, addTag, deleteTag };
}
