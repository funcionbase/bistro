import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { FieldHint } from '@/components/ui/field-hint';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, ArchiveRestore, ChefHat, Clipboard, Pencil, Plus, ShieldOff, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Station {
    id: string;
    slug: string;
    name: string;
    color: string;
    sla_warn_minutes: number;
    sla_alert_minutes: number;
    is_default: boolean;
    archived_at: string | null;
}

interface DeviceToken {
    id: string;
    label: string | null;
    last_seen_at: string | null;
    last_ip: string | null;
    revoked_at: string | null;
    created_at: string | null;
}

interface StationFormState {
    id?: string;
    slug: string;
    name: string;
    color: string;
    sla_warn_minutes: number;
    sla_alert_minutes: number;
    is_default: boolean;
}

const EMPTY_STATION_FORM: StationFormState = {
    slug: '',
    name: '',
    color: '#64748B',
    sla_warn_minutes: 8,
    sla_alert_minutes: 15,
    is_default: false,
};


/**
 * F6 — Configuración del KDS por sede: CRUD de estaciones y gestión
 * de device-tokens.
 *
 * Mobile-first: listado en `Card` apiladas; copia el patrón de
 * `/company/printers` (card layout sirve igual en mobile y desktop, sin
 * doble render). Diálogos usan `Dialog` del DS — en mobile se ven
 * full-width.
 */
