import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { useAlertRules, type AlertRule, type AlertType } from '@/hooks/use-alerts';
import { AlertCircle, Bell, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RuleMeta {
    title: string;
    description: string;
    thresholdLabel: string;
    thresholdHint: string;
    /** Convierte el valor mostrado en el input al valor enviado al backend. */
    toApi: (display: number) => number;
    /** Convierte el valor del backend al valor mostrado en el input. */
    toDisplay: (api: number) => number;
    /** Si false, oculta el control de threshold (la regla no lo usa). */
    showThreshold: boolean;
}

const META: Record<AlertType, RuleMeta> = {
    margin_below: {
        title: 'Margen bajo',
        description: 'Avisar cuando un plato tenga margen menor al umbral en el período.',
        thresholdLabel: 'Margen mínimo (%)',
        thresholdHint: 'Por debajo de este margen se dispara la alerta.',
        toApi: (d) => d / 100,
        toDisplay: (a) => Math.round(a * 1000) / 10,
        showThreshold: true,
    },
    cost_increase: {
        title: 'Costo en alza',
        description: 'Avisar cuando el costo promedio de un insumo suba más del umbral.',
        thresholdLabel: 'Incremento mínimo (%)',
        thresholdHint: 'Comparado contra el período anterior del mismo largo.',
        toApi: (d) => d / 100,
        toDisplay: (a) => Math.round(a * 1000) / 10,
        showThreshold: true,
    },
    item_low_volume: {
        title: 'Platos sin ventas',
        description: 'Avisar de platos activos sin ventas durante el período.',
        thresholdLabel: '',
        thresholdHint: '',
        toApi: (d) => d,
        toDisplay: (a) => a,
        showThreshold: false,
    },
    low_stock: {
        title: 'Existencias bajas',
        description: 'Avisar de insumos con existencias al o por debajo del mínimo declarado.',
        thresholdLabel: '',
        thresholdHint: 'Usa el mínimo de existencias por insumo (configurable en Inventario).',
        toApi: (d) => d,
        toDisplay: (a) => a,
        showThreshold: false,
    },
};

const ORDER: AlertType[] = ['margin_below', 'cost_increase', 'item_low_volume', 'low_stock'];

/**
 * Configuración de las 4 reglas de alerta. Se embebe en
 * /company/preferences. Una sola tarjeta agrupa todas — cada regla tiene su
 * propio botón de guardar para no acoplar cambios.
 */
export function AlertRulesConfig() {
    const { rules, loading, error, update } = useAlertRules();
    const { showToast } = useToast();
    const [draft, setDraft] = useState<Record<AlertType, AlertRule | null>>({
        margin_below: null,
        cost_increase: null,
        item_low_volume: null,
        low_stock: null,
    });
    const [saving, setSaving] = useState<AlertType | null>(null);

    useEffect(() => {
        if (rules.length === 0) return;
        const next: Record<AlertType, AlertRule | null> = {
            margin_below: null,
            cost_increase: null,
            item_low_volume: null,
            low_stock: null,
        };
        for (const r of rules) next[r.type] = r;
        setDraft(next);
    }, [rules]);

    const handleField = <K extends keyof AlertRule>(type: AlertType, key: K, value: AlertRule[K]) => {
        setDraft((prev) => {
            const current = prev[type];
            if (!current) return prev;
            return { ...prev, [type]: { ...current, [key]: value } };
        });
    };

    const handleSave = async (type: AlertType) => {
        const rule = draft[type];
        if (!rule) return;
        setSaving(type);
        try {
            await update(type, {
                threshold: rule.threshold,
                period_days: rule.period_days,
                enabled: rule.enabled,
                notify_dashboard: rule.notify_dashboard,
                notify_whatsapp: rule.notify_whatsapp,
            });
            showToast('success', `Regla "${META[type].title}" guardada.`);
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error al guardar.');
        } finally {
            setSaving(null);
        }
    };

    return (
        <DashboardPanel title="Alertas accionables" icon={Bell}>
            <p className="text-muted-foreground -mt-2 mb-4 text-sm">
                Reglas que disparan avisos en el dashboard cuando hay riesgo de margen, costos o existencias. Solo visibles para usuarios con permiso de
                reportes.
            </p>

            <div className="space-y-4">
                {loading && (
                    <div className="space-y-3">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-24 w-full" />
                        ))}
                    </div>
                )}

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {!loading &&
                    ORDER.map((type) => {
                        const rule = draft[type];
                        const meta = META[type];
                        if (!rule) return null;
                        const displayThreshold = meta.toDisplay(rule.threshold);
                        const enabledId = `alert-rule-${type}-enabled`;
                        const thresholdId = `alert-rule-${type}-threshold`;
                        const periodId = `alert-rule-${type}-period`;
                        return (
                            <div key={type} className="border-border rounded-lg border p-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <div className="text-foreground text-sm font-medium">{meta.title}</div>
                                        <p className="text-muted-foreground text-xs">{meta.description}</p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Checkbox
                                            id={enabledId}
                                            checked={rule.enabled}
                                            onCheckedChange={(c) => handleField(type, 'enabled', Boolean(c))}
                                        />
                                        <Label htmlFor={enabledId} className="cursor-pointer text-sm">
                                            Activa
                                        </Label>
                                    </div>
                                </div>

                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    {meta.showThreshold && (
                                        <div className="space-y-1.5">
                                            <Label htmlFor={thresholdId} className="text-xs">
                                                {meta.thresholdLabel}
                                            </Label>
                                            <Input
                                                id={thresholdId}
                                                type="number"
                                                step="1"
                                                min={0}
                                                value={displayThreshold}
                                                onChange={(e) => handleField(type, 'threshold', meta.toApi(Number(e.target.value) || 0))}
                                            />
                                            <p className="text-muted-foreground text-[11px]">{meta.thresholdHint}</p>
                                        </div>
                                    )}
                                    <div className="space-y-1.5">
                                        <Label htmlFor={periodId} className="text-xs">
                                            Período (días)
                                        </Label>
                                        <Input
                                            id={periodId}
                                            type="number"
                                            step={1}
                                            min={1}
                                            max={365}
                                            value={rule.period_days}
                                            onChange={(e) => handleField(type, 'period_days', Math.max(1, Number(e.target.value) || 1))}
                                        />
                                    </div>
                                </div>

                                <div className="mt-3 flex justify-end">
                                    <Button onClick={() => void handleSave(type)} disabled={saving === type} size="sm" className="min-w-24">
                                        {saving === type ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Guardar'}
                                    </Button>
                                </div>
                            </div>
                        );
                    })}
            </div>
        </DashboardPanel>
    );
}
