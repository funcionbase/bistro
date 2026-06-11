import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useToast } from '@/components/ui/toast';
import { WhatsappPageSkeleton } from '@/components/ui/whatsapp-page-skeleton';
import { WhatsappStatusPill } from '@/components/ui/whatsapp-status-pill';
import WhatsappVerificationCodeModal, { type WhatsappAction } from '@/components/whatsapp/whatsapp-verification-code-modal';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { type CompanySettings } from '@/types';

import {
    AlertTriangle,
    Bot,
    CheckCheck,
    CheckCircle2,
    HelpCircle,
    MessageCircle,
    Phone,
    PhoneOff,
    RefreshCw,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

declare global {
    interface Window {
        FB?: {
            init: (params: Record<string, unknown>) => void;
            login: (callback: (resp: { authResponse?: { code?: string } }) => void, options?: Record<string, unknown>) => void;
        };
        fbAsyncInit?: () => void;
    }
}

interface WhatsappAccountResponse {
    data: {
        connected: boolean;
        status: string;
        provisioning_mode: string | null;
        phone_e164: string | null;
        display_name: string | null;
        display_name_status: string | null;
        quality_rating: string | null;
        messaging_tier: string | null;
        is_business_verified: boolean;
        connected_at: string | null;
        last_synced_at: string | null;
        last_error: string | null;
    };
    meta: {
        config_id: string | null;
        app_id: string | null;
        graph_api_version: string | null;
        environment: string | null;
    };
}


export default function WhatsappPage() {
    const navigate = useNavigate();
    const { activeCompany } = useSharedData();
    const token = useToken();
    const { showToast } = useToast();

    const [account, setAccount] = useState<WhatsappAccountResponse['data'] | null>(null);
    const [meta, setMeta] = useState<WhatsappAccountResponse['meta'] | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [modal, setModal] = useState<null | {
        action: WhatsappAction;
        title: string;
        description: string;
        confirmLabel: string;
        onConfirm: (code: string) => Promise<void>;
    }>(null);

    const [settings, setSettings] = useState<CompanySettings | null>(null);
    const [canUpdateSettings, setCanUpdateSettings] = useState(false);
    const [savingSection, setSavingSection] = useState<null | 'privacy' | 'bot'>(null);
    const [settingsErrors, setSettingsErrors] = useState<Partial<Record<keyof CompanySettings, string>>>({});

    useEffect(() => {
        if (!token) {
            setLoading(false);
            setError('No hay sesion activa.');
            return;
        }
        void loadAccount();
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

    // Carga el SDK de Facebook una sola vez (necesario para Embedded Signup).
    useEffect(() => {
        if (!meta?.app_id) return;
        if (document.getElementById('facebook-jssdk')) return;

        const script = document.createElement('script');
        script.id = 'facebook-jssdk';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        document.head.appendChild(script);

        window.fbAsyncInit = () => {
            window.FB?.init({
                appId: meta.app_id,
                cookie: false,
                xfbml: false,
                version: meta.graph_api_version ?? 'v25.0',
            });
        };
    }, [meta?.app_id, meta?.graph_api_version]);

    async function loadAccount() {
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/whatsapp');
            const json = (await res.json()) as WhatsappAccountResponse | { message?: string };
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo cargar el estado de WhatsApp.');
                return;
            }
            const data = json as WhatsappAccountResponse;
            setAccount(data.data);
            setMeta(data.meta);
        } catch {
            setError('Error de conexion al cargar el estado de WhatsApp.');
        } finally {
            setLoading(false);
        }
    }

    function launchEmbeddedSignup(): Promise<{ code?: string; waba_id?: string; phone_number_id?: string }> {
        return new Promise((resolve, reject) => {
            if (!window.FB || !meta?.config_id) {
                reject(new Error('Embedded Signup no disponible: SDK de Facebook no cargo.'));
                return;
            }
            window.FB.login(
                (resp) => {
                    if (resp?.authResponse?.code) {
                        // El frontend solo recibe el `code`. Los IDs (waba_id,
                        // phone_number_id) llegan al frontend via un mensaje
                        // postMessage del popup en flujos reales; para el MVP
                        // pediremos al usuario confirmacion en pantalla o lo
                        // recogeremos del callback del SDK.
                        resolve({ code: resp.authResponse.code });
                    } else {
                        reject(new Error('El popup se cerro sin completar.'));
                    }
                },
                {
                    config_id: meta.config_id,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: { feature: 'whatsapp_embedded_signup' },
                },
            );
        });
    }

    function openConnectModal() {
        setModal({
            action: 'connect',
            title: 'Conectar WhatsApp',
            description: 'Confirma con el codigo que enviaremos al propietario. Despues abriremos la ventana de Meta para enlazar tu numero.',
            confirmLabel: 'Verificar y conectar',
            onConfirm: async (code) => {
                try {
                    const fbResponse = await launchEmbeddedSignup();
                    const res = await apiFetch('/api/v1/whatsapp/embedded-signup-callback', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Whatsapp-Verification-Code': code,
                        },
                        body: JSON.stringify({
                            code: fbResponse.code,
                            waba_id: fbResponse.waba_id ?? '',
                            phone_number_id: fbResponse.phone_number_id ?? '',
                        }),
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        showToast('error', data.message ?? 'No se pudo conectar WhatsApp.');
                        return;
                    }
                    showToast('success', 'WhatsApp conectado.');
                    setModal(null);
                    await loadAccount();
                } catch (e: unknown) {
                    const message = e instanceof Error ? e.message : 'Error inesperado.';
                    showToast('error', message);
                }
            },
        });
    }

    function openSwapModal() {
        setModal({
            action: 'swap',
            title: 'Cambiar numero de WhatsApp',
            description:
                'Esto liberara el numero actual y te permitira registrar uno nuevo. Las conversaciones quedan archivadas. Solo el propietario puede confirmar.',
            confirmLabel: 'Liberar numero actual',
            onConfirm: async (code) => {
                const res = await apiFetch('/api/v1/whatsapp/phone', {
                    method: 'DELETE',
                    headers: { 'X-Whatsapp-Verification-Code': code },
                });
                const data = await res.json();
                if (!res.ok) {
                    showToast('error', data.message ?? 'No se pudo cambiar el numero.');
                    return;
                }
                showToast('success', 'Numero liberado. Vuelve a "Conectar WhatsApp" para enlazar el nuevo.');
                setModal(null);
                await loadAccount();
            },
        });
    }

    function openDisconnectModal() {
        setModal({
            action: 'disconnect',
            title: 'Desconectar WhatsApp',
            description: 'Se liberara el numero en Meta y se desactivara la integracion. Solo el propietario puede confirmar.',
            confirmLabel: 'Desconectar',
            onConfirm: async (code) => {
                const res = await apiFetch('/api/v1/whatsapp', {
                    method: 'DELETE',
                    headers: { 'X-Whatsapp-Verification-Code': code },
                });
                const data = await res.json();
                if (!res.ok) {
                    showToast('error', data.message ?? 'No se pudo desconectar.');
                    return;
                }
                showToast('success', 'WhatsApp desconectado.');
                setModal(null);
                await loadAccount();
            },
        });
    }

    function openNaasModal() {
        setModal({
            action: 'connect',
            title: 'Solicitar numero (flexyflow lo provee)',
            description: 'Confirma con el codigo del propietario. Te contactaremos para coordinar firma y documentos legales.',
            confirmLabel: 'Enviar solicitud',
            onConfirm: async (code) => {
                const res = await apiFetch('/api/v1/whatsapp/naas-request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Whatsapp-Verification-Code': code,
                    },
                    body: JSON.stringify({ naas_provider: 'pending' }),
                });
                const data = await res.json();
                if (!res.ok) {
                    showToast('error', data.message ?? 'No se pudo crear la solicitud.');
                    return;
                }
                showToast('success', 'Solicitud creada. El equipo te contactara.');
                setModal(null);
                await loadAccount();
            },
        });
    }

    return (
        <PageShell title="WhatsApp · flexyflow">
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
                {loading ? (
                    <WhatsappPageSkeleton />
                ) : (
                    <>
                        <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0 space-y-1">
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]">
                                        <MessageCircle className="h-5 w-5" />
                                    </span>
                                    <h1 className="text-foreground text-2xl font-semibold tracking-tight sm:text-3xl">WhatsApp</h1>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    Conecta el número de tu empresa a WhatsApp Cloud API. Los mensajes entrantes aparecerán en el panel de Chats.
                                </p>
                                {activeCompany?.name && (
                                    <p className="text-muted-foreground text-xs">
                                        Empresa activa: <span className="text-foreground font-medium">{activeCompany.name}</span>
                                    </p>
                                )}
                            </div>
                            {account && <WhatsappStatusPill status={account.connected ? 'connected' : 'disconnected'} />}
                        </header>

                        <Alert>
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle>Bot en desarrollo</AlertTitle>
                            <AlertDescription>
                                El bot automatico (n8n) aun no esta disponible. Mientras tanto, los mensajes entrantes apareceran en el panel de chats
                                con el bot pausado, y un operador puede responder manualmente.
                            </AlertDescription>
                        </Alert>

                        {error && (
                            <Alert variant="destructive">
                                <AlertTriangle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {account && (
                            <>
                                {account.connected ? (
                                    <ConnectedCard
                                        account={account}
                                        onSwap={openSwapModal}
                                        onDisconnect={openDisconnectModal}
                                        onRefresh={loadAccount}
                                    />
                                ) : (
                                    <DisconnectedView onConnect={openConnectModal} onNaas={openNaasModal} />
                                )}
                            </>
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

            {modal && (
                <WhatsappVerificationCodeModal
                    open
                    action={modal.action}
                    title={modal.title}
                    description={modal.description}
                    confirmLabel={modal.confirmLabel}
                    onClose={() => setModal(null)}
                    onVerified={modal.onConfirm}
                />
            )}
        </PageShell>
    );
}

function ConnectedCard({
    account,
    onSwap,
    onDisconnect,
    onRefresh,
}: {
    account: WhatsappAccountResponse['data'];
    onSwap: () => void;
    onDisconnect: () => void;
    onRefresh: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <CardTitle className="flex items-center gap-2 text-[color:var(--color-status-safe)]">
                            <CheckCircle2 className="h-5 w-5 shrink-0" />
                            WhatsApp conectado
                        </CardTitle>
                        <CardDescription className="mt-1 truncate">{account.display_name ?? 'Sin display name'}</CardDescription>
                    </div>
                    <Badge variant="outline" className="w-fit capitalize">
                        {account.provisioning_mode === 'naas' ? 'Provisto por flexyflow' : 'Embedded Signup'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
                <Field icon={<Phone className="h-4 w-4" />} label="Numero" value={account.phone_e164 ?? '—'} />
                <Field
                    icon={<ShieldCheck className="h-4 w-4" />}
                    label="Verificado por Meta"
                    value={account.is_business_verified ? 'Si' : 'No (opcional)'}
                />
                <Field icon={<HelpCircle className="h-4 w-4" />} label="Estado del nombre" value={account.display_name_status ?? '—'} />
                <Field icon={<HelpCircle className="h-4 w-4" />} label="Calidad" value={account.quality_rating ?? '—'} />
            </CardContent>
            <CardFooter className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Button variant="outline" onClick={onRefresh} className="w-full sm:w-auto">
                    <RefreshCw className="mr-2 h-4 w-4" />
                    Actualizar
                </Button>
                <Button variant="outline" onClick={onSwap} className="w-full sm:w-auto">
                    <Phone className="mr-2 h-4 w-4" />
                    Cambiar numero
                </Button>
                <Button variant="destructive" onClick={onDisconnect} className="w-full sm:w-auto">
                    <PhoneOff className="mr-2 h-4 w-4" />
                    Desconectar
                </Button>
            </CardFooter>
        </Card>
    );
}

function DisconnectedView({ onConnect, onNaas }: { onConnect: () => void; onNaas: () => void }) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            <Card className="flex flex-col transition-shadow hover:shadow-md">
                <CardHeader>
                    <div className="mb-2 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]">
                        <Phone className="h-5 w-5" />
                    </div>
                    <CardTitle>Tengo mi número</CardTitle>
                    <CardDescription>
                        Conecta tu número actual a WhatsApp Cloud API. ~10 minutos. Necesitas una cuenta de Facebook personal y recibir SMS.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex-1">
                    <ul className="text-muted-foreground ml-5 list-disc space-y-1 text-sm">
                        <li>El número NO debe estar en la app de WhatsApp.</li>
                        <li>flexyflow administra la WABA en tu nombre.</li>
                        <li>Sin costo adicional aparte de tu plan.</li>
                    </ul>
                </CardContent>
                <CardFooter>
                    <Button onClick={onConnect} className="w-full sm:w-auto">
                        Conectar mi número
                    </Button>
                </CardFooter>
            </Card>

            <Card className="flex flex-col transition-shadow hover:shadow-md">
                <CardHeader>
                    <div className="mb-2 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-[color:var(--color-status-info)]/10 text-[color:var(--color-status-info)]">
                        <UserPlus className="h-5 w-5" />
                    </div>
                    <CardTitle>Que flexyflow me provea uno</CardTitle>
                    <CardDescription>
                        Recibes un número nuevo, ya configurado a tu nombre. No tocas Meta ni Facebook. Costo mensual del número.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex-1">
                    <ul className="text-muted-foreground ml-5 list-disc space-y-1 text-sm">
                        <li>Tiempo del cliente: ~15 min repartidos en email/firma.</li>
                        <li>Documentos legales de la empresa.</li>
                        <li>Te enviamos confirmación cuando esté listo.</li>
                    </ul>
                </CardContent>
                <CardFooter>
                    <Button variant="outline" onClick={onNaas} className="w-full sm:w-auto">
                        Solicitar número
                    </Button>
                </CardFooter>
            </Card>
        </div>
    );
}

function Field({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="flex items-start gap-2">
            <div className="text-muted-foreground">{icon}</div>
            <div>
                <div className="text-muted-foreground text-xs">{label}</div>
                <div className="font-medium">{value}</div>
            </div>
        </div>
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
                                    onClick={() => onSave('bot', ['bot_welcome_message', 'bot_away_message'])}
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
