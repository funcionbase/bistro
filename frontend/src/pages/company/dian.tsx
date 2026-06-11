import { Lock } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { createResolution, deactivateResolution, getProviderConfig, listResolutions, updateProviderConfig } from '@/lib/dian-api';
import type { DianDocumentType, DianProviderConfig, DianResolution } from '@/types/dian';

/**
 * Configuración → Facturación DIAN (HU #235 — pantalla owner-only).
 *
 * 3 tabs: Proveedor · Resoluciones · Cliente por defecto.
 * Cada tab es un componente local pequeño. Reutiliza componentes del DS
 * (PageHeader, Card, Tabs, Alert, SanitizedInput, Select, Skeleton).
 *
 * El perfil fiscal del emisor se edita ahora desde /company/settings →
 * "Información" (componente CompanyFiscalSection), no acá.
 *
 * Por ahora la pantalla es SOLO INFORMATIVA: la facturación DIAN aún no se
 * libera para edición, así que los controles van deshabilitados y las acciones
 * (guardar, registrar, desactivar) se ocultan. Cuando se habilite la edición,
 * flipear DIAN_EDITABLE a true (o cablearlo a un gate/feature-flag real).
 */
const DIAN_EDITABLE = false;

function InformationalLockBanner() {
    return (
        <Alert>
            <Lock className="h-4 w-4" />
            <AlertTitle>Vista informativa</AlertTitle>
            <AlertDescription>
                La facturación electrónica DIAN todavía no está habilitada para edición. Por ahora puedes explorar la configuración para irla
                conociendo; pronto podrás conectar tu proveedor y registrar tus resoluciones.
            </AlertDescription>
        </Alert>
    );
}

export default function DianConfigPage() {
    return (
        <PageShell title="Facturación DIAN">
            <div className="flex flex-col gap-6">
                <PageHeader
                    eyebrow="CONFIGURACIÓN"
                    title="Facturación electrónica DIAN"
                    description="Proveedor, resoluciones autorizadas y adquirente por defecto."
                    variant="dense"
                    showBranchBadge={false}
                />

                {!DIAN_EDITABLE && <InformationalLockBanner />}

                <Tabs defaultValue="provider" className="w-full">
                    <TabsList className="max-w-full overflow-x-auto">
                        <TabsTrigger value="provider">Proveedor</TabsTrigger>
                        <TabsTrigger value="resolutions">Resoluciones</TabsTrigger>
                        <TabsTrigger value="recipient">Contacto por defecto</TabsTrigger>
                    </TabsList>

                    <TabsContent value="provider" className="mt-4">
                        <ProviderTab editable={DIAN_EDITABLE} />
                    </TabsContent>
                    <TabsContent value="resolutions" className="mt-4">
                        <ResolutionsTab editable={DIAN_EDITABLE} />
                    </TabsContent>
                    <TabsContent value="recipient" className="mt-4">
                        <DefaultRecipientTab />
                    </TabsContent>
                </Tabs>
            </div>
        </PageShell>
    );
}

/* ============== TAB 2: PROVEEDOR ============== */

