import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

/** Flujo de automatización (n8n) por empresa/sede (§9.5). */
export interface AutomationFlow {
    id: string;
    branch_id: string | null;
    branch_name?: string | null;
    label: string | null;
    enabled: boolean;
    url: string;
    events: string[];
    has_token: boolean;
    token_last4: string | null;
    token_created_at: string | null;
    last_delivery_at: string | null;
    created_at: string | null;
}

export interface AutomationFlowInput {
    branch_id?: string | null;
    label?: string | null;
    url?: string;
    events?: string[];
    enabled?: boolean;
}

/** Respuesta del alta: el token y el secreto se ven UNA sola vez (patrón PAT). */
export interface CreatedFlow {
    flow: AutomationFlow;
    token: string;
    secret: string;
}

export interface FlowDelivery {
    event: string | null;
    http_status: number | null;
    latency_ms: number | null;
    attempt: number | null;
    at: string | null;
}

export interface TestResult {
    ok: boolean;
    http_status: number;
    latency_ms: number;
    error?: string;
}

interface UseAutomationFlowsReturn {
    flows: AutomationFlow[];
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    create: (input: AutomationFlowInput) => Promise<CreatedFlow>;
    update: (id: string, input: AutomationFlowInput) => Promise<void>;
    rotateToken: (id: string) => Promise<string>;
    rotateSecret: (id: string) => Promise<string>;
    test: (id: string) => Promise<TestResult>;
    deliveries: (id: string) => Promise<FlowDelivery[]>;
    remove: (id: string) => Promise<void>;
}

const BASE = '/api/v1/whatsapp/automation-flows';

async function readJson(res: Response): Promise<Record<string, unknown>> {
    return (await res.json().catch(() => ({}))) as Record<string, unknown>;
}

/**
 * Flujos de automatización (n8n) de la empresa activa (§9.5).
 *
 * Listar es `whatsapp.read`; crear/rotar/borrar es `whatsapp.update` (el backend
 * responde 403 a los demás). Sin polling: cambian rara vez y se refrescan tras
 * cada mutación. El token y el secreto solo llegan en la respuesta de creación o
 * rotación — no se guardan ni se pueden volver a pedir.
 */
export function useAutomationFlows(token: string | null): UseAutomationFlowsReturn {
    const [flows, setFlows] = useState<AutomationFlow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(async (): Promise<void> => {
        if (!token) {
            setLoading(false);
            return;
        }
        try {
            const res = await apiFetch(BASE);
            const json = await readJson(res);
            if (!res.ok) {
                setError((json.message as string) ?? 'No se pudieron cargar los flujos de automatización.');
                return;
            }
            setFlows((json.data as AutomationFlow[]) ?? []);
            setError(null);
        } catch {
            setError('Error de conexión al cargar los flujos de automatización.');
        } finally {
            setLoading(false);
        }
    }, [token]);

    const send = useCallback(async (url: string, method: string, body?: unknown): Promise<Record<string, unknown>> => {
        const res = await apiFetch(url, {
            method,
            headers: body ? { 'Content-Type': 'application/json' } : undefined,
            body: body ? JSON.stringify(body) : undefined,
        });
        const json = await readJson(res);
        if (!res.ok) {
            throw new Error((json.message as string) ?? 'No se pudo completar la operación.');
        }
        return json;
    }, []);

    const create = useCallback(
        async (input: AutomationFlowInput): Promise<CreatedFlow> => {
            const json = await send(BASE, 'POST', input);
            await refresh();
            return {
                flow: (json.data as { data?: AutomationFlow })?.data ?? (json.data as AutomationFlow),
                token: json.token as string,
                secret: json.secret as string,
            };
        },
        [send, refresh],
    );

    const update = useCallback(
        async (id: string, input: AutomationFlowInput): Promise<void> => {
            await send(`${BASE}/${id}`, 'PUT', input);
            await refresh();
        },
        [send, refresh],
    );

    const rotateToken = useCallback(
        async (id: string): Promise<string> => {
            const json = await send(`${BASE}/${id}/rotate-token`, 'POST');
            await refresh();
            return json.token as string;
        },
        [send, refresh],
    );

    const rotateSecret = useCallback(
        async (id: string): Promise<string> => {
            const json = await send(`${BASE}/${id}/rotate-secret`, 'POST');
            return json.secret as string;
        },
        [send],
    );

    const test = useCallback(
        async (id: string): Promise<TestResult> => {
            const json = await send(`${BASE}/${id}/test`, 'POST');
            return json as unknown as TestResult;
        },
        [send],
    );

    const deliveries = useCallback(
        async (id: string): Promise<FlowDelivery[]> => {
            const json = await send(`${BASE}/${id}/deliveries`, 'GET');
            return (json.data as FlowDelivery[]) ?? [];
        },
        [send],
    );

    const remove = useCallback(
        async (id: string): Promise<void> => {
            await send(`${BASE}/${id}`, 'DELETE');
            await refresh();
        },
        [send, refresh],
    );

    useEffect(() => {
        void refresh();
    }, [refresh]);

    return { flows, loading, error, refresh, create, update, rotateToken, rotateSecret, test, deliveries, remove };
}

/** Eventos suscribibles (§9.2). Deben coincidir con `AutomationFlowController::ALLOWED_EVENTS`. */
export const AUTOMATION_EVENTS: { value: string; label: string; description: string }[] = [
    { value: 'chat.message.received', label: 'Mensaje recibido', description: 'Un cliente escribió al canal.' },
    { value: 'chat.handoff.requested', label: 'Handoff pedido', description: 'Se pidió intervención humana.' },
    { value: 'chat.bot_toggled', label: 'Bot pausado/reanudado', description: 'Cambió el estado del bot en un chat.' },
    { value: 'channel.status.changed', label: 'Estado del canal', description: 'El canal se conectó o desconectó.' },
];
