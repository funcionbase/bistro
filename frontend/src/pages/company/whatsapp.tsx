import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { WhatsappPageSkeleton } from '@/components/ui/whatsapp-page-skeleton';
import { AutomationSection } from '@/components/whatsapp/automation-section';
import { BranchPlaceholderCard, ChannelCard } from '@/components/whatsapp/channel-card';
import { ConnectWizard } from '@/components/whatsapp/connect-wizard';
import { QuickRepliesManager } from '@/components/whatsapp/quick-replies-manager';
import WhatsappVerificationCodeModal from '@/components/whatsapp/whatsapp-verification-code-modal';
import { useToken } from '@/hooks/use-token';
import { useWhatsappChannels, type WhatsappChannel } from '@/hooks/use-whatsapp-channels';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { type CompanySettings } from '@/types';

import { AlertTriangle, Bot, CheckCheck, MessageCircle, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

/**
 * Empresa → WhatsApp: lista de canales (§8.2).
 *
 * Este rediseño saca de la pantalla todo lo que era de Meta Cloud API — el SDK
 * de Facebook, el botón de Embedded Signup, el bloque NaaS y los campos
 * `quality_rating` / `messaging_tier` / `display_name_status` / verificación de
 * negocio. **El backend de Meta sigue vivo**: `WhatsappAccountController` no se
 * tocó y los clientes que todavía no migraron siguen funcionando hasta F4.
 *
 * El bloque «Preferencias» se conserva: el doble chulito y los mensajes del bot
 * no eran conceptos de Meta y los sigue usando Evolution.
 */
/**
 * n8n aún no está desplegado (F6/§9 es a futuro). La sección Automatización se
 * muestra deshabilitada con la razón —no oculta— para que la capacidad sea
 * descubrible sin permitir configurar un flujo que no tendría a dónde apuntar.
 * Cuando n8n se despliegue, poner en true (o cablear a un flag de plan/feature).
 */
const AUTOMATION_AVAILABLE = false;

export default function WhatsappPage() {
    const navigate = useNavigate();
    const { activeCompany, ...shared } = useSharedData();
    const token = useToken();
    const { showToast } = useToast();

    const { channels, meta, loading, error, refresh } = useWhatsappChannels(token);

    const [wizardOpen, setWizardOpen] = useState(false);
    const [presetBranchId, setPresetBranchId] = useState<string | null>(null);
    const [resumeChannel, setResumeChannel] = useState<WhatsappChannel | null>(null);
    const [testingId, setTestingId] = useState<string | null>(null);
    const [pendingDisconnect, setPendingDisconnect] = useState<WhatsappChannel | null>(null);
    const [otpChannel, setOtpChannel] = useState<WhatsappChannel | null>(null);

    const [settings, setSettings] = useState<CompanySettings | null>(null);
    const [canUpdateSettings, setCanUpdateSettings] = useState(false);
    const [savingSection, setSavingSection] = useState<null | 'privacy' | 'bot'>(null);
    const [settingsErrors, setSettingsErrors] = useState<Partial<Record<keyof CompanySettings, string>>>({});

    const permissions = shared.permissions ?? [];
    const isSystem = shared.role?.is_system ?? false;
    const canConnect = isSystem || permissions.includes('whatsapp.connect');
    const canManageAutomation = isSystem || permissions.includes('whatsapp.update');

    useEffect(() => {
        if (!token) return;
        void loadSettings();
    }, [token]);

    async function loadSettings() {
        try {
            const res = await apiFetch('/api/v1/companies/settings');
            const data = await res.json();
            if (res.ok && data.settings) {
                setSettings(data.settings as CompanySettings);
                setCanUpdateSettings(data.can_update === true);
            }
        } catch {
            // Silencioso: la página puede operar sin este bloque cargado.
        }
    }

    function patchSetting<K extends keyof CompanySettings>(key: K, value: CompanySettings[K]) {
        setSettings((prev) => (prev ? { ...prev, [key]: value } : prev));
        setSettingsErrors((prev) => {
            const next = { ...prev };
            delete next[key];
            return next;
        });
    }

    async function saveSettingsSection(sectionId: 'privacy' | 'bot', keys: (keyof CompanySettings)[]) {
        if (!settings || !canUpdateSettings) return;
        const payload: Partial<CompanySettings> = {};
        for (const key of keys) {
            (payload as Record<string, unknown>)[key] = settings[key];
        }
        setSavingSection(sectionId);
        setSettingsErrors({});
        try {
            const res = await apiFetch('/api/v1/companies/settings', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: payload }),
            });
            const data = await res.json();
            if (res.ok) {
                setSettings(data.settings as CompanySettings);
                showToast('success', 'Preferencias guardadas');
            } else if (res.status === 422 && data.errors) {
                const flat: Partial<Record<keyof CompanySettings, string>> = {};
                for (const [field, msgs] of Object.entries(data.errors)) {
                    const k = field.replace('settings.', '') as keyof CompanySettings;
                    flat[k] = Array.isArray(msgs) ? (msgs[0] as string) : String(msgs);
                }
                setSettingsErrors(flat);
                showToast('error', 'Revisa los campos marcados');
            } else if (res.status === 403) {
                showToast('error', 'No tienes permiso para modificar configuraciones');
            } else {
                showToast('error', data.message ?? 'Error al guardar');
            }
        } catch {
            showToast('error', 'Error de conexión al guardar');
        } finally {
            setSavingSection(null);
        }
    }

    const openWizard = (branchId: string | null = null, resume: WhatsappChannel | null = null) => {
        setPresetBranchId(branchId);
        setResumeChannel(resume);
        setWizardOpen(true);
    };

    const sendTestMessage = async (channel: WhatsappChannel) => {
        setTestingId(channel.id);
        try {
            const res = await apiFetch(`/api/v1/whatsapp/channels/${channel.id}/test-message`, { method: 'POST' });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                showToast('error', (json as { message?: string }).message ?? 'No se pudo enviar el mensaje de prueba.');
                return;
            }
            showToast('success', 'Mensaje de prueba enviado. Revisá tu celular y la bandeja de chats.');
        } catch {
            showToast('error', 'Error de conexión al enviar el mensaje de prueba.');
        } finally {
            setTestingId(null);
        }
    };

    /**
     * Un canal que nunca llegó a conectarse no tiene sesión que cerrar: se borra
     * sin OTP. El resto sí lo exige — desconectar deja a la empresa sin WhatsApp
     * hasta que alguien vuelva a escanear con el teléfono en la mano.
     */
    const requestDisconnect = (channel: WhatsappChannel) => {
        if (channel.status === 'pending' || channel.status === 'verifying') {
            setPendingDisconnect(channel);
            return;
        }
        setOtpChannel(channel);
    };

    const disconnect = async (channel: WhatsappChannel, code?: string) => {
        try {
            const res = await apiFetch(`/api/v1/whatsapp/channels/${channel.id}`, {
                method: 'DELETE',
                headers: code ? { 'X-Whatsapp-Verification-Code': code } : undefined,
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                showToast('error', (json as { message?: string }).message ?? 'No se pudo desconectar.');
                return;
            }
            showToast('success', 'Canal desconectado.');
            setPendingDisconnect(null);
            setOtpChannel(null);
            await refresh();
        } catch {
            showToast('error', 'Error de conexión al desconectar.');
        }
    };

    const branchesWithout = meta?.branches_without_channel ?? [];
    const connectedCount = meta?.connected_count ?? 0;
    const branchCount = meta?.branch_count ?? 0;
    const hasCompanyChannel = meta?.has_company_channel ?? false;

    const summary = [
        branchCount > 0 ? `${connectedCount} de ${branchCount} sede${branchCount === 1 ? '' : 's'} con WhatsApp` : null,
        hasCompanyChannel ? '1 número de empresa' : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <PageShell title="WhatsApp · flexyflow">
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
                {loading ? (
                    <WhatsappPageSkeleton />
                ) : (
                    <>
                        <PageHeader
                            title="WhatsApp"
                            description={summary || 'Conectá el WhatsApp de tu negocio para atender a los clientes desde el panel.'}
                            actions={
                                canConnect ? (
                                    <Button onClick={() => openWizard()}>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Conectar WhatsApp
                                    </Button>
                                ) : undefined
                            }
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertTriangle className="h-4 w-4" />
                                <AlertTitle>No podemos contactar el servidor de mensajería</AlertTitle>
                                <AlertDescription className="space-y-2">
                                    <p>{error}</p>
                                    <Button size="sm" variant="outline" onClick={() => void refresh()}>
                                        Reintentar
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}

                        {channels.length === 0 && branchesWithout.length === 0 ? (
                            <EmptyState
                                icon={MessageCircle}
                                title="Todavía no conectaste ningún WhatsApp"
                                description="Vinculá tu número escaneando un QR, igual que WhatsApp Web. Toma menos de dos minutos."
                                action={
                                    canConnect ? (
                                        <Button onClick={() => openWizard()}>
                                            <Plus className="mr-2 h-4 w-4" />
                                            Conectar WhatsApp
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {channels.map((channel) => (
                                    <ChannelCard
                                        key={channel.id}
                                        channel={channel}
                                        canManage={canConnect}
                                        testing={testingId === channel.id}
                                        onReconnect={(c) => openWizard(c.branch_id, c)}
                                        onDisconnect={requestDisconnect}
                                        onTestMessage={(c) => void sendTestMessage(c)}
                                        onOpenChats={(c) => navigate(`/chats?channel=${c.id}`)}
                                    />
                                ))}

                                {branchesWithout.map((branch) => (
                                    <BranchPlaceholderCard
                                        key={branch.id}
                                        branchName={branch.name}
                                        onConnect={() => openWizard(branch.id)}
                                        disabledReason={canConnect ? undefined : 'Necesitás el permiso «Conectar WhatsApp».'}
                                    />
                                ))}
                            </div>
                        )}

                        {settings && (
                            <WhatsappPreferences
                                settings={settings}
                                readOnly={!canUpdateSettings}
                                savingSection={savingSection}
                                errors={settingsErrors}
                                companyName={activeCompany?.name ?? ''}
                                onPatch={patchSetting}
                                onSave={saveSettingsSection}
                            />
                        )}

                        {/* Gestión de respuestas rápidas (§8.4b punto 7). Solo owner/admin,
                            proxy en `canUpdateSettings`; el backend valida igual. */}
                        {canUpdateSettings && <QuickRepliesManager token={token} branches={shared.branches ?? []} />}

                        {/* Automatización (n8n) por empresa/sede (F6, §9.5). Visible como
                            configuración; las acciones se gatean por `whatsapp.update`. */}
                        <AutomationSection
                            token={token}
                            branches={shared.branches ?? []}
                            canManage={canManageAutomation}
                            available={AUTOMATION_AVAILABLE}
                        />

                        <div className="text-muted-foreground text-xs">
                            <button
                                type="button"
                                className="inline-flex items-center gap-1 underline-offset-2 hover:underline"
                                onClick={() => navigate('/chats')}
                            >
                                <MessageCircle className="h-3 w-3" />
                                Ir al panel de chats
                            </button>
                        </div>
                    </>
                )}
            </div>

            <ConnectWizard
                open={wizardOpen}
                onClose={() => setWizardOpen(false)}
                branches={branchesWithout}
                hasCompanyChannel={hasCompanyChannel}
                canManageCompanyChannel={meta?.can_manage_company_channel ?? false}
                presetBranchId={presetBranchId}
                resumeChannel={resumeChannel}
                onFinished={() => {
                    setWizardOpen(false);
                    void refresh();
                }}
                onGoToChats={() => navigate('/chats')}
            />

            <ConfirmDialog
                open={pendingDisconnect !== null}
                title="Descartar esta conexión"
                message="Este canal nunca llegó a conectarse, así que no hay conversaciones ni sesión que perder."
                confirmLabel="Descartar"
                onConfirm={() => pendingDisconnect && void disconnect(pendingDisconnect)}
                onCancel={() => setPendingDisconnect(null)}
            />

            {otpChannel && (
                <WhatsappVerificationCodeModal
                    open
                    action="disconnect"
                    title="Desconectar WhatsApp"
                    description="Se cerrará la sesión de este número y dejarán de entrar mensajes hasta que vuelvas a escanear el QR. Solo el propietario puede confirmar."
                    confirmLabel="Desconectar"
                    onClose={() => setOtpChannel(null)}
                    onVerified={async (code) => {
                        await disconnect(otpChannel, code);
                    }}
                />
            )}
        </PageShell>
    );
}

/**
 * Burbuja de mensaje estilo WhatsApp para previsualizar los textos del bot.
 *
 * El fondo `#e5ddd5` y la burbuja blanca con texto gris son colores de
 * marca de WhatsApp — se conservan a propósito (NO son tokens del DS) para
 * que la vista previa se sienta como el chat real. El resto del módulo SÍ
 * usa tokens DS.
 */
function WhatsAppPreview({ message, companyName }: { message: string; companyName: string }) {
    const rendered = message.replace('{company_name}', companyName || 'Tu Empresa');
    return (
        <div className="rounded-xl bg-[#e5ddd5] p-3">
            <div className="flex items-end gap-2">
                <div className="max-w-[80%] rounded-2xl rounded-tl-sm bg-white px-3 py-2 shadow-sm">
                    <p className="text-sm whitespace-pre-wrap text-gray-800">{rendered}</p>
                    <p className="mt-0.5 text-right text-[10px] text-gray-400">12:00</p>
                </div>
            </div>
        </div>
    );
}

function WhatsappPreferences({
    settings,
    readOnly,
    savingSection,
    errors,
    companyName,
    onPatch,
    onSave,
}: {
    settings: CompanySettings;
    readOnly: boolean;
    savingSection: null | 'privacy' | 'bot';
    errors: Partial<Record<keyof CompanySettings, string>>;
    companyName: string;
    onPatch: <K extends keyof CompanySettings>(key: K, value: CompanySettings[K]) => void;
    onSave: (sectionId: 'privacy' | 'bot', keys: (keyof CompanySettings)[]) => void;
}) {
    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-lg font-semibold tracking-tight">Preferencias</h2>
                <p className="text-muted-foreground text-sm">Ajustes específicos del canal de WhatsApp.</p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CheckCheck className="h-4 w-4 text-[color:var(--color-status-safe)]" />
                            Privacidad
                        </CardTitle>
                        <CardDescription>Controla cómo se confirman los mensajes recibidos.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="border-border flex items-start justify-between gap-3 rounded-lg border px-3 py-2.5">
                            <div className="flex min-w-0 flex-col gap-1">
                                <label htmlFor="read-receipts" className="cursor-pointer text-sm font-medium">
                                    Marcar mensajes como leídos
                                </label>
                                <p className="text-muted-foreground text-xs">
                                    Si está activo, el cliente verá el doble chulito azul cuando un mensaje suyo se procese en el panel. Por defecto
                                    está desactivado.
                                </p>
                            </div>
                            <Checkbox
                                id="read-receipts"
                                checked={settings.whatsapp_read_receipts}
                                disabled={readOnly}
                                onCheckedChange={(v) => onPatch('whatsapp_read_receipts', Boolean(v))}
                            />
                        </div>
                        {errors.whatsapp_read_receipts && <p className="text-destructive text-xs">{errors.whatsapp_read_receipts}</p>}

                        {!readOnly && (
                            <div className="flex justify-end pt-1">
                                <Button
                                    onClick={() => onSave('privacy', ['whatsapp_read_receipts'])}
                                    disabled={savingSection === 'privacy'}
                                    size="sm"
                                    className="min-w-24"
                                >
                                    {savingSection === 'privacy' ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Bot className="h-4 w-4 text-[color:var(--color-status-safe)]" />
                            Bot de WhatsApp
                        </CardTitle>
                        <CardDescription>Mensajes automáticos que envía el bot a los clientes.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="space-y-1.5">
                            <Label>Mensaje de bienvenida</Label>
                            <p className="text-muted-foreground text-xs">
                                Usa <code className="bg-muted rounded px-1">{'{company_name}'}</code> para insertar el nombre de la empresa.
                            </p>
                            <textarea
                                value={settings.bot_welcome_message}
                                onChange={(e) => onPatch('bot_welcome_message', e.target.value)}
                                disabled={readOnly}
                                rows={3}
                                maxLength={500}
                                className="border-input bg-background focus:border-ring w-full rounded-lg border px-3 py-2 text-sm focus:outline-none disabled:opacity-60"
                            />
                            {errors.bot_welcome_message && <p className="text-destructive text-xs">{errors.bot_welcome_message}</p>}
                            <p className="text-muted-foreground text-xs font-medium">Vista previa</p>
                            <WhatsAppPreview message={settings.bot_welcome_message} companyName={companyName} />
                        </div>

                        <div className="border-border flex items-start justify-between gap-3 rounded-lg border px-3 py-2.5">
                            <div className="flex min-w-0 flex-col gap-1">
                                <label htmlFor="away-enabled" className="cursor-pointer text-sm font-medium">
                                    Responder automáticamente fuera de horario
                                </label>
                                <p className="text-muted-foreground text-xs">
                                    Si está activo, cuando un cliente escribe con el local cerrado se le envía el mensaje de abajo, una sola vez por
                                    día. No necesita automatización. Por defecto está desactivado.
                                </p>
                            </div>
                            <Checkbox
                                id="away-enabled"
                                checked={settings.whatsapp_away_reply_enabled}
                                disabled={readOnly}
                                onCheckedChange={(v) => onPatch('whatsapp_away_reply_enabled', Boolean(v))}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Mensaje fuera de horario</Label>
                            <textarea
                                value={settings.bot_away_message}
                                onChange={(e) => onPatch('bot_away_message', e.target.value)}
                                disabled={readOnly}
                                rows={2}
                                maxLength={500}
                                className="border-input bg-background focus:border-ring w-full rounded-lg border px-3 py-2 text-sm focus:outline-none disabled:opacity-60"
                            />
                            {errors.bot_away_message && <p className="text-destructive text-xs">{errors.bot_away_message}</p>}
                            <p className="text-muted-foreground text-xs font-medium">Vista previa</p>
                            <WhatsAppPreview message={settings.bot_away_message} companyName={companyName} />
                        </div>

                        {!readOnly && (
                            <div className="flex justify-end pt-1">
                                <Button
                                    onClick={() => onSave('bot', ['bot_welcome_message', 'bot_away_message', 'whatsapp_away_reply_enabled'])}
                                    disabled={savingSection === 'bot'}
                                    size="sm"
                                    className="min-w-24"
                                >
                                    {savingSection === 'bot' ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
