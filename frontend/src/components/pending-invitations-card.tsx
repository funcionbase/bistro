import { useCallback, useEffect, useMemo, useState } from 'react';
import { Clock, Mail, MoreVertical, RefreshCw, Trash2, UserPlus } from 'lucide-react';

import RoleBadge from '@/components/role-badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { apiFetch } from '@/lib/api';
import type { PendingInvitation } from '@/types';

interface PendingInvitationsCardProps {
    /**
     * Si el actor no tiene permiso para invitar/cancelar, el panel sigue
     * visible (informativo) pero los botones de acción quedan ocultos.
     */
    canManage: boolean;
    /**
     * Notifica al padre cuando una invitación se reenvía o cancela. Útil para
     * disparar refresh en otras secciones (ej. tabla de miembros si se acepta
     * una invitación entre tanto).
     */
    onChanged?: () => void;
}

/**
 * Panel reusable que lista las invitaciones pendientes de la empresa activa
 * y expone acciones reenviar/cancelar (DS v3.1). Vive dentro de `/users`
 * pero se puede embeber en cualquier vista de gestión de equipo.
 *
 * - Carga inicial vía GET /api/v1/invitations.
 * - Reenviar (POST /resend): el backend re-encola el job; UI muestra toast
 *   y refresca el listado. Tooltip explica que sólo se procesará si el
 *   correo previo no se mandó en la última hora (ShouldBeUnique en el job).
 * - Cancelar (DELETE): el backend hace hard delete + audit log
 *   `invitation.cancelled`. Se confirma con ConfirmDialog destructivo.
 *
 * El componente NO maneja "crear invitación" — eso vive en InviteUserModal.
 */
export function PendingInvitationsCard({ canManage, onChanged }: PendingInvitationsCardProps) {
    const [invitations, setInvitations] = useState<PendingInvitation[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [confirmCancelId, setConfirmCancelId] = useState<string | null>(null);
    const [feedback, setFeedback] = useState<{ kind: 'success' | 'error'; message: string } | null>(null);

    const fetchInvitations = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/invitations');
            if (!res.ok) {
                setError(res.status === 403 ? 'No tienes permiso para ver invitaciones.' : 'Error al cargar invitaciones.');
                setInvitations([]);
                return;
            }
            const data = await res.json();
            setInvitations((data?.data ?? []) as PendingInvitation[]);
        } catch {
            setError('Error de conexión. Reintenta en un momento.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void fetchInvitations();
    }, [fetchInvitations]);

    // Limpia el toast efímero al refrescar / cambiar la lista. Mantenerlo
    // pegajoso confunde después de varias acciones consecutivas.
    useEffect(() => {
        if (!feedback) return;
        const t = setTimeout(() => setFeedback(null), 4000);
        return () => clearTimeout(t);
    }, [feedback]);

    const handleResend = async (invitation: PendingInvitation) => {
        if (busyId) return;
        setBusyId(invitation.id);
        try {
            const res = await apiFetch(`/api/v1/invitations/${invitation.id}/resend`, { method: 'POST' });
            const data = await res.json().catch(() => null);
            if (!res.ok) {
                setFeedback({ kind: 'error', message: data?.message ?? 'No se pudo reenviar la invitación.' });
                return;
            }
            setFeedback({ kind: 'success', message: data?.message ?? `Reenvío encolado para ${invitation.email}.` });
            await fetchInvitations();
            onChanged?.();
        } catch {
            setFeedback({ kind: 'error', message: 'Error de conexión al reenviar.' });
        } finally {
            setBusyId(null);
        }
    };

    const handleCancelConfirmed = async () => {
        if (!confirmCancelId) return;
        const id = confirmCancelId;
        setBusyId(id);
        try {
            const res = await apiFetch(`/api/v1/invitations/${id}`, { method: 'DELETE' });
            const data = await res.json().catch(() => null);
            if (!res.ok) {
                setFeedback({ kind: 'error', message: data?.message ?? 'No se pudo cancelar la invitación.' });
                return;
            }
            setFeedback({ kind: 'success', message: data?.message ?? 'Invitación cancelada.' });
            setConfirmCancelId(null);
            await fetchInvitations();
            onChanged?.();
        } catch {
            setFeedback({ kind: 'error', message: 'Error de conexión al cancelar.' });
        } finally {
            setBusyId(null);
        }
    };

    const cancellingInvitation = useMemo(
        () => invitations.find((i) => i.id === confirmCancelId) ?? null,
        [invitations, confirmCancelId],
    );

    const headerRight = (
        <div className="flex items-center gap-2">
            {feedback && (
                <span
                    role="status"
                    className={
                        feedback.kind === 'success'
                            ? 'text-xs text-[color:var(--color-status-ok-fg)]'
                            : 'text-destructive text-xs'
                    }
                >
                    {feedback.message}
                </span>
            )}
            <Button variant="ghost" size="sm" onClick={() => void fetchInvitations()} disabled={loading} aria-label="Actualizar invitaciones">
                <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            </Button>
        </div>
    );

    return (
        <DashboardPanel title="Invitaciones pendientes" icon={Mail} rightSlot={headerRight} dense>
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}

            {!error && loading && invitations.length === 0 && (
                <p className="text-muted-foreground text-sm">Cargando invitaciones…</p>
            )}

            {!error && !loading && invitations.length === 0 && (
                <EmptyState
                    icon={UserPlus}
                    title="Sin invitaciones pendientes"
                    description={
                        canManage
                            ? 'Cuando invites usuarios verás aquí su estado y podrás reenviar o cancelar.'
                            : 'Pedile al propietario de la empresa que invite a nuevos miembros desde aquí.'
                    }
                />
            )}

            {invitations.length > 0 && (
                <TooltipProvider delayDuration={250}>
                    <ul className="divide-border divide-y" role="list">
                        {invitations.map((invitation) => (
                            <InvitationRow
                                key={invitation.id}
                                invitation={invitation}
                                canManage={canManage}
                                busy={busyId === invitation.id}
                                onResend={() => void handleResend(invitation)}
                                onCancel={() => setConfirmCancelId(invitation.id)}
                            />
                        ))}
                    </ul>
                </TooltipProvider>
            )}

            <ConfirmDialog
                open={confirmCancelId !== null}
                title="Cancelar invitación"
                message={
                    cancellingInvitation
                        ? `¿Cancelar la invitación pendiente para ${cancellingInvitation.email}? El correo de invitación deja de funcionar y la persona tendría que ser invitada de nuevo.`
                        : '¿Cancelar la invitación pendiente?'
                }
                confirmLabel="Cancelar invitación"
                cancelLabel="No, volver"
                loading={busyId === confirmCancelId}
                onConfirm={() => void handleCancelConfirmed()}
                onCancel={() => setConfirmCancelId(null)}
            />
        </DashboardPanel>
    );
}

