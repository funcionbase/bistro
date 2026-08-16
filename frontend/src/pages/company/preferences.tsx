import { AlertRulesConfig } from '@/components/alerts/alert-rules-config';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { cn } from '@/lib/utils';
import { type CompanySettings } from '@/types';

import { AlertCircle, BarChart3, Bell, Gift, LoaderCircle, Lock, ShoppingCart } from 'lucide-react';
import { useEffect, useState } from 'react';


const PAYMENT_OPTIONS: { value: string; label: string; hasAccount: boolean }[] = [
    { value: 'efectivo', label: 'Efectivo', hasAccount: false },
    { value: 'transferencia', label: 'Transferencia', hasAccount: true },
    { value: 'tarjeta', label: 'Tarjeta', hasAccount: false },
    { value: 'nequi', label: 'Nequi', hasAccount: true },
    { value: 'daviplata', label: 'Daviplata', hasAccount: true },
];

type ApiErrors = Partial<Record<keyof CompanySettings | 'settings', string>>;

function SettingsSkeleton() {
    return (
        <div className="grid gap-6 lg:grid-cols-2">
            {Array.from({ length: 4 }).map((_, i) => (
                <Card key={i} className="rounded-2xl shadow-sm">
                    <CardContent className="space-y-4 p-6">
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-3/4" />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export default function CompanyPreferences() {
    const token = useToken();
    const { showToast } = useToast();

    const [settings, setSettings] = useState<CompanySettings | null>(null);
    const [canUpdate, setCanUpdate] = useState(false);
    const [loading, setLoading] = useState(true);
    const [fetchError, setFetchError] = useState<string | null>(null);

    const [savingSection, setSavingSection] = useState<string | null>(null);
    const [errors, setErrors] = useState<ApiErrors>({});

    useEffect(() => {
        if (!token) {
            setLoading(false);
            setFetchError('No hay sesión activa.');
            return;
        }

        let mounted = true;

        apiFetch('/api/v1/companies/settings')
            .then((res) => res.json())
            .then((data) => {
                if (!mounted) return;
                if (data.settings) {
                    setSettings(data.settings as CompanySettings);
                    setCanUpdate(data.can_update === true);
                } else {
                    setFetchError('No se pudieron cargar las configuraciones.');
                }
            })
            .catch(() => {
                if (!mounted) return;
                setFetchError('Error de conexión al cargar configuraciones.');
            })
            .finally(() => {
                if (!mounted) return;
                setLoading(false);
            });

        return () => {
            mounted = false;
        };
    }, [token]);

    function patchField<K extends keyof CompanySettings>(key: K, value: CompanySettings[K]) {
        setSettings((prev) => (prev ? { ...prev, [key]: value } : prev));
        setErrors((prev) => {
            const next = { ...prev };
            delete next[key];
            return next;
        });
    }

    async function saveSection(sectionId: string, keys: (keyof CompanySettings)[]) {
        if (!settings || !canUpdate) return;

        const payload: Partial<CompanySettings> = {};
        for (const key of keys) {
            (payload as Record<string, unknown>)[key] = settings[key];
        }

        setSavingSection(sectionId);
        setErrors({});

        try {
            const res = await apiFetch('/api/v1/companies/settings', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: payload }),
            });

            const data = await res.json();

            if (res.ok) {
                setSettings(data.settings as CompanySettings);
                showToast('success', 'Configuración guardada.');
            } else if (res.status === 422) {
                const flat: ApiErrors = {};
                if (data.errors) {
                    for (const [field, msgs] of Object.entries(data.errors)) {
                        const key = field.replace('settings.', '') as keyof CompanySettings;
                        flat[key] = Array.isArray(msgs) ? (msgs[0] as string) : String(msgs);
                    }
                }
                setErrors(flat);
                showToast('error', 'Revisa los campos marcados en rojo.');
            } else if (res.status === 403) {
                showToast('error', 'No tienes permiso para modificar configuraciones.');
            } else {
                showToast('error', data.message ?? 'Error al guardar.');
            }
        } catch {
            showToast('error', 'Error de conexión al guardar.');
        } finally {
            setSavingSection(null);
        }
    }

    if (loading) {
        return (
            <PageShell title="Preferencias">
                <div className="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
                    <PageHeader eyebrow="PREFERENCIAS" title="Preferencias" description="Ajusta las preferencias de tu empresa." />
                    <SettingsSkeleton />
                </div>
            </PageShell>
        );
    }

    if (fetchError || !settings) {
        return (
            <PageShell title="Preferencias">
                <div className="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
                    <PageHeader eyebrow="PREFERENCIAS" title="Preferencias" description="Ajusta las preferencias de tu empresa." />
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{fetchError ?? 'No se pudieron cargar las preferencias.'}</AlertDescription>
                    </Alert>
                </div>
            </PageShell>
        );
    }

    const readOnly = !canUpdate;

    return (
        <PageShell title="Preferencias">
            <div className="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader eyebrow="PREFERENCIAS" title="Preferencias" description="Ajusta las preferencias de tu empresa." />

                {readOnly && (
                    <Alert variant="warning">
                        <Lock className="h-4 w-4" />
                        <AlertDescription>Solo el propietario puede modificar las configuraciones. Estás viendo en modo lectura.</AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <DashboardPanel title="Pedidos" icon={ShoppingCart}>
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="pref-min-order">Monto mínimo (sin decimales)</Label>
                                    <Input
                                        id="pref-min-order"
                                        type="number"
                                        min={0}
                                        value={settings.min_order_amount}
                                        onChange={(e) => patchField('min_order_amount', Number(e.target.value))}
                                        disabled={readOnly}
                                    />
                                    {errors.min_order_amount && <p className="text-destructive text-xs">{errors.min_order_amount}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="pref-delivery-area">Área de entrega (km)</Label>
                                    <Input
                                        id="pref-delivery-area"
                                        type="number"
                                        min={1}
                                        max={100}
                                        value={settings.delivery_area_km}
                                        onChange={(e) => patchField('delivery_area_km', Number(e.target.value))}
                                        disabled={readOnly}
                                    />
                                    {errors.delivery_area_km && <p className="text-destructive text-xs">{errors.delivery_area_km}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Métodos de pago</Label>
                                <div className="flex flex-col gap-2">
                                    {PAYMENT_OPTIONS.map((opt) => {
                                        const checked = settings.payment_methods.includes(opt.value);
                                        const account = settings.payment_method_accounts?.[opt.value] ?? '';
                                        const inputId = `pref-payment-${opt.value}`;
                                        return (
                                            <div key={opt.value} className="flex flex-col gap-1.5">
                                                <label
                                                    htmlFor={inputId}
                                                    className={cn(
                                                        'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                                        checked
                                                            ? 'border-primary bg-primary/5 text-foreground'
                                                            : 'border-border text-muted-foreground hover:border-foreground/30',
                                                        readOnly && 'cursor-default opacity-60',
                                                    )}
                                                >
                                                    <Checkbox
                                                        id={inputId}
                                                        checked={checked}
                                                        disabled={readOnly}
                                                        onCheckedChange={(v) => {
                                                            const next = v
                                                                ? [...settings.payment_methods, opt.value]
                                                                : settings.payment_methods.filter((m) => m !== opt.value);
                                                            patchField('payment_methods', next);
                                                        }}
                                                    />
                                                    {opt.label}
                                                </label>
                                                {checked && opt.hasAccount && (
                                                    <Input
                                                        value={account}
                                                        onChange={(e) =>
                                                            patchField('payment_method_accounts', {
                                                                ...settings.payment_method_accounts,
                                                                [opt.value]: e.target.value,
                                                            })
                                                        }
                                                        disabled={readOnly}
                                                        placeholder={`Número de cuenta / ${opt.label}`}
                                                        className="ml-7 h-8 text-sm"
                                                        maxLength={100}
                                                    />
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {errors.payment_methods && <p className="text-destructive text-xs">{errors.payment_methods}</p>}
                            </div>

                            <div className="border-border flex items-center gap-3 rounded-lg border px-3 py-2.5">
                                <Checkbox
                                    id="pref-auto-confirm"
                                    checked={settings.order_auto_confirm}
                                    disabled={readOnly}
                                    onCheckedChange={(v) => patchField('order_auto_confirm', Boolean(v))}
                                />
                                <label htmlFor="pref-auto-confirm" className="text-foreground cursor-pointer text-sm">
                                    Confirmar pedidos automáticamente
                                </label>
                            </div>
                            {errors.order_auto_confirm && <p className="text-destructive text-xs">{errors.order_auto_confirm}</p>}

                            {!readOnly && (
                                <div className="flex justify-end pt-2">
                                    <SaveButton
                                        saving={savingSection === 'orders'}
                                        onClick={() =>
                                            saveSection('orders', [
                                                'min_order_amount',
                                                'delivery_area_km',
                                                'payment_methods',
                                                'payment_method_accounts',
                                                'order_auto_confirm',
                                            ])
                                        }
                                    />
                                </div>
                            )}
                        </div>
                    </DashboardPanel>

                    <DashboardPanel title="Notificaciones" icon={Bell}>
                        <div className="space-y-4">
                            <div className="border-border flex items-center gap-3 rounded-lg border px-3 py-2.5">
                                <Checkbox
                                    id="pref-notify-email"
                                    checked={settings.order_notify_customer_email}
                                    disabled={readOnly}
                                    onCheckedChange={(v) => patchField('order_notify_customer_email', Boolean(v))}
                                />
                                <label htmlFor="pref-notify-email" className="text-foreground cursor-pointer text-sm">
                                    Enviar email de confirmación al cliente cuando se crea un pedido
                                </label>
                            </div>
                            {errors.order_notify_customer_email && <p className="text-destructive text-xs">{errors.order_notify_customer_email}</p>}

                            {!readOnly && (
                                <div className="flex justify-end pt-2">
                                    <SaveButton
                                        saving={savingSection === 'notifications'}
                                        onClick={() => saveSection('notifications', ['order_notify_customer_email'])}
                                    />
                                </div>
                            )}
                        </div>
                    </DashboardPanel>

                    {/* Fidelización — puntos por compra. LoyaltyService::award
                        lee estas claves al cerrar pago y otorga puntos
                        idempotentemente por order_id. */}
                    <DashboardPanel title="Fidelización" icon={Gift}>
                        <div className="space-y-4">
                            <div className="border-border flex items-center gap-3 rounded-lg border px-3 py-2.5">
                                <Checkbox
                                    id="pref-loyalty-enabled"
                                    checked={settings['loyalty.enabled'] === true}
                                    disabled={readOnly}
                                    onCheckedChange={(v) => patchField('loyalty.enabled', Boolean(v))}
                                />
                                <label htmlFor="pref-loyalty-enabled" className="text-foreground cursor-pointer text-sm">
                                    Activar programa de fidelización
                                </label>
                            </div>
                            {errors['loyalty.enabled'] && <p className="text-destructive text-xs">{errors['loyalty.enabled']}</p>}

                            <div className="space-y-1.5">
                                <Label htmlFor="pref-loyalty-rate">Puntos por cada $1.000 COP gastados</Label>
                                <p className="text-muted-foreground text-xs">
                                    Cuando el cliente paga y tiene celular registrado, se le acreditan estos puntos por cada $1.000 COP del total.
                                    Sugerido: 1 punto por cada $1.000.
                                </p>
                                <Input
                                    id="pref-loyalty-rate"
                                    type="number"
                                    min={0}
                                    max={1000}
                                    step={1}
                                    value={Math.round((parseFloat(settings['loyalty.points_per_cop'] || '0') || 0) * 1000)}
                                    onChange={(e) => {
                                        const perKilo = Math.max(0, Math.min(1000, parseInt(e.target.value || '0', 10)));
                                        patchField('loyalty.points_per_cop', (perKilo / 1000).toFixed(6));
                                    }}
                                    disabled={readOnly || settings['loyalty.enabled'] !== true}
                                    className="w-32"
                                />
                                {errors['loyalty.points_per_cop'] && <p className="text-destructive text-xs">{errors['loyalty.points_per_cop']}</p>}
                            </div>

                            {!readOnly && (
                                <div className="flex justify-end pt-2">
                                    <SaveButton
                                        saving={savingSection === 'loyalty'}
                                        onClick={() => saveSection('loyalty', ['loyalty.enabled', 'loyalty.points_per_cop'])}
                                    />
                                </div>
                            )}
                        </div>
                    </DashboardPanel>

                    {/* Reportes — food cost */}
                    <DashboardPanel title="Reportes" icon={BarChart3}>
                        <div className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="food-cost-threshold">Margen mínimo aceptable (%)</Label>
                                <p className="text-muted-foreground text-xs">
                                    En el reporte de costo de alimentos, los platos cuyo margen quede por debajo de este porcentaje aparecerán
                                    marcados como "margen bajo". Recomendado: entre 25% y 40%.
                                </p>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="food-cost-threshold"
                                        type="number"
                                        min={1}
                                        max={99}
                                        step={1}
                                        value={Math.round((parseFloat(settings.food_cost_alert_threshold || '0.30') || 0.3) * 100)}
                                        onChange={(e) => {
                                            const pct = Math.max(1, Math.min(99, parseInt(e.target.value || '30', 10)));
                                            patchField('food_cost_alert_threshold', (pct / 100).toFixed(2));
                                        }}
                                        disabled={readOnly}
                                        className="w-24"
                                    />
                                    <span className="text-muted-foreground text-sm">%</span>
                                </div>
                                {errors.food_cost_alert_threshold && <p className="text-destructive text-xs">{errors.food_cost_alert_threshold}</p>}
                            </div>

                            {!readOnly && (
                                <div className="flex justify-end pt-2">
                                    <SaveButton
                                        saving={savingSection === 'reports'}
                                        onClick={() => saveSection('reports', ['food_cost_alert_threshold'])}
                                    />
                                </div>
                            )}
                        </div>
                    </DashboardPanel>
                </div>

                {/*
                    Alertas accionables. Solo lectura para no-owners
                    (la validación final la hace el backend con permission:company.update).
                */}
                {!readOnly && <AlertRulesConfig />}
            </div>
        </PageShell>
    );
}

function SaveButton({ saving, onClick }: { saving: boolean; onClick: () => void }) {
    return (
        <Button onClick={onClick} disabled={saving} size="sm" className="min-w-24">
            {saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Guardar'}
        </Button>
    );
}
