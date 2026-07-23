import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ReasonTooltip } from '@/components/ui/field-hint';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { AUTOMATION_EVENTS, useAutomationFlows, type AutomationFlow, type FlowDelivery, type TestResult } from '@/hooks/use-automation-flows';
import type { Branch } from '@/types';

import { AlertTriangle, Bot, Copy, KeyRound, Plus, RefreshCw, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

/** JSON de ejemplo del webhook saliente (§9.2), para el modal "Ver ejemplo". */
const PAYLOAD_EXAMPLE = `{
  "event": "chat.message.received",
  "sent_at": "2026-07-22T14:03:11-05:00",
  "company_nit": "900123456-7",
  "branch_id": "uuid",
  "channel": { "id": "uuid", "label": "Sede Norte", "phone_e164": "+57310..." },
  "chat":    { "id": "uuid", "client_phone": "+57300...", "client_name": "Ana", "bot_paused": false },
  "message": { "id": "uuid", "sender": "client", "body": "hola, tienen domicilio?",
               "media_type": null, "sent_at": "2026-07-22T14:03:10-05:00" }
}`;

interface RevealState {
    title: string;
    token?: string;
    secret?: string;
}

/**
 * Sección "Automatización" de la pantalla de WhatsApp (§9.5).
 *
 * Configura los flujos de n8n por (empresa, sede): URL del webhook, eventos,
 * token del bot (mostrado una sola vez, patrón PAT) y secreto de firma. n8n no
 * es obligatorio — sin flujo, la bandeja de operadores resuelve todo (§5.6), así
 * que el estado vacío NO se presenta como un paso pendiente del onboarding.
 */
export function AutomationSection({
    token,
    branches,
    canManage,
    available,
}: {
    token: string | null;
    branches: Branch[];
    canManage: boolean;
    /** n8n desplegado y utilizable. Mientras sea false, la sección se muestra
     *  deshabilitada con la razón (no oculta): la capacidad existe pero no hay a
     *  dónde apuntar el flujo todavía. */
    available: boolean;
}) {
    const { flows, loading, error, create, update, rotateToken, rotateSecret, test, deliveries, remove } = useAutomationFlows(token);
    const { showToast } = useToast();

    // Poder operar = tener el permiso Y que la automatización esté disponible.
    const manageable = canManage && available;
    const unavailableReason = available ? null : 'La automatización con n8n estará disponible próximamente.';

    const [formOpen, setFormOpen] = useState(false);
    const [url, setUrl] = useState('');
    const [branchId, setBranchId] = useState('');
    const [events, setEvents] = useState<string[]>(AUTOMATION_EVENTS.map((e) => e.value));
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    const [reveal, setReveal] = useState<RevealState | null>(null);
    const [pendingDelete, setPendingDelete] = useState<AutomationFlow | null>(null);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [testResult, setTestResult] = useState<Record<string, TestResult>>({});
    const [deliveryList, setDeliveryList] = useState<Record<string, FlowDelivery[]>>({});
    const [payloadOpen, setPayloadOpen] = useState(false);

    const openNew = () => {
        setUrl('');
        setBranchId('');
        setEvents(AUTOMATION_EVENTS.map((e) => e.value));
        setFormError(null);
        setFormOpen(true);
    };

    const toggleEvent = (value: string) => {
        setEvents((prev) => (prev.includes(value) ? prev.filter((e) => e !== value) : [...prev, value]));
    };

    const copy = async (value: string, label: string) => {
        try {
            await navigator.clipboard.writeText(value);
            showToast('success', `${label} copiado`);
        } catch {
            showToast('error', 'No se pudo copiar');
        }
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (saving) return;
        setSaving(true);
        setFormError(null);
        try {
            const created = await create({ branch_id: branchId || null, url: url.trim(), events, enabled: true });
            setFormOpen(false);
            setReveal({
                title: 'Flujo creado',
                token: created.token,
                secret: created.secret,
            });
        } catch (err) {
            setFormError(err instanceof Error ? err.message : 'No se pudo crear el flujo.');
        } finally {
            setSaving(false);
        }
    };

    const run = async (id: string, fn: () => Promise<void>) => {
        setBusyId(id);
        try {
            await fn();
        } catch (err) {
            showToast('error', err instanceof Error ? err.message : 'No se pudo completar la operación.');
        } finally {
            setBusyId(null);
        }
    };

    const doRotateToken = (flow: AutomationFlow) =>
        run(flow.id, async () => {
            const t = await rotateToken(flow.id);
            setReveal({ title: 'Token rotado', token: t });
        });

    const doRotateSecret = (flow: AutomationFlow) =>
        run(flow.id, async () => {
            const s = await rotateSecret(flow.id);
            setReveal({ title: 'Secreto rotado', secret: s });
        });

    const doTest = (flow: AutomationFlow) =>
        run(flow.id, async () => {
            const result = await test(flow.id);
            setTestResult((prev) => ({ ...prev, [flow.id]: result }));
        });

    const doDeliveries = (flow: AutomationFlow) =>
        run(flow.id, async () => {
            const list = await deliveries(flow.id);
            setDeliveryList((prev) => ({ ...prev, [flow.id]: list }));
        });

    const doToggleEnabled = (flow: AutomationFlow) =>
        run(flow.id, async () => {
            await update(flow.id, { enabled: !flow.enabled });
        });

    const confirmDelete = async () => {
        if (!pendingDelete) return;
        try {
            await remove(pendingDelete.id);
            showToast('success', 'Flujo eliminado');
        } catch {
            showToast('error', 'No se pudo eliminar');
        } finally {
            setPendingDelete(null);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h2 className="text-lg font-semibold tracking-tight">Automatización</h2>
                        {!available && (
                            <Badge variant="secondary" className="shrink-0">
                                Próximamente
                            </Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Conectá un flujo (n8n) que responda solo. Es opcional: sin flujo, tus conversaciones las atienden tus operadores.{' '}
                        <button type="button" className="text-primary underline underline-offset-2" onClick={() => setPayloadOpen(true)}>
                            Ver ejemplo de payload
                        </button>
                    </p>
                </div>
                {canManage && (
                    <ReasonTooltip reason={unavailableReason}>
                        <Button size="sm" onClick={openNew} className="shrink-0" disabled={!available}>
                            <Plus className="mr-2 h-4 w-4" />
                            Agregar flujo
                        </Button>
                    </ReasonTooltip>
                )}
            </div>

            {error && (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {loading ? (
                <div className="space-y-2">
                    <Skeleton className="h-24 w-full" />
                    <Skeleton className="h-24 w-full" />
                </div>
            ) : flows.length === 0 ? (
                <div className="text-muted-foreground border-border rounded-lg border border-dashed p-6 text-center text-sm">
                    <Bot className="text-muted-foreground/60 mx-auto mb-2 h-6 w-6" />
                    {available
                        ? 'Tus conversaciones las atienden tus operadores. Si querés automatizar respuestas, configurá un flujo.'
                        : 'Tus conversaciones las atienden tus operadores. La automatización con n8n estará disponible próximamente.'}
                </div>
            ) : (
                <div className="space-y-3">
                    {flows.map((flow) => (
                        <div key={flow.id} className="border-border space-y-3 rounded-lg border p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline" className="shrink-0">
                                            {flow.branch_name ?? 'Empresa'}
                                        </Badge>
                                        <Badge variant={flow.enabled ? 'default' : 'secondary'} className="shrink-0">
                                            {flow.enabled ? 'Activo' : 'Pausado'}
                                        </Badge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 truncate font-mono text-xs" title={flow.url}>
                                        {flow.url}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {flow.has_token ? (
                                            <>
                                                Token <code className="bg-muted rounded px-1">ffw_…{flow.token_last4}</code>
                                            </>
                                        ) : (
                                            'Sin token'
                                        )}
                                        {flow.last_delivery_at && <> · última entrega {new Date(flow.last_delivery_at).toLocaleString('es-CO')}</>}
                                    </p>
                                </div>
                                {manageable && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-destructive shrink-0"
                                        onClick={() => setPendingDelete(flow)}
                                        aria-label="Eliminar flujo"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>

                            <div className="flex flex-wrap gap-1.5">
                                {AUTOMATION_EVENTS.filter((e) => flow.events.includes(e.value)).map((e) => (
                                    <Badge key={e.value} variant="secondary" className="text-[10px]">
                                        {e.label}
                                    </Badge>
                                ))}
                            </div>

                            {testResult[flow.id] && (
                                <p className={`text-xs ${testResult[flow.id].ok ? 'text-[var(--color-status-success)]' : 'text-destructive'}`}>
                                    Prueba: HTTP {testResult[flow.id].http_status || '—'} · {testResult[flow.id].latency_ms} ms
                                    {testResult[flow.id].error ? ` · ${testResult[flow.id].error}` : ''}
                                </p>
                            )}

                            {deliveryList[flow.id] && (
                                <div className="border-border overflow-x-auto rounded-md border">
                                    <table className="w-full text-left text-xs">
                                        <thead className="text-muted-foreground bg-muted/50">
                                            <tr>
                                                <th className="px-2 py-1 font-medium">Evento</th>
                                                <th className="px-2 py-1 font-medium">HTTP</th>
                                                <th className="px-2 py-1 font-medium">ms</th>
                                                <th className="px-2 py-1 font-medium">Intento</th>
                                                <th className="px-2 py-1 font-medium">Hora</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {deliveryList[flow.id].length === 0 ? (
                                                <tr>
                                                    <td colSpan={5} className="text-muted-foreground px-2 py-2 text-center">
                                                        Sin entregas todavía.
                                                    </td>
                                                </tr>
                                            ) : (
                                                deliveryList[flow.id].map((d, i) => (
                                                    <tr key={i} className="border-border border-t">
                                                        <td className="px-2 py-1">{d.event}</td>
                                                        <td className="px-2 py-1">{d.http_status ?? '—'}</td>
                                                        <td className="px-2 py-1">{d.latency_ms ?? '—'}</td>
                                                        <td className="px-2 py-1">{d.attempt ?? '—'}</td>
                                                        <td className="px-2 py-1">{d.at ? new Date(d.at).toLocaleString('es-CO') : '—'}</td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {manageable && (
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => void doToggleEnabled(flow)} disabled={busyId === flow.id}>
                                        {flow.enabled ? 'Pausar' : 'Activar'}
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => void doTest(flow)} disabled={busyId === flow.id}>
                                        <Send className="mr-1.5 h-3.5 w-3.5" />
                                        Enviar evento de prueba
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => void doDeliveries(flow)} disabled={busyId === flow.id}>
                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                        Ver entregas
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => void doRotateToken(flow)} disabled={busyId === flow.id}>
                                        <KeyRound className="mr-1.5 h-3.5 w-3.5" />
                                        Rotar token
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => void doRotateSecret(flow)} disabled={busyId === flow.id}>
                                        Rotar secreto
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* Alta de flujo */}
            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Nuevo flujo de automatización</DialogTitle>
                        <DialogDescription>El token y el secreto se muestran una sola vez al crear el flujo.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3" noValidate>
                        <div className="space-y-1">
                            <Label htmlFor="af-scope">Alcance</Label>
                            <select
                                id="af-scope"
                                value={branchId}
                                onChange={(e) => setBranchId(e.target.value)}
                                className="border-input bg-background focus:border-ring w-full rounded-lg border px-3 py-2 text-sm focus:outline-none"
                            >
                                <option value="">Toda la empresa</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="af-url">URL del webhook (n8n)</Label>
                            <Input
                                id="af-url"
                                type="url"
                                value={url}
                                onChange={(e) => setUrl(e.target.value)}
                                placeholder="https://n8n.tu-dominio.com/webhook/bistro-whatsapp"
                            />
                            <p className="text-muted-foreground text-xs">Debe ser https:// — el webhook lleva contenido de conversaciones.</p>
                        </div>
                        <div className="space-y-2">
                            <Label>Eventos</Label>
                            {AUTOMATION_EVENTS.map((ev) => (
                                <label key={ev.value} className="flex cursor-pointer items-start gap-2">
                                    <Checkbox checked={events.includes(ev.value)} onCheckedChange={() => toggleEvent(ev.value)} className="mt-0.5" />
                                    <span className="text-sm">
                                        {ev.label}
                                        <span className="text-muted-foreground block text-xs">{ev.description}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {formError && <p className="text-destructive text-xs">{formError}</p>}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setFormOpen(false)} disabled={saving}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={saving || !url.trim() || events.length === 0}>
                                {saving ? 'Creando…' : 'Crear flujo'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Revelado una-sola-vez de token/secreto */}
            <Dialog open={reveal !== null} onOpenChange={(open) => !open && setReveal(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{reveal?.title}</DialogTitle>
                        <DialogDescription>Guardalo ahora: no vamos a poder mostrarlo de nuevo.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {reveal?.token && (
                            <div className="space-y-1">
                                <Label>Token del bot (Authorization: Bearer)</Label>
                                <div className="flex gap-2">
                                    <Input readOnly value={reveal.token} className="font-mono text-xs" />
                                    <Button type="button" variant="outline" size="icon" onClick={() => void copy(reveal.token!, 'Token')} aria-label="Copiar token">
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        )}
                        {reveal?.secret && (
                            <div className="space-y-1">
                                <Label>Secreto de firma (HMAC del webhook)</Label>
                                <div className="flex gap-2">
                                    <Input readOnly value={reveal.secret} className="font-mono text-xs" />
                                    <Button type="button" variant="outline" size="icon" onClick={() => void copy(reveal.secret!, 'Secreto')} aria-label="Copiar secreto">
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" onClick={() => setReveal(null)}>
                            Ya lo guardé
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Ejemplo de payload */}
            <Dialog open={payloadOpen} onOpenChange={setPayloadOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Ejemplo de payload</DialogTitle>
                        <DialogDescription>Lo que bistro envía a tu webhook, firmado con HMAC-SHA256 en el header X-Flexyflow-Signature.</DialogDescription>
                    </DialogHeader>
                    <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">{PAYLOAD_EXAMPLE}</pre>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => void copy(PAYLOAD_EXAMPLE, 'Ejemplo')}>
                            <Copy className="mr-1.5 h-4 w-4" />
                            Copiar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={pendingDelete !== null}
                title="Eliminar flujo de automatización"
                message="El flujo de n8n dejará de recibir eventos y su token quedará inválido. Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                onConfirm={() => void confirmDelete()}
                onCancel={() => setPendingDelete(null)}
            />
        </div>
    );
}
