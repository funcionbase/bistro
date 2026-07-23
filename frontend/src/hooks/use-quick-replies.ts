import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

/** Respuesta rápida configurable por empresa/sede (§8.4b punto 7). */
export interface QuickReply {
    id: string;
    branch_id: string | null;
    branch_name?: string | null;
    title: string;
    /** Admite {{cliente}}, {{pedido}}, {{sede}}; se resuelven al insertar. */
    body: string;
}

export interface QuickReplyInput {
    branch_id?: string | null;
    title: string;
    body: string;
}

interface UseQuickRepliesReturn {
    replies: QuickReply[];
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    create: (input: QuickReplyInput) => Promise<void>;
    update: (id: string, input: Omit<QuickReplyInput, 'branch_id'>) => Promise<void>;
    remove: (id: string) => Promise<void>;
}

/**
 * Respuestas rápidas del operador (§8.4b punto 7).
 *
 * Listar/usar lo puede cualquiera con `chats.read`; crear/editar/borrar es
 * owner/admin (el backend responde 403 a los demás). Sin polling: cambian rara
 * vez y se refrescan tras cada mutación.
 */
export function useQuickReplies(token: string | null): UseQuickRepliesReturn {
    const [replies, setReplies] = useState<QuickReply[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(async (): Promise<void> => {
        if (!token) {
            setLoading(false);
            return;
        }
        try {
            const res = await apiFetch('/api/v1/chats/quick-replies');
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudieron cargar las respuestas rápidas.');
                return;
            }
            setReplies((json as { data: QuickReply[] }).data ?? []);
            setError(null);
        } catch {
            setError('Error de conexión al cargar las respuestas rápidas.');
        } finally {
            setLoading(false);
        }
    }, [token]);

    const mutate = useCallback(
        async (url: string, method: string, body?: unknown): Promise<void> => {
            const res = await apiFetch(url, {
                method,
                headers: body ? { 'Content-Type': 'application/json' } : undefined,
                body: body ? JSON.stringify(body) : undefined,
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'No se pudo guardar la respuesta rápida.');
            }
            await refresh();
        },
        [refresh],
    );

    const create = useCallback((input: QuickReplyInput) => mutate('/api/v1/chats/quick-replies', 'POST', input), [mutate]);
    const update = useCallback(
        (id: string, input: Omit<QuickReplyInput, 'branch_id'>) => mutate(`/api/v1/chats/quick-replies/${id}`, 'PUT', input),
        [mutate],
    );
    const remove = useCallback((id: string) => mutate(`/api/v1/chats/quick-replies/${id}`, 'DELETE'), [mutate]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    return { replies, loading, error, refresh, create, update, remove };
}

/**
 * Resuelve las variables de una plantilla al insertarla (§8.4b punto 14).
 *
 * `{{cliente}}` → nombre del cliente, `{{pedido}}` → código del último pedido,
 * `{{sede}}` → nombre de la sede. Lo que no se pueda resolver se deja vacío en
 * vez de dejar el `{{...}}` crudo a la vista del cliente.
 */
export function resolveQuickReplyVariables(body: string, vars: { cliente?: string | null; pedido?: string | null; sede?: string | null }): string {
    return body
        .replace(/\{\{\s*cliente\s*\}\}/gi, (vars.cliente ?? '').trim())
        .replace(/\{\{\s*pedido\s*\}\}/gi, (vars.pedido ?? '').trim())
        .replace(/\{\{\s*sede\s*\}\}/gi, (vars.sede ?? '').trim());
}
