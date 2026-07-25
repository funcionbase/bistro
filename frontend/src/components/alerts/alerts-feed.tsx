import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAlerts, type AlertEvent, type AlertSeverity } from '@/hooks/use-alerts';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { route } from '@/lib/route-compat';
import { AlertCircle, AlertTriangle, ChevronRight, Info, Loader2, X } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

const SEVERITY_META: Record<AlertSeverity, { label: string; color: string; icon: typeof Info }> = {
    critical: {
        label: 'Crítico',
        color: 'bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)] border-[color:var(--color-status-critical)]/30',
        icon: AlertCircle,
    },
    warning: {
        label: 'Aviso',
        color: 'bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)] border-[color:var(--color-status-warning)]/30',
        icon: AlertTriangle,
    },
    info: {
        label: 'Info',
        color: 'bg-primary/10 text-primary border-primary/30',
        icon: Info,
    },
};

/**
 * Feed compacto de alertas activas. Se embebe en /dashboard arriba de los
 * KPIs. Sólo renderiza si hay alertas (no ocupa espacio cuando está vacío).
 */
export function AlertsFeed() {
    const { alerts, loading, error, dismiss, action } = useAlerts('active');
    const [busy, setBusy] = useState<string | null>(null);
    // Dialog "Marcar revisado": reemplaza al window.prompt (bloqueante, sin
    // estilo del DS y sin sanitización). Guarda el id de la alerta objetivo.
    const [actionTarget, setActionTarget] = useState<string | null>(null);
    const [actionNote, setActionNote] = useState('');
    const [actionError, setActionError] = useState<string | null>(null);

    if (loading && alerts.length === 0) {
        return (
            <Card className="rounded-2xl shadow-sm">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Loader2 className="h-4 w-4 animate-spin" /> Cargando alertas...
                    </CardTitle>
                </CardHeader>
            </Card>
        );
    }

    if (error) {
        return (
            <Card className="rounded-2xl shadow-sm">
                <CardContent className="text-destructive py-4 text-sm">{error}</CardContent>
            </Card>
        );
    }

    if (alerts.length === 0) {
        return null;
    }

    const handleDismiss = async (id: string) => {
        setBusy(id);
        try {
            await dismiss(id);
        } finally {
            setBusy(null);
        }
    };

    const handleAction = (id: string) => {
        setActionTarget(id);
        setActionNote('');
        setActionError(null);
    };

    const confirmAction = async () => {
        if (!actionTarget) return;
        setBusy(actionTarget);
        setActionError(null);
        try {
            await action(actionTarget, actionNote.trim() || undefined);
            setActionTarget(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'No se pudo marcar la alerta como revisada.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            <Card className="rounded-2xl shadow-sm">
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <AlertTriangle className="h-4 w-4" />
                        Alertas activas
                        <Badge variant="outline">{alerts.length}</Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {alerts.map((alert) => (
                        <AlertRow key={alert.id} alert={alert} busy={busy === alert.id} onDismiss={handleDismiss} onAction={handleAction} />
                    ))}
                </CardContent>
            </Card>

            <Dialog open={actionTarget !== null} onOpenChange={(o) => !o && setActionTarget(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Marcar alerta como revisada</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-1.5">
                        <Label htmlFor="alert-action-note">Nota opcional sobre la acción tomada</Label>
                        <Input
                            id="alert-action-note"
                            value={actionNote}
                            onChange={(e) => setActionNote(sanitizePlainText(e.target.value, 500, true, false))}
                            maxLength={500}
                            placeholder="Ej: se ajustó el precio del plato"
                        />
                    </div>
                    {actionError && <p className="text-destructive text-sm">{actionError}</p>}
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setActionTarget(null)} disabled={busy !== null}>
                            Cancelar
                        </Button>
                        <Button onClick={() => void confirmAction()} disabled={busy !== null}>
                            {busy !== null ? 'Guardando…' : 'Confirmar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

interface AlertRowProps {
    alert: AlertEvent;
    busy: boolean;
    onDismiss: (id: string) => void | Promise<void>;
    onAction: (id: string) => void | Promise<void>;
}

function AlertRow({ alert, busy, onDismiss, onAction }: AlertRowProps) {
    const navigate = useNavigate();
    const meta = SEVERITY_META[alert.severity];
    const Icon = meta.icon;
    const payload = alert.payload as Record<string, unknown>;
    const name = (payload.name as string | undefined) ?? alert.target_id ?? 'global';

    const headline = describeAlert(alert);
    const deepLink = deepLinkFor(alert);

    return (
        <div className={`rounded-md border p-3 ${meta.color}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="flex items-start gap-2">
                    <Icon className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <div className="text-sm font-medium">
                            {meta.label} · {humanType(alert.type)}
                        </div>
                        <div className="text-sm">{headline}</div>
                        <div className="mt-1 text-xs opacity-80">{name}</div>
                    </div>
                </div>
                <button
                    type="button"
                    aria-label="Descartar"
                    title="Descartar"
                    onClick={() => void onDismiss(alert.id)}
                    disabled={busy}
                    className="hover:bg-background/40 rounded p-1 disabled:opacity-50"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
                {deepLink && (
                    <Button variant="outline" size="sm" onClick={() => navigate(deepLink.url)} disabled={busy}>
                        {deepLink.label}
                        <ChevronRight className="ml-1 h-3 w-3" />
                    </Button>
                )}
                <Button variant="ghost" size="sm" onClick={() => void onAction(alert.id)} disabled={busy}>
                    Marcar revisado
                </Button>
            </div>
        </div>
    );
}

function humanType(t: AlertEvent['type']): string {
    switch (t) {
        case 'margin_below':
            return 'Margen bajo';
        case 'cost_increase':
            return 'Costo en alza';
        case 'item_low_volume':
            return 'Sin ventas';
        case 'low_stock':
            return 'Existencias bajas';
    }
}

function describeAlert(alert: AlertEvent): string {
    const p = alert.payload as Record<string, unknown>;
    switch (alert.type) {
        case 'margin_below': {
            const margin = ((p.margin as number) ?? 0) * 100;
            const threshold = ((p.threshold as number) ?? 0) * 100;
            return `Margen ${margin.toFixed(1)}% (umbral ${threshold.toFixed(0)}%) · ${p.units_sold ?? 0} unidades`;
        }
        case 'cost_increase': {
            const inc = ((p.increase as number) ?? 0) * 100;
            return `Costo subió ${inc.toFixed(1)}% en ${p.period_days ?? '?'}d · prom $${formatNum(p.recent_avg)}`;
        }
        case 'item_low_volume': {
            return `Sin ventas en ${p.period_days ?? '?'} días`;
        }
        case 'low_stock': {
            return `Existencias ${p.stock ?? '?'} ${p.unit ?? ''} (mínimo ${p.min_stock ?? '?'})`;
        }
    }
}

function deepLinkFor(alert: AlertEvent): { label: string; url: string } | null {
    try {
        if (alert.target_type === 'menu_item') {
            return { label: 'Ver menú', url: route('menu') };
        }
        if (alert.target_type === 'ingredient') {
            return { label: 'Ver inventario', url: route('inventory') };
        }
    } catch {
        return null;
    }
    return null;
}

function formatNum(v: unknown): string {
    if (typeof v !== 'number') return '0';
    return new Intl.NumberFormat('es-CO').format(Math.round(v));
}