export default function CompanyKdsPage() {
    const { activeCompany } = useSharedData();
    const token = useToken();
    const { showToast } = useToast();
    const [stations, setStations] = useState<Station[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const [stationModalOpen, setStationModalOpen] = useState(false);
    const [stationForm, setStationForm] = useState<StationFormState>(EMPTY_STATION_FORM);
    const [savingStation, setSavingStation] = useState(false);
    const [stationFormError, setStationFormError] = useState<string | null>(null);
    // Errores 422 por campo → inline bajo cada input. `stationFormError` queda
    // para errores no atribuibles a un campo.
    const [stationFieldErrors, setStationFieldErrors] = useState<Record<string, string>>({});
    const [confirmArchive, setConfirmArchive] = useState<Station | null>(null);

    const [tokensModalStation, setTokensModalStation] = useState<Station | null>(null);
    const [tokens, setTokens] = useState<DeviceToken[]>([]);
    const [tokensLoading, setTokensLoading] = useState(false);
    const [tokenLabel, setTokenLabel] = useState('');
    const [generatingToken, setGeneratingToken] = useState(false);
    const [revealedToken, setRevealedToken] = useState<{ token: string; launchUrl: string; label: string | null } | null>(null);
    const [confirmRevokeToken, setConfirmRevokeToken] = useState<DeviceToken | null>(null);

    useEffect(() => {
        if (!token) {
            setLoading(false);
            return;
        }
        void loadStations();
    }, [token]);

    async function loadStations() {
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/kds/stations');
            if (!res.ok) throw new Error('No pudimos cargar las estaciones.');
            const json = (await res.json()) as { data: Station[] };
            setStations(json.data ?? []);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error desconocido.');
        } finally {
            setLoading(false);
        }
    }

    function openCreateStation() {
        setStationForm(EMPTY_STATION_FORM);
        setStationFormError(null);
        setStationFieldErrors({});
        setStationModalOpen(true);
    }

    function openEditStation(station: Station) {
        setStationForm({
            id: station.id,
            slug: station.slug,
            name: station.name,
            color: station.color,
            sla_warn_minutes: station.sla_warn_minutes,
            sla_alert_minutes: station.sla_alert_minutes,
            is_default: station.is_default,
        });
        setStationFormError(null);
        setStationFieldErrors({});
        setStationModalOpen(true);
    }

    async function submitStation(e: React.FormEvent) {
        e.preventDefault();
        setStationFormError(null);
        setStationFieldErrors({});
        setSavingStation(true);
        try {
            const isEditing = stationForm.id !== undefined;
            const url = isEditing ? `/api/v1/company/kds/stations/${stationForm.id}` : '/api/v1/company/kds/stations';
            const res = await apiFetch(url, {
                method: isEditing ? 'PATCH' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...(isEditing ? {} : { slug: stationForm.slug }),
                    name: stationForm.name,
                    color: stationForm.color,
                    sla_warn_minutes: stationForm.sla_warn_minutes,
                    sla_alert_minutes: stationForm.sla_alert_minutes,
                    is_default: stationForm.is_default,
                }),
            });
            const body = await res.json();
            if (!res.ok) {
                // 422: errores por campo → inline bajo cada input.
                if (res.status === 422 && body.errors) {
                    const mapped: Record<string, string> = {};
                    for (const [field, messages] of Object.entries(body.errors as Record<string, string[]>)) {
                        mapped[field] = messages[0] ?? '';
                    }
                    setStationFieldErrors(mapped);
                    return;
                }
                throw new Error(body.message ?? 'No se pudo guardar.');
            }
            await loadStations();
            setStationModalOpen(false);
            showToast('success', isEditing ? 'Estación actualizada.' : 'Estación creada.');
        } catch (err) {
            setStationFormError(err instanceof Error ? err.message : 'Error de conexión.');
        } finally {
            setSavingStation(false);
        }
    }

    async function archiveStation(station: Station) {
        try {
            const res = await apiFetch(`/api/v1/company/kds/stations/${station.id}/archive`, { method: 'POST' });
            if (!res.ok) throw new Error('No se pudo archivar.');
            await loadStations();
            showToast('success', 'Estación archivada.');
        } catch (err) {
            showToast('error', err instanceof Error ? err.message : 'Error.');
        } finally {
            setConfirmArchive(null);
        }
    }

    async function openTokensModal(station: Station) {
        setTokensModalStation(station);
        setTokenLabel('');
        setRevealedToken(null);
        await loadTokens(station.id);
    }

    async function loadTokens(stationId: string) {
        setTokensLoading(true);
        try {
            const res = await apiFetch(`/api/v1/company/kds/stations/${stationId}/tokens`);
            if (!res.ok) throw new Error('No pudimos cargar los tokens.');
            const json = (await res.json()) as { data: DeviceToken[] };
            setTokens(json.data ?? []);
        } catch (err) {
            showToast('error', err instanceof Error ? err.message : 'Error.');
        } finally {
            setTokensLoading(false);
        }
    }

    async function generateToken() {
        if (!tokensModalStation) return;
        setGeneratingToken(true);
        try {
            const res = await apiFetch(`/api/v1/company/kds/stations/${tokensModalStation.id}/tokens`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ label: tokenLabel || null }),
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body.message ?? 'No se pudo generar.');
            setRevealedToken({
                token: body.token,
                launchUrl: body.launch_url,
                label: body.data?.label ?? null,
            });
            setTokenLabel('');
            await loadTokens(tokensModalStation.id);
        } catch (err) {
            showToast('error', err instanceof Error ? err.message : 'Error.');
        } finally {
            setGeneratingToken(false);
        }
    }

    async function revokeToken(tokenItem: DeviceToken) {
        if (!tokensModalStation) return;
        try {
            const res = await apiFetch(`/api/v1/company/kds/stations/${tokensModalStation.id}/tokens/${tokenItem.id}`, { method: 'DELETE' });
            if (!res.ok) throw new Error('No se pudo revocar.');
            await loadTokens(tokensModalStation.id);
            showToast('success', 'Token revocado.');
        } catch (err) {
            showToast('error', err instanceof Error ? err.message : 'Error.');
        } finally {
            setConfirmRevokeToken(null);
        }
    }

    function copyToClipboard(text: string, label: string) {
        if (navigator.clipboard?.writeText) {
            void navigator.clipboard
                .writeText(text)
                .then(() => showToast('success', `${label} copiado al portapapeles.`))
                .catch(() => showToast('error', 'No se pudo copiar.'));
        }
    }

    const activeStations = useMemo(() => stations.filter((s) => s.archived_at === null), [stations]);
    const archivedStations = useMemo(() => stations.filter((s) => s.archived_at !== null), [stations]);

    return (
        <PageShell title="KDS / Cocina">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="KDS / Cocina"
                    description={
                        activeCompany?.name
                            ? `Estaciones de cocina y dispositivos de ${activeCompany.name}.`
                            : 'Estaciones de cocina y dispositivos por sede.'
                    }
                    actions={
                        <Button onClick={openCreateStation} className="w-full sm:w-auto">
                            <Plus className="mr-1.5 h-4 w-4" /> Nueva estación
                        </Button>
                    }
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <ListCardSkeleton rows={3} />
                ) : activeStations.length === 0 ? (
                    <EmptyState icon={ChefHat} title="Sin estaciones" description="Crea una estación para enviarles tickets desde el menú." />
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {activeStations.map((station) => (
                            <Card key={station.id} className="flex flex-col">
                                <CardContent className="flex flex-1 flex-col gap-3 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span
                                                aria-hidden
                                                className="inline-block h-3 w-3 shrink-0 rounded-full"
                                                style={{ backgroundColor: station.color }}
                                            />
                                            <div className="min-w-0">
                                                <p className="text-foreground truncate text-base font-semibold">{station.name}</p>
                                                <p className="text-muted-foreground truncate text-xs">{station.slug}</p>
                                            </div>
                                        </div>
                                        {station.is_default && (
                                            <Badge variant="secondary" className="shrink-0 text-[10px] uppercase">
                                                Default
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        SLA: {station.sla_warn_minutes} / {station.sla_alert_minutes} min
                                    </p>
                                    <div className="mt-auto flex flex-wrap gap-1.5 pt-2">
                                        <Button type="button" size="sm" variant="outline" onClick={() => openEditStation(station)}>
                                            <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => void openTokensModal(station)}>
                                            <Clipboard className="mr-1 h-3.5 w-3.5" /> Tokens
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setConfirmArchive(station)}
                                            disabled={station.is_default}
                                            title={station.is_default ? 'No se puede archivar la estación default.' : undefined}
                                        >
                                            <Trash2 className="mr-1 h-3.5 w-3.5" /> Archivar
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {archivedStations.length > 0 && (
                    <details className="mt-2">
                        <summary className="text-muted-foreground cursor-pointer text-sm">Estaciones archivadas ({archivedStations.length})</summary>
                        <ul className="border-border mt-2 divide-y rounded-md border">
                            {archivedStations.map((s) => (
                                <li key={s.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                    <ArchiveRestore className="text-muted-foreground h-4 w-4" />
                                    <span className="flex-1">
                                        {s.name} <span className="text-muted-foreground">({s.slug})</span>
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {s.archived_at ? new Date(s.archived_at).toLocaleDateString() : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>

            <Dialog open={stationModalOpen} onOpenChange={(o) => !o && setStationModalOpen(false)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{stationForm.id ? 'Editar estación' : 'Nueva estación'}</DialogTitle>
                        <DialogDescription>Define el slug, los umbrales SLA y el color identitario.</DialogDescription>
                    </DialogHeader>
                    <form noValidate onSubmit={submitStation} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="kds-name">Nombre *</Label>
                            <Input
                                id="kds-name"
                                value={stationForm.name}
                                maxLength={64}
                                onChange={(e) => setStationForm({ ...stationForm, name: e.target.value })}
                                placeholder="Caliente"
                                required
                                aria-invalid={!!stationFieldErrors.name}
                            />
                            <InputError message={stationFieldErrors.name} className="text-xs" />
                        </div>
                        {!stationForm.id && (
                            <div className="space-y-1.5">
                                <Label htmlFor="kds-slug">Slug *</Label>
                                <Input
                                    id="kds-slug"
                                    value={stationForm.slug}
                                    maxLength={64}
                                    onChange={(e) => setStationForm({ ...stationForm, slug: e.target.value })}
                                    placeholder="caliente"
                                    pattern="[a-z0-9][a-z0-9_-]*"
                                    required
                                    aria-invalid={!!stationFieldErrors.slug}
                                />
                                {stationFieldErrors.slug ? (
                                    <InputError message={stationFieldErrors.slug} className="text-xs" />
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        Sólo minúsculas, números, guion y guion bajo. No cambia después de creado.
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="kds-warn" className="flex items-center gap-1.5">
                                    SLA aviso (min)
                                    <FieldHint text="Minutos que puede estar un ticket en la estación antes de marcarse en ámbar (va con retraso)." />
                                </Label>
                                <Input
                                    id="kds-warn"
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={stationForm.sla_warn_minutes}
                                    onChange={(e) => setStationForm({ ...stationForm, sla_warn_minutes: parseInt(e.target.value, 10) || 0 })}
                                    required
                                    aria-invalid={!!stationFieldErrors.sla_warn_minutes}
                                />
                                <InputError message={stationFieldErrors.sla_warn_minutes} className="text-xs" />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="kds-alert" className="flex items-center gap-1.5">
                                    SLA alerta (min)
                                    <FieldHint text="Minutos tras los cuales el ticket pasa a rojo (crítico). Debe ser mayor que el SLA de aviso." />
                                </Label>
                                <Input
                                    id="kds-alert"
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={stationForm.sla_alert_minutes}
                                    onChange={(e) => setStationForm({ ...stationForm, sla_alert_minutes: parseInt(e.target.value, 10) || 0 })}
                                    required
                                    aria-invalid={!!stationFieldErrors.sla_alert_minutes}
                                />
                                <InputError message={stationFieldErrors.sla_alert_minutes} className="text-xs" />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="kds-color">Color</Label>
                            <Input
                                id="kds-color"
                                type="color"
                                value={stationForm.color}
                                onChange={(e) => setStationForm({ ...stationForm, color: e.target.value })}
                                className="h-10 w-20 p-1"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={stationForm.is_default}
                                onChange={(e) => setStationForm({ ...stationForm, is_default: e.target.checked })}
                            />
                            <span>Estación default (fallback para categorías sin mapeo)</span>
                        </label>
                        {stationFormError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{stationFormError}</AlertDescription>
                            </Alert>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setStationModalOpen(false)} disabled={savingStation}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={savingStation}>
                                {savingStation ? 'Guardando…' : stationForm.id ? 'Guardar cambios' : 'Crear estación'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={tokensModalStation !== null} onOpenChange={(o) => !o && setTokensModalStation(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Dispositivos de {tokensModalStation?.name}</DialogTitle>
                        <DialogDescription>
                            Cada tableta de cocina necesita un token único. El token completo se muestra UNA sola vez.
                        </DialogDescription>
                    </DialogHeader>

                    {revealedToken && (
                        <Alert>
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription className="space-y-2">
                                <p className="font-semibold">Token generado. Copialo ahora — no se va a volver a mostrar.</p>
                                <code className="bg-muted block rounded p-2 text-xs break-all">{revealedToken.token}</code>
                                <div className="flex flex-wrap gap-1.5">
                                    <Button type="button" size="sm" variant="outline" onClick={() => copyToClipboard(revealedToken.token, 'Token')}>
                                        <Clipboard className="mr-1 h-3.5 w-3.5" /> Copiar token
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" onClick={() => copyToClipboard(revealedToken.launchUrl, 'URL')}>
                                        <Clipboard className="mr-1 h-3.5 w-3.5" /> Copiar URL de inicio
                                    </Button>
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Abre la URL de inicio en la tableta una sola vez; el token quedará guardado como cookie.
                                </p>
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                value={tokenLabel}
                                maxLength={64}
                                onChange={(e) => setTokenLabel(e.target.value)}
                                placeholder="Etiqueta (ej. Pereira Tablet 1)"
                                disabled={generatingToken}
                            />
                            <Button onClick={() => void generateToken()} disabled={generatingToken}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                {generatingToken ? 'Generando…' : 'Generar token'}
                            </Button>
                        </div>

                        {tokensLoading ? (
                            <ListCardSkeleton rows={2} />
                        ) : tokens.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No hay tokens para esta estación todavía.</p>
                        ) : (
                            <ul className="border-border divide-y rounded-md border">
                                {tokens.map((t) => (
                                    <li key={t.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-foreground truncate font-medium">{t.label ?? `Token #${t.id}`}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {t.last_seen_at ? `Último uso: ${new Date(t.last_seen_at).toLocaleString()}` : 'Nunca usado'}
                                                {t.last_ip && ` · ${t.last_ip}`}
                                            </p>
                                        </div>
                                        {t.revoked_at ? (
                                            <Badge variant="secondary" className="text-[10px] uppercase">
                                                Revocado
                                            </Badge>
                                        ) : (
                                            <Button type="button" size="sm" variant="ghost" onClick={() => setConfirmRevokeToken(t)}>
                                                <ShieldOff className="mr-1 h-3.5 w-3.5" /> Revocar
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmArchive !== null}
                title={`Archivar estación "${confirmArchive?.name ?? ''}"`}
                message="Los tickets en curso siguen visibles hasta marcarse listos. Los nuevos items con categorías mapeadas a esta estación caerán al fallback default."
                confirmLabel="Archivar"
                onConfirm={() => {
                    if (confirmArchive) {
                        void archiveStation(confirmArchive);
                    }
                }}
                onCancel={() => setConfirmArchive(null)}
            />

            <ConfirmDialog
                open={confirmRevokeToken !== null}
                title="Revocar token de dispositivo"
                message="El dispositivo perderá acceso inmediatamente. Si lo quieres volver a usar, hay que generar otro token."
                confirmLabel="Revocar"
                onConfirm={() => {
                    if (confirmRevokeToken) {
                        void revokeToken(confirmRevokeToken);
                    }
                }}
                onCancel={() => setConfirmRevokeToken(null)}
            />
        </PageShell>
    );
}