interface InvitationRowProps {
    invitation: PendingInvitation;
    canManage: boolean;
    busy: boolean;
    onResend: () => void;
    onCancel: () => void;
}

function InvitationRow({ invitation, canManage, busy, onResend, onCancel }: InvitationRowProps) {
    const sentLabel = formatSentLabel(invitation.email_sent_at);
    const expiresLabel = formatExpiresLabel(invitation.expires_at);

    return (
        <li className="flex flex-wrap items-center gap-3 py-3 sm:flex-nowrap">
            <div className="min-w-0 flex-1">
                <p className="text-foreground truncate text-sm font-medium" title={invitation.email}>
                    {invitation.email}
                </p>
                <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    {invitation.role_name && (
                        <RoleBadge name={invitation.role_name} color={invitation.role_color} isSystem={false} className="max-w-[10rem]" />
                    )}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="inline-flex cursor-help items-center gap-1">
                                <Clock className="h-3 w-3" aria-hidden />
                                {sentLabel.short}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">{sentLabel.detail}</TooltipContent>
                    </Tooltip>
                    {expiresLabel && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="inline-flex cursor-help items-center gap-1">
                                    <span aria-hidden>·</span>
                                    {expiresLabel.short}
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="top">{expiresLabel.detail}</TooltipContent>
                        </Tooltip>
                    )}
                </div>
            </div>

            {canManage && (
                <div className="ml-auto flex items-center gap-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button variant="outline" size="sm" onClick={onResend} disabled={busy}>
                                <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${busy ? 'animate-spin' : ''}`} />
                                Reenviar
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="top" className="max-w-xs">
                            Pide al worker procesar de nuevo el correo. Si se envió hace menos de una hora, el envío se omite (anti-spam).
                        </TooltipContent>
                    </Tooltip>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" disabled={busy} aria-label="Más acciones">
                                <MoreVertical className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={onCancel} className="text-destructive focus:text-destructive">
                                <Trash2 className="mr-2 h-4 w-4" />
                                Cancelar invitación
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            )}
        </li>
    );
}

const TIME_ZONE = 'America/Bogota';

function formatSentLabel(sentAt: string | null): { short: string; detail: string } {
    if (sentAt === null) {
        return {
            short: 'Pendiente de envío',
            detail: 'El correo todavía no se ha enviado. El worker lo procesará en los próximos segundos.',
        };
    }
    return {
        short: `Enviado ${formatRelative(sentAt)}`,
        detail: `Último envío: ${formatAbsolute(sentAt)}`,
    };
}

function formatExpiresLabel(expiresAt: string | null): { short: string; detail: string } | null {
    if (expiresAt === null) return null;
    const ms = new Date(expiresAt).getTime() - Date.now();
    if (ms <= 0) {
        return { short: 'Expirada', detail: `Expiró el ${formatAbsolute(expiresAt)}` };
    }
    const days = Math.floor(ms / 86_400_000);
    if (days >= 1) return { short: `Expira en ${days}d`, detail: `Expira el ${formatAbsolute(expiresAt)}` };
    const hours = Math.floor(ms / 3_600_000);
    if (hours >= 1) return { short: `Expira en ${hours}h`, detail: `Expira el ${formatAbsolute(expiresAt)}` };
    const minutes = Math.max(Math.floor(ms / 60_000), 1);
    return { short: `Expira en ${minutes}m`, detail: `Expira el ${formatAbsolute(expiresAt)}` };
}

function formatRelative(iso: string): string {
    const diffMs = Date.now() - new Date(iso).getTime();
    if (diffMs < 60_000) return 'hace un momento';
    const minutes = Math.floor(diffMs / 60_000);
    if (minutes < 60) return `hace ${minutes}m`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `hace ${hours}h`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `hace ${days}d`;
    return formatAbsolute(iso);
}

function formatAbsolute(iso: string): string {
    return new Date(iso).toLocaleString('es-CO', {
        timeZone: TIME_ZONE,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
