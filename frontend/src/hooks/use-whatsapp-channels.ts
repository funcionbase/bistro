import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

import type { WhatsappStatus } from '@/components/ui/whatsapp-status-pill';

export interface WhatsappChannel {
    id: string;
    branch_id: string | null;
    branch_name?: string | null;
    scope: 'company' | 'branch';
    label: string | null;
    status: WhatsappStatus | string;
    phone_e164: string | null;
    display_name: string | null;
    connected_at: string | null;
    disconnected_at: string | null;
    last_connection_check_at: string | null;
    created_at: string | null;
    last_message_at?: string | null;
    chats_count: number;
    can_send: boolean;
}

export interface BranchWithoutChannel {
    id: string;
    name: string;
}

export interface WhatsappChannelsMeta {
    branches_without_channel: BranchWithoutChannel[];
    branch_count: number;
    has_company_channel: boolean;
    connected_count: number;
    can_manage_company_channel: boolean;
}

interface UseWhatsappChannelsReturn {
    channels: WhatsappChannel[];
    meta: WhatsappChannelsMeta | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
}

const EMPTY_META: WhatsappChannelsMeta = {
    branches_without_channel: [],
    branch_count: 0,
    has_company_channel: false,
    connected_count: 0,
    can_manage_company_channel: false,
};

/**
 * Lista de canales de WhatsApp de la empresa activa (§8.2).
 *
 * Sin polling propio: la pantalla es de configuración, no de operación. El
 * único momento en que el estado cambia solo es mientras el modal del QR está
 * abierto, y ese polling vive en el modal (cada 2 s) — pollear la lista entera
 * de fondo sería gastar backend para mirar una pantalla que nadie está mirando.
 */
export function useWhatsappChannels(token: string | null): UseWhatsappChannelsReturn {
    const [channels, setChannels] = useState<WhatsappChannel[]>([]);
    const [meta, setMeta] = useState<WhatsappChannelsMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(async (): Promise<void> => {
        if (!token) {
            setLoading(false);
            return;
        }
        try {
            const res = await apiFetch('/api/v1/whatsapp/channels');
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo cargar el estado de WhatsApp.');
                return;
            }
            const body = json as { data: WhatsappChannel[]; meta: WhatsappChannelsMeta };
            setChannels(body.data ?? []);
            setMeta(body.meta ?? EMPTY_META);
            setError(null);
        } catch {
            setError('Error de conexión al cargar los canales de WhatsApp.');
        } finally {
            setLoading(false);
        }
    }, [token]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    return { channels, meta, loading, error, refresh };
}
