import { Lock } from 'lucide-react';
import { useEffect, useState } from 'react';

import { DocumentsExplorer } from '@/components/dian/documents-explorer';
import InputError from '@/components/input-error';
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
import { DianApiError, createResolution, deactivateResolution, listResolutions } from '@/lib/dian-api';
import { DIAN_DOC_TYPE_LABELS, type DianDocumentType, type DianResolution } from '@/types/dian';

/**
 * Configuración → Facturación DIAN (HU #235 — pantalla owner-only).
 *
 * 3 tabs: Facturas (principal — consulta de documentos emitidos por
 * resolución, con filtro por sede/empresa, búsqueda, ordenamiento y
 * paginación server-side) · Resoluciones · Contacto por defecto.
 * Cada tab es un componente local pequeño. Reutiliza componentes del DS
 * (PageHeader, Card, Tabs, Alert, SanitizedInput, Select, Skeleton).
 *
 * El tab Proveedor se retiró (2026-07): el proveedor tecnológico es único
 * para toda la plataforma y lo gestiona flexyflow — el cliente no configura
 * credenciales ni ambiente. La config sigue viva en el backend
 * (DianProviderConfig) operada por flexyflow.
 *
 * El perfil fiscal del emisor se edita ahora desde /company/settings →
 * "Información" (componente CompanyFiscalSection), no acá.
 *
 * Por ahora la pantalla es SOLO INFORMATIVA: la facturación DIAN aún no se
 * libera para edición, así que los controles van deshabilitados y las acciones
 * (registrar, desactivar) se ocultan. Cuando se habilite la edición,
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
                conociendo; pronto podrás conectar tu proveedor y registrar tus resoluciones.{' '}
                <strong>
                    La facturación electrónica DIAN hace parte del Plan Plus: $300.000 COP/mes más $10 COP por cada factura electrónica
                    generada.
                </strong>
            </AlertDescription>
        </Alert>
    );
}

export default function DianConfigPage() {
    // El catálogo de resoluciones lo comparten los tabs Facturas (selector) y
    // Resoluciones (cards + alta), así que vive acá y baja por props.
    const [resolutions, setResolutions] = useState<DianResolution[]>([]);
    const [resolutionsLoaded, setResolutionsLoaded] = useState(false);
    const [resolutionsError, setResolutionsError] = useState<string | null>(null);
    const [tab, setTab] = useState('documents');
    const [selectedResolutionId, setSelectedResolutionId] = useState('');

    const fetchResolutions = () => {
        listResolutions()
            .then(({ data }) => setResolutions(data))
            .catch((e) => setResolutionsError(e instanceof Error ? e.message : 'Error'))
            .finally(() => setResolutionsLoaded(true));
    };

    useEffect(fetchResolutions, []);

    // Salto "Consultar facturas" desde una card del tab Resoluciones.
    const consultResolution = (id: string) => {
        setSelectedResolutionId(id);
        setTab('documents');
    };

    return (
        <PageShell title="Facturación DIAN">
            {/* Mismo contenedor que company/settings: sin él, el contenido
                quedaba pegado al sidebar y sin gutter en mobile. */}
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="CONFIGURACIÓN"
                    title="Facturación electrónica DIAN"
                    description="Facturas emitidas, resoluciones autorizadas y adquirente por defecto. La emisión la opera flexyflow con un proveedor único para toda la plataforma."
                    variant="dense"
                    showBranchBadge={false}
                />

                {!DIAN_EDITABLE && <InformationalLockBanner />}

                <Tabs defaultValue="documents" value={tab} onValueChange={setTab} className="w-full">
                    <TabsList className="max-w-full overflow-x-auto">
                        <TabsTrigger value="documents">Facturas</TabsTrigger>
                        <TabsTrigger value="resolutions">Resoluciones</TabsTrigger>
                        <TabsTrigger value="recipient">Contacto por defecto</TabsTrigger>
                    </TabsList>

                    <TabsContent value="documents" className="mt-4">
                        {!resolutionsLoaded ? (
                            <Skeleton className="h-96 w-full" />
                        ) : (
                            <DocumentsExplorer
                                resolutions={resolutions}
                                resolutionId={selectedResolutionId}
                                onResolutionChange={setSelectedResolutionId}
                            />
                        )}
                    </TabsContent>
                    <TabsContent value="resolutions" className="mt-4">
                        <ResolutionsTab
                            editable={DIAN_EDITABLE}
                            resolutions={resolutions}
                            loaded={resolutionsLoaded}
                            loadError={resolutionsError}
                            onRefresh={fetchResolutions}
                            onConsult={consultResolution}
                        />
                    </TabsContent>
                    <TabsContent value="recipient" className="mt-4">
                        <DefaultRecipientTab />
                    </TabsContent>
                </Tabs>
            </div>
        </PageShell>
    );
}

/* ============== TAB 1: RESOLUCIONES ============== */