function ProviderTab({ editable }: { editable: boolean }) {
    const [config, setConfig] = useState<DianProviderConfig | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [form, setForm] = useState({
        provider_slug: 'mock',
        api_base_url: '',
        api_token: '',
        software_id: '',
        software_pin: '',
        test_set_id: '',
        environment: 'habilitacion' as 'habilitacion' | 'produccion',
        webhook_secret: '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [okMsg, setOkMsg] = useState<string | null>(null);

    useEffect(() => {
        getProviderConfig()
            .then(({ data }) => {
                if (data) {
                    setConfig(data);
                    setForm((prev) => ({
                        ...prev,
                        provider_slug: data.provider_slug,
                        api_base_url: data.api_base_url ?? '',
                        software_id: data.software_id ?? '',
                        test_set_id: data.test_set_id ?? '',
                        environment: data.environment,
                    }));
                }
            })
            .catch((e) => setError(e instanceof Error ? e.message : 'Error'))
            .finally(() => setLoaded(true));
    }, []);

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        setOkMsg(null);
        try {
            const payload = {
                ...form,
                api_token: form.api_token || null,
                software_pin: form.software_pin || null,
                webhook_secret: form.webhook_secret || null,
                api_base_url: form.api_base_url || null,
                software_id: form.software_id || null,
                test_set_id: form.test_set_id || null,
            };
            const { data } = await updateProviderConfig(payload);
            setConfig(data);
            setForm((prev) => ({ ...prev, api_token: '', software_pin: '', webhook_secret: '' }));
            setOkMsg('Configuración del proveedor guardada. Tokens enmascarados — recuérdalos por separado.');
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error al guardar');
        } finally {
            setSaving(false);
        }
    };

    if (!loaded) return <Skeleton className="h-96 w-full" />;

    return (
        <Card className="space-y-4 p-4">
            {config && (
                <Alert>
                    <AlertTitle>Proveedor activo: {config.provider_slug}</AlertTitle>
                    <AlertDescription>
                        Ambiente: <strong>{config.environment}</strong> · {config.has_api_token ? '🔒 token configurado' : '⚠️ sin token'} ·{' '}
                        {config.has_webhook_secret ? '🔒 webhook secret configurado' : '⚠️ sin webhook secret'}
                    </AlertDescription>
                </Alert>
            )}

            {form.provider_slug === 'mock' && form.environment === 'produccion' && (
                <Alert variant="destructive">
                    <AlertTitle>Combinación inválida</AlertTitle>
                    <AlertDescription>
                        El proveedor <code>mock</code> NO puede emitir en ambiente productivo. Cambiá a un proveedor real antes de pasar a producción.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <Label>Proveedor</Label>
                    <Select value={form.provider_slug} onValueChange={(v) => setForm({ ...form, provider_slug: v })} disabled={!editable}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="mock">Mock (desarrollo / habilitación)</SelectItem>
                            <SelectItem value="factura1">Factura1 (próximamente)</SelectItem>
                            <SelectItem value="siigo">Siigo (próximamente)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label>Ambiente</Label>
                    <Select
                        value={form.environment}
                        onValueChange={(v) => setForm({ ...form, environment: v as 'habilitacion' | 'produccion' })}
                        disabled={!editable}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="habilitacion">Habilitación (pruebas)</SelectItem>
                            <SelectItem value="produccion">Producción</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="md:col-span-2">
                    <Label htmlFor="apiBase">URL base API</Label>
                    <Input
                        id="apiBase"
                        value={form.api_base_url}
                        onChange={(e) => setForm({ ...form, api_base_url: e.target.value })}
                        placeholder="https://api.proveedor.com/v1"
                        disabled={!editable}
                    />
                </div>
                <div>
                    <Label htmlFor="softwareId">Software ID</Label>
                    <Input
                        id="softwareId"
                        value={form.software_id}
                        onChange={(e) => setForm({ ...form, software_id: e.target.value })}
                        disabled={!editable}
                    />
                </div>
                <div>
                    <Label htmlFor="testSetId">Test set ID</Label>
                    <Input
                        id="testSetId"
                        value={form.test_set_id}
                        onChange={(e) => setForm({ ...form, test_set_id: e.target.value })}
                        disabled={!editable}
                    />
                </div>
                <div>
                    <Label htmlFor="apiToken">API token (rotar)</Label>
                    <Input
                        id="apiToken"
                        type="password"
                        value={form.api_token}
                        onChange={(e) => setForm({ ...form, api_token: e.target.value })}
                        placeholder="Pegar para rotar — vacío conserva actual"
                        disabled={!editable}
                    />
                </div>
                <div>
                    <Label htmlFor="softwarePin">Software PIN (rotar)</Label>
                    <Input
                        id="softwarePin"
                        type="password"
                        value={form.software_pin}
                        onChange={(e) => setForm({ ...form, software_pin: e.target.value })}
                        placeholder="Vacío conserva actual"
                        disabled={!editable}
                    />
                </div>
                <div className="md:col-span-2">
                    <Label htmlFor="webhookSecret">Webhook secret (rotar)</Label>
                    <Input
                        id="webhookSecret"
                        type="password"
                        value={form.webhook_secret}
                        onChange={(e) => setForm({ ...form, webhook_secret: e.target.value })}
                        placeholder="Vacío genera uno aleatorio al guardar"
                        disabled={!editable}
                    />
                </div>
            </div>

            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            {okMsg && (
                <Alert variant="safe">
                    <AlertDescription>{okMsg}</AlertDescription>
                </Alert>
            )}

            {editable && (
                <div className="flex justify-end">
                    <Button onClick={handleSave} disabled={saving}>
                        {saving ? 'Guardando...' : 'Guardar proveedor'}
                    </Button>
                </div>
            )}
        </Card>
    );
}

/* ============== TAB 3: RESOLUCIONES ============== */

const DOC_TYPES_RESOLUTION: { value: DianDocumentType; label: string }[] = [
    { value: 'pos_equivalent', label: 'DEE POS (consumidor final)' },
    { value: 'invoice', label: 'Factura electrónica (FEV)' },
    { value: 'credit_note', label: 'Nota crédito FEV' },
    { value: 'debit_note', label: 'Nota débito FEV' },
];

function ResolutionsTab({ editable }: { editable: boolean }) {
    const [resolutions, setResolutions] = useState<DianResolution[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [creating, setCreating] = useState(false);
    const [confirmDeactivateId, setConfirmDeactivateId] = useState<string | null>(null);
    const [form, setForm] = useState({
        document_type: 'pos_equivalent' as DianDocumentType,
        prefix: 'PO',
        range_from: 1,
        range_to: 5000,
        resolution_number: '',
        valid_from: new Date().toISOString().slice(0, 10),
        valid_until: new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 10),
        technical_key: '',
        environment: 'habilitacion' as 'habilitacion' | 'produccion',
    });
    const [error, setError] = useState<string | null>(null);

    const fetchAll = () => {
        listResolutions()
            .then(({ data }) => setResolutions(data))
            .catch((e) => setError(e instanceof Error ? e.message : 'Error'))
            .finally(() => setLoaded(true));
    };

    useEffect(fetchAll, []);

    const handleCreate = async () => {
        setError(null);
        try {
            await createResolution({
                ...form,
                is_active: true,
            } as Partial<DianResolution> & { technical_key: string });
            setCreating(false);
            fetchAll();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error al crear');
        }
    };

    const handleDeactivate = async () => {
        if (confirmDeactivateId === null) return;
        try {
            await deactivateResolution(confirmDeactivateId);
            setConfirmDeactivateId(null);
            fetchAll();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error');
        }
    };

    if (!loaded) return <Skeleton className="h-96 w-full" />;

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-muted-foreground text-sm">
                    Una empresa típicamente registra 2 resoluciones activas: DEE POS y Factura Electrónica.
                </p>
                {editable && <Button onClick={() => setCreating(true)}>Registrar nueva</Button>}
            </div>

            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                {resolutions.map((r) => (
                    <Card key={r.id} className="space-y-1 p-3">
                        <div className="flex items-start justify-between">
                            <div>
                                <div className="font-mono text-sm">
                                    {r.prefix}
                                    {r.range_from} - {r.prefix}
                                    {r.range_to}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {DOC_TYPES_RESOLUTION.find((t) => t.value === r.document_type)?.label ?? r.document_type}
                                </div>
                            </div>
                            <div className="flex flex-shrink-0 flex-wrap justify-end gap-1">
                                {r.is_active && (
                                    <Badge
                                        variant="outline"
                                        className="border-[color:var(--color-status-success)]/30 bg-[color:var(--color-status-success)]/10 text-[color:var(--color-status-success)]"
                                    >
                                        Activa
                                    </Badge>
                                )}
                                {r.is_expiring_soon && (
                                    <Badge
                                        variant="outline"
                                        className="border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]"
                                    >
                                        Por vencer
                                    </Badge>
                                )}
                                {r.is_exhausted && (
                                    <Badge
                                        variant="outline"
                                        className="border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)]"
                                    >
                                        Agotada
                                    </Badge>
                                )}
                            </div>
                        </div>
                        <div className="text-muted-foreground text-xs">
                            Resolución {r.resolution_number} · {r.environment} · vence {r.valid_until ?? '—'}
                        </div>
                        <div className="text-xs">
                            Consumido: <strong>{r.current_number}</strong> de {r.range_to} (
                            {Math.round((r.current_number / Math.max(1, r.range_to)) * 100)}%)
                        </div>
                        {editable && r.is_active && (
                            <div className="pt-1">
                                <Button variant="ghost" size="sm" onClick={() => setConfirmDeactivateId(r.id)}>
                                    Desactivar
                                </Button>
                            </div>
                        )}
                    </Card>
                ))}
            </div>

            {editable && creating && (
                <Card className="border-primary space-y-3 p-4">
                    <h3 className="font-semibold">Nueva resolución DIAN</h3>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <Label>Tipo de documento</Label>
                            <Select value={form.document_type} onValueChange={(v) => setForm({ ...form, document_type: v as DianDocumentType })}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {DOC_TYPES_RESOLUTION.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Prefijo</Label>
                            <Input value={form.prefix} onChange={(e) => setForm({ ...form, prefix: e.target.value })} maxLength={10} />
                        </div>
                        <div>
                            <Label>Rango desde</Label>
                            <Input
                                type="number"
                                value={form.range_from}
                                onChange={(e) => setForm({ ...form, range_from: parseInt(e.target.value || '1', 10) })}
                            />
                        </div>
                        <div>
                            <Label>Rango hasta</Label>
                            <Input
                                type="number"
                                value={form.range_to}
                                onChange={(e) => setForm({ ...form, range_to: parseInt(e.target.value || '5000', 10) })}
                            />
                        </div>
                        <div>
                            <Label>Número de resolución</Label>
                            <Input value={form.resolution_number} onChange={(e) => setForm({ ...form, resolution_number: e.target.value })} />
                        </div>
                        <div>
                            <Label>Ambiente</Label>
                            <Select
                                value={form.environment}
                                onValueChange={(v) => setForm({ ...form, environment: v as 'habilitacion' | 'produccion' })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="habilitacion">Habilitación</SelectItem>
                                    <SelectItem value="produccion">Producción</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Vigente desde</Label>
                            <Input type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} />
                        </div>
                        <div>
                            <Label>Vigente hasta</Label>
                            <Input type="date" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} />
                        </div>
                        <div className="md:col-span-2">
                            <Label>Clave técnica DIAN</Label>
                            <Input
                                value={form.technical_key}
                                onChange={(e) => setForm({ ...form, technical_key: e.target.value })}
                                placeholder="40 caracteres entregados por DIAN"
                            />
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setCreating(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={handleCreate} disabled={!form.resolution_number || !form.technical_key}>
                            Registrar resolución
                        </Button>
                    </div>
                </Card>
            )}

            <ConfirmDialog
                open={confirmDeactivateId !== null}
                onCancel={() => setConfirmDeactivateId(null)}
                title="Desactivar resolución"
                message="La resolución pasará a histórica. Las emisiones futuras del tipo de documento requerirán otra resolución activa."
                confirmLabel="Desactivar"
                onConfirm={handleDeactivate}
            />
        </div>
    );
}

/* ============== TAB 4: CLIENTE POR DEFECTO ============== */

/**
 * Cliente por defecto DIAN. Es data fija de la DIAN: el adquirente genérico
 * "CONSUMIDOR FINAL" (NIT 222222222222), que se usa cuando un cobro no
 * identifica un contacto fiscal. No es configurable — se muestra de solo
 * lectura para que el usuario sepa a quién se emiten las DEE POS genéricas.
 */
function DefaultRecipientTab() {
    return (
        <Card className="space-y-4 p-4">
            <Alert>
                <AlertTitle>Consumidor final DIAN (estándar)</AlertTitle>
                <AlertDescription>
                    Cuando un cobro no identifica un contacto fiscal, la emisión se hace al adquirente genérico estándar de la DIAN. Es un valor fijo
                    definido por la DIAN y no puede modificarse.
                </AlertDescription>
            </Alert>

            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <Label>Tipo de documento</Label>
                    <Input value="NIT" disabled readOnly />
                </div>
                <div>
                    <Label>Número documento</Label>
                    <Input value="222222222222" disabled readOnly />
                </div>
                <div className="md:col-span-2">
                    <Label>Razón social</Label>
                    <Input value="CONSUMIDOR FINAL" disabled readOnly />
                </div>
            </div>
        </Card>
    );
}
