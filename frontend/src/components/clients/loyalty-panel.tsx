import { LoyaltyBadge } from '@/components/loyalty-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useLoyalty } from '@/hooks/use-loyalty';
import { formatCurrency, formatDate } from '@/lib/coupon-helpers';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { cn } from '@/lib/utils';
import { AlertCircle, Award, CheckCircle2, HelpCircle, Sparkles, Wallet } from 'lucide-react';
import { useState } from 'react';

interface LoyaltyPanelProps {
    token: string | null;
    phone: string;
    canEdit: boolean;
}

/**
 * Panel de fidelización en /clients/{phone} (#122). Muestra balance, tier,
 * progreso al siguiente, catálogo de recompensas (canjeables y bloqueadas),
 * historial de movimientos y modales para ajuste manual / canje en nombre
 * del cliente.
 *
 * Cuando la empresa tiene el programa deshabilitado o el cliente no tiene
 * cuenta aún, el endpoint devuelve un placeholder con balance 0.
 */
export function LoyaltyPanel({ token, phone, canEdit }: LoyaltyPanelProps) {
    const { account, loading, error, adjust, redeem } = useLoyalty(token, phone);
    const [adjustOpen, setAdjustOpen] = useState(false);
    const [redeemKey, setRedeemKey] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [lastCouponCode, setLastCouponCode] = useState<string | null>(null);

    if (loading && !account) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Fidelización</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    <Skeleton className="h-16 w-full" />
                    <Skeleton className="h-8 w-3/4" />
                </CardContent>
            </Card>
        );
    }

    if (error) {
        return (
            <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>{error}</AlertDescription>
            </Alert>
        );
    }

    if (!account) return null;

    const enabled = account.config?.enabled ?? false;
    const rewards = Object.entries(account.rewards ?? {});
    const progress = account.tier_progress;

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <Sparkles className="h-4 w-4 text-[color:var(--color-status-warning)]" />
                        Fidelización
                    </CardTitle>
                    <LoyaltyBadge tier={account.tier} size="md" />
                </CardHeader>
                <CardContent className="space-y-4">
                    {!enabled && (
                        <Alert variant="warning">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>
                                El programa de fidelización está deshabilitado para esta empresa. Los datos mostrados son históricos.
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                        <Stat
                            label="Saldo"
                            value={`${account.balance.toLocaleString('es-CO')} pts`}
                            highlight
                            icon={<Wallet className="h-3 w-3" />}
                        />
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="cursor-help">
                                        <Stat
                                            label={
                                                <span className="flex items-center gap-1">
                                                    Lifetime
                                                    <HelpCircle className="h-3 w-3" />
                                                </span>
                                            }
                                            value={`${account.lifetime_earned.toLocaleString('es-CO')} pts`}
                                        />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent side="bottom" className="max-w-xs text-xs">
                                    Suma histórica de puntos ganados por compras. Los ajustes manuales
                                    suman al Saldo pero no al Lifetime, por eso el Saldo puede superar
                                    este valor.
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <Stat label="Tier actual" value={account.tier.toUpperCase()} />
                        <Stat label="Última actividad" value={formatDate(account.last_activity_at)} />
                    </div>

                    {progress.next_tier !== null && (
                        <div className="bg-muted/40 rounded-md border p-3 text-sm">
                            <div className="mb-1.5 flex items-center justify-between text-xs">
                                <span className="text-muted-foreground">
                                    Te faltan <strong className="text-foreground">{progress.points_to_next}</strong> pts para{' '}
                                    <strong className="text-foreground uppercase">{progress.next_tier}</strong>
                                </span>
                                <span className="text-muted-foreground tabular-nums">{progress.progress_pct.toFixed(0)}%</span>
                            </div>
                            <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                                <div
                                    className="bg-primary h-full rounded-full transition-all"
                                    style={{ width: `${Math.min(100, progress.progress_pct)}%` }}
                                />
                            </div>
                        </div>
                    )}

                    {canEdit && enabled && rewards.length > 0 && (
                        <div>
                            <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">Canjear a nombre del cliente</h3>
                            <ul className="grid gap-2 md:grid-cols-2">
                                {rewards.map(([key, r]) => {
                                    const canRedeem = account.balance >= r.points;
                                    return (
                                        <li
                                            key={key}
                                            className={cn(
                                                'flex items-center justify-between gap-2 rounded border p-2 text-sm',
                                                canRedeem ? 'bg-background' : 'bg-muted/40 opacity-70',
                                            )}
                                        >
                                            <div>
                                                <div className="font-medium">{r.label}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {r.points} pts · mínimo {formatCurrency(r.min_order_amount)}
                                                </div>
                                            </div>
                                            <Button size="sm" disabled={!canRedeem} onClick={() => setRedeemKey(key)}>
                                                Canjear
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {canEdit && (
                        <div className="flex gap-2 pt-2">
                            <Button size="sm" variant="outline" onClick={() => setAdjustOpen(true)}>
                                <Award className="mr-1 h-4 w-4" />
                                Ajustar puntos manualmente
                            </Button>
                        </div>
                    )}

                    {lastCouponCode && (
                        <Alert variant="safe">
                            <CheckCircle2 className="h-4 w-4" />
                            <AlertDescription>
                                Canje generado. Código del cupón: <code className="font-mono font-semibold">{lastCouponCode}</code>
                            </AlertDescription>
                        </Alert>
                    )}

                    {account.movements.length > 0 && (
                        <div>
                            <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                                Movimientos recientes ({account.movements.length})
                            </h3>
                            <ul className="bg-card divide-y rounded-lg border text-sm shadow-sm">
                                {account.movements.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between gap-2 p-2">
                                        <div className="flex items-center gap-2">
                                            <span className="bg-muted rounded px-1.5 py-0.5 font-mono text-[10px] uppercase">{m.type}</span>
                                            <span className="text-muted-foreground text-xs">{formatDate(m.created_at)}</span>
                                            {m.actor_name && <span className="text-muted-foreground text-xs">· {m.actor_name}</span>}
                                            {m.reference_type === 'order' && (
                                                <span className="text-muted-foreground text-xs">· orden #{m.reference_id}</span>
                                            )}
                                        </div>
                                        <span
                                            className={cn(
                                                'font-semibold tabular-nums',
                                                m.points >= 0 ? 'text-[color:var(--color-status-safe)]' : 'text-destructive',
                                            )}
                                        >
                                            {m.points >= 0 ? '+' : ''}
                                            {m.points} pts
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </CardContent>
            </Card>

            <AdjustModal
                open={adjustOpen}
                onClose={() => {
                    setAdjustOpen(false);
                    setActionError(null);
                }}
                onSubmit={async (points, reason) => {
                    setActionError(null);
                    try {
                        await adjust(points, reason);
                        setAdjustOpen(false);
                    } catch (e) {
                        setActionError(e instanceof Error ? e.message : 'Error al ajustar.');
                    }
                }}
                error={actionError}
            />

            <Dialog open={redeemKey !== null} onOpenChange={(open) => !open && setRedeemKey(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirmar canje</DialogTitle>
                    </DialogHeader>
                    {redeemKey !== null && (
                        <div className="space-y-2 text-sm">
                            <p>
                                Vas a canjear <strong>{account.rewards[redeemKey]?.label}</strong> por{' '}
                                <strong>{account.rewards[redeemKey]?.points} puntos</strong> en nombre de este cliente.
                            </p>
                            <p className="text-muted-foreground text-xs">
                                Se generará un cupón temporal vinculado a su teléfono. El cliente debe usarlo dentro del tiempo configurado.
                            </p>
                            {actionError && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{actionError}</AlertDescription>
                                </Alert>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRedeemKey(null)}>
                            Cancelar
                        </Button>
                        <Button
                            onClick={async () => {
                                if (!redeemKey) return;
                                setActionError(null);
                                try {
                                    const result = await redeem(redeemKey);
                                    setLastCouponCode(result.coupon_code);
                                    setRedeemKey(null);
                                } catch (e) {
                                    setActionError(e instanceof Error ? e.message : 'Error al canjear.');
                                }
                            }}
                        >
                            Canjear
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Stat({ label, value, highlight, icon }: { label: React.ReactNode; value: string; highlight?: boolean; icon?: React.ReactNode }) {
    return (
        <div className={cn('rounded-md p-3', highlight ? 'bg-accent text-accent-foreground' : 'bg-muted/40')}>
            <div
                className={cn(
                    'flex items-center gap-1 text-xs tracking-wide uppercase',
                    highlight ? 'text-accent-foreground/80' : 'text-muted-foreground',
                )}
            >
                {icon}
                {label}
            </div>
            <div className="mt-1 font-semibold tabular-nums">{value}</div>
        </div>
    );
}

function AdjustModal({
    open,
    onClose,
    onSubmit,
    error,
}: {
    open: boolean;
    onClose: () => void;
    onSubmit: (points: number, reason: string) => Promise<void>;
    error: string | null;
}) {
    const [points, setPoints] = useState('');
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) {
                    onClose();
                    setPoints('');
                    setReason('');
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Ajustar puntos manualmente</DialogTitle>
                </DialogHeader>
                <div className="space-y-3 text-sm">
                    <div>
                        <label className="mb-1 block text-xs font-medium">Puntos (positivo o negativo)</label>
                        <Input type="number" value={points} onChange={(e) => setPoints(e.target.value)} placeholder="Ej: 100 o -50" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium">Motivo</label>
                        <textarea
                            value={reason}
                            onChange={(e) => setReason(sanitizePlainText(e.target.value, 255, true, false))}
                            placeholder="Ej: Compensación por queja, evento de campaña, etc."
                            rows={3}
                            maxLength={255}
                            className="border-input bg-background ring-offset-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                    </div>
                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button
                        disabled={submitting || !points || !reason || reason.length < 3}
                        onClick={async () => {
                            setSubmitting(true);
                            try {
                                await onSubmit(Number(points), reason);
                                setPoints('');
                                setReason('');
                            } finally {
                                setSubmitting(false);
                            }
                        }}
                    >
                        Aplicar ajuste
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