const DOC_TYPES_RESOLUTION: { value: DianDocumentType; label: string }[] = [
    { value: 'pos_equivalent', label: 'DEE POS (consumidor final)' },
    { value: 'invoice', label: 'Factura electrónica (FEV)' },
    { value: 'credit_note', label: 'Nota crédito FEV' },
    { value: 'debit_note', label: 'Nota débito FEV' },
];

interface ResolutionsTabProps {
    editable: boolean;
    resolutions: DianResolution[];
    loaded: boolean;
    loadError: string | null;
    onRefresh: () => void;
    /** Abre el tab Facturas con esta resolución preseleccionada. */
    onConsult: (id: string) => void;
}

function ResolutionsTab({ editable, resolutions, loaded, loadError, onRefresh, onConsult }: ResolutionsTabProps) {
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
    // Errores 422 por campo del alta de resolución → inline bajo cada input.
    const [createErrors, setCreateErrors] = useState<Record<string, string>>({});

    const handleCreate = async () => {
        setError(null);
        setCreateErrors({});
        try {
            await createResolution({
                ...form,
                is_active: true,
            } as Partial<DianResolution> & { technical_key: string });
            setCreating(false);
            onRefresh();
        } catch (e) {
            // 422 con errores por campo → inline bajo cada input; si no, mensaje general.
            if (e instanceof DianApiError && e.errors) {
                const mapped: Record<string, string> = {};
                for (const [field, messages] of Object.entries(e.errors)) {
                    mapped[field] = messages[0] ?? '';
                }
                setCreateErrors(mapped);
            } else {
                setError(e instanceof Error ? e.message : 'Error al crear');
            }
        }
    };

    const handleDeactivate = async () => {
        if (confirmDeactivateId === null) return;
        try {
            await deactivateResolution(confirmDeactivateId);
            setConfirmDeactivateId(null);
            onRefresh();
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

            {(error ?? loadError) && (
                <Alert variant="destructive">
                    <AlertDescription>{error ?? loadError}</AlertDescription>
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
                                    {DOC_TYPES_RESOLUTION.find((t) => t.value === r.document_type)?.label ?? DIAN_DOC_TYPE_LABELS[r.document_type] ?? r.document_type}
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
                        <div className="flex flex-wrap gap-1 pt-1">
                            <Button variant="outline" size="sm" onClick={() => onConsult(r.id)}>
                                Consultar facturas
                            </Button>
                            {editable && r.is_active && (
                                <Button variant="ghost" size="sm" onClick={() => setConfirmDeactivateId(r.id)}>
                                    Desactivar
                                </Button>
                            )}
                        </div>
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
                            <Input
                                value={form.prefix}
                                onChange={(e) => setForm({ ...form, prefix: e.target.value })}
                                maxLength={10}
                                aria-invalid={!!createErrors.prefix}
                            />
                            <InputError message={createErrors.prefix} className="text-xs" />
                        </div>
                        <div>
                            <Label>Rango desde</Label>
                            <Input
                                type="number"
                                value={form.range_from}
                                onChange={(e) => setForm({ ...form, range_from: parseInt(e.target.value || '1', 10) })}
                                aria-invalid={!!createErrors.range_from}
                            />
                            <InputError message={createErrors.range_from} className="text-xs" />
                        </div>
                        <div>
                            <Label>Rango hasta</Label>
                            <Input
                                type="number"
                                value={form.range_to}
                                onChange={(e) => setForm({ ...form, range_to: parseInt(e.target.value || '5000', 10) })}
                                aria-invalid={!!createErrors.range_to}
                            />
                            <InputError message={createErrors.range_to} className="text-xs" />
                        </div>
                        <div>
                            <Label>Número de resolución</Label>
                            <Input
                                value={form.resolution_number}
                                onChange={(e) => setForm({ ...form, resolution_number: e.target.value })}
                                aria-invalid={!!createErrors.resolution_number}
                            />
                            <InputError message={createErrors.resolution_number} className="text-xs" />
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
                            <Input
                                type="date"
                                value={form.valid_from}
                                onChange={(e) => setForm({ ...form, valid_from: e.target.value })}
                                aria-invalid={!!createErrors.valid_from}
                            />
                            <InputError message={createErrors.valid_from} className="text-xs" />
                        </div>
                        <div>
                            <Label>Vigente hasta</Label>
                            <Input
                                type="date"
                                value={form.valid_until}
                                onChange={(e) => setForm({ ...form, valid_until: e.target.value })}
                                aria-invalid={!!createErrors.valid_until}
                            />
                            <InputError message={createErrors.valid_until} className="text-xs" />
                        </div>
                        <div className="md:col-span-2">
                            <Label>Clave técnica DIAN</Label>
                            <Input
                                value={form.technical_key}
                                onChange={(e) => setForm({ ...form, technical_key: e.target.value })}
                                placeholder="40 caracteres entregados por DIAN"
                                aria-invalid={!!createErrors.technical_key}
                            />
                            <InputError message={createErrors.technical_key} className="text-xs" />
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

/* ============== TAB 2: CLIENTE POR DEFECTO ============== */

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
