/**
 * Capa de comando offline-aware (caja offline-first, plan §7.1).
 *
 * `useOfflineMutation` envuelve una mutación de caja/órdenes:
 *  1. Intenta la request online vía `apiFetch`.
 *  2. Si responde 2xx → devuelve el resultado del server.
 *  3. Si falla por red (fetch rechaza) o 5xx → genera `op_id` (+`client_uuid`
 *     donde aplique), persiste la intención en el OUTBOX y devuelve `queued`.
 *     El sync engine la drenará a `/sync/batch` al reconectar (idempotente).
 *  4. Si responde 4xx (validación/permiso/conflicto de negocio) → NO encola:
 *     es un error real del cliente; se propaga para mostrarlo al usuario.
 *
 * El `op_id` y el `client_uuid` se generan UNA sola vez por intento de comando,
 * de modo que un reintento del sync nunca duplica (idempotencia extremo a
 * extremo, plan §3). La sanitización de inputs de texto debe hacerse ANTES de
 * llamar a `run` (el outbox no es excusa para saltarse el saneo cliente, §7.6).
 */
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { useCallback, useState } from 'react';

import { appendSyncLog, putOutboxOp, type OutboxOp, type OutboxOpType } from './db';
import { refreshPendingCount } from './sync-engine';
import { uuidv4 } from './uuid';

/** Contexto inyectado al builder: ids estables y tenant/sede activos. */
export interface OfflineMutationContext {
    op_id: string;
    client_uuid: string;
    company_nit: string;
    branch_id: string;
}

export interface OfflineMutationConfig<TVars, TResult> {
    /** Tipo de operación del outbox (mapea a un handler real del backend). */
    type: OutboxOpType;
    /** Construye la request online: URL relativa, método y body. */
    request: (vars: TVars) => { url: string; method?: string; body?: Record<string, unknown> };
    /**
     * Construye la operación a encolar offline. Debe incluir `client_uuid` en el
     * payload donde el backend lo use para idempotencia (orders/receipts).
     */
    buildOp: (
        vars: TVars,
        ctx: OfflineMutationContext,
    ) => {
        payload: Record<string, unknown>;
        depends_on?: string[];
        entity_ref?: string;
    };
    /** Optimistic update local tras encolar (p.ej. limpiar carrito, refrescar UI). */
    onQueued?: (op: OutboxOp, vars: TVars) => void | Promise<void>;
    /** Callback tras éxito online. */
    onApplied?: (result: TResult, vars: TVars) => void | Promise<void>;
}

export type OfflineMutationResult<TResult> =
    | { status: 'online'; data: TResult }
    | { status: 'queued'; op: OutboxOp };

export interface OfflineMutationState<TVars, TResult> {
    run: (vars: TVars) => Promise<OfflineMutationResult<TResult>>;
    submitting: boolean;
    /** Error real (4xx) — los fallos de red NO son error, encolan. */
    error: string | null;
    reset: () => void;
}

/** ¿El error de la request amerita encolar (red/5xx) en lugar de fallar (4xx)? */
function shouldQueue(status: number | null): boolean {
    // Sin status = fetch rechazó (offline / DNS / CORS) → encolar.
    if (status === null) return true;
    // 5xx = server caído → encolar (idempotente, no perdemos la venta).
    return status >= 500;
}

export function useOfflineMutation<TVars, TResult = unknown>(
    config: OfflineMutationConfig<TVars, TResult>,
): OfflineMutationState<TVars, TResult> {
    const { activeCompany, activeBranch } = useSharedData();
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const reset = useCallback(() => setError(null), []);

    const run = useCallback(
        async (vars: TVars): Promise<OfflineMutationResult<TResult>> => {
            setSubmitting(true);
            setError(null);

            const companyNit = activeCompany?.nit ?? null;
            const branchId = activeBranch?.id ?? null;
            const { url, method = 'POST', body } = config.request(vars);

            // ── 1. Camino online ──────────────────────────────────────────
            let status: number | null = null;
            try {
                const res = await apiFetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: body !== undefined ? JSON.stringify(body) : undefined,
                });
                status = res.status;
                const data = await res.json().catch(() => ({}));
                if (res.ok) {
                    await config.onApplied?.(data as TResult, vars);
                    return { status: 'online', data: data as TResult };
                }
                // 4xx → error real del cliente, no encolar.
                if (!shouldQueue(status)) {
                    const msg = (data as { message?: string })?.message ?? `Error ${status}.`;
                    setError(msg);
                    throw new Error(msg);
                }
                // 5xx cae al encolado.
            } catch (e) {
                // Si ya marcamos un 4xx, re-lanzar (no encolar).
                if (status !== null && !shouldQueue(status)) {
                    throw e;
                }
                // fetch rechazó (offline) → status sigue null → encolar.
            }

            // ── 2. Encolar en el outbox ───────────────────────────────────
            if (!companyNit || !branchId) {
                const msg = 'Sin empresa/sede activa: no se puede operar offline.';
                setError(msg);
                throw new Error(msg);
            }

            const opId = uuidv4();
            const clientUuid = uuidv4();
            const built = config.buildOp(vars, { op_id: opId, client_uuid: clientUuid, company_nit: companyNit, branch_id: branchId });

            const op: OutboxOp = {
                op_id: opId,
                type: config.type,
                company_nit: companyNit,
                branch_id: branchId,
                payload: built.payload,
                depends_on: built.depends_on,
                entity_ref: built.entity_ref,
                created_at_client: new Date().toISOString(),
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            };

            await putOutboxOp(op);
            await appendSyncLog({
                company_nit: companyNit,
                event: 'order_queued',
                detail: `${config.type} op=${opId.slice(0, 8)}`,
                occurred_at: new Date().toISOString(),
            });
            await refreshPendingCount();
            await config.onQueued?.(op, vars);

            return { status: 'queued', op };
        },
        [activeCompany?.nit, activeBranch?.id, config],
    );

    // `submitting` se libera vía finally implícito: lo reseteamos en el caller
    // pattern; aquí lo manejamos envolviendo run.
    const wrappedRun = useCallback(
        async (vars: TVars): Promise<OfflineMutationResult<TResult>> => {
            try {
                return await run(vars);
            } finally {
                setSubmitting(false);
            }
        },
        [run],
    );

    return { run: wrappedRun, submitting, error, reset };
}
