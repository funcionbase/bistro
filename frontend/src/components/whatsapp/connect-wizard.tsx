import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SelectableTile } from '@/components/ui/selectable-tile';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { WizardStepIndicator } from '@/components/ui/wizard-step-indicator';
import { QrLinker } from '@/components/whatsapp/qr-linker';
import { useIsMobile } from '@/hooks/use-mobile';
import type { BranchWithoutChannel, WhatsappChannel } from '@/hooks/use-whatsapp-channels';
import { apiFetch } from '@/lib/api';

import { AlertTriangle, Building2, CheckCircle2, MessageCircle, Plus, Store } from 'lucide-react';
import { useEffect, useState } from 'react';

const STEPS = [{ label: 'Alcance' }, { label: 'Vincular' }] as const;

interface ConnectWizardProps {
    open: boolean;
    onClose: () => void;
    /** Sedes sin canal. Las que ya tienen uno ni llegan acá. */
    branches: BranchWithoutChannel[];
    /** Ya existe el número de toda la empresa: la opción se ofrece deshabilitada. */
    hasCompanyChannel: boolean;
    /** Solo owner/admin puede conectar el número de empresa (§7.3). */
    canManageCompanyChannel: boolean;
    /** Sede preseleccionada cuando se entra desde el placeholder de una tarjeta. */
    presetBranchId?: string | null;
    /** Canal ya creado que quedó a medio vincular: entra directo al paso 2. */
    resumeChannel?: WhatsappChannel | null;
    onFinished: () => void;
    onGoToChats: () => void;
}

type Scope = 'company' | 'branch';

/**
 * Wizard de conexión de WhatsApp en dos pasos (§8.3).
 *
 * **Paso 1 se salta solo** cuando no hay decisión que tomar: una sola sede, o el
 * usuario no puede conectar el número de empresa. Preguntarle a quien no tiene
 * opciones es fricción pura.
 *
 * **El consentimiento no es un checkbox de trámite**: es lo único que respalda
 * que al cliente se le dijo que WhatsApp puede bloquearle el número. Queda
 * registrado con actor, IP y timestamp del lado del servidor.
 *
 * En mobile va a pantalla completa (`Sheet`): el caso real es escanear con un
 * segundo teléfono, y un QR de 240 px dentro de un diálogo centrado no entra.
 */
export function ConnectWizard({
    open,
    onClose,
    branches,
    hasCompanyChannel,
    canManageCompanyChannel,
    presetBranchId = null,
    resumeChannel = null,
    onFinished,
    onGoToChats,
}: ConnectWizardProps) {
    const isMobile = useIsMobile();

    const [step, setStep] = useState(0);
    const [scope, setScope] = useState<Scope>('branch');
    const [branchId, setBranchId] = useState<string | null>(presetBranchId);
    const [consent, setConsent] = useState(false);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [channel, setChannel] = useState<WhatsappChannel | null>(resumeChannel);
    const [connected, setConnected] = useState<WhatsappChannel | null>(null);

    // El paso 1 no aporta nada si solo hay un camino posible. Se calcula acá y
    // no en el render para que el "Atrás" del paso 2 no lleve a una pantalla
    // vacía con una sola opción ya elegida.
    const companyOptionAvailable = canManageCompanyChannel && !hasCompanyChannel;
    const skipScopeStep = !companyOptionAvailable && branches.length <= 1;

    useEffect(() => {
        if (!open) return;

        setError(null);
        setConsent(false);
        setConnected(null);
        setChannel(resumeChannel);
        setStep(resumeChannel ? 1 : 0);

        if (resumeChannel) return;

        if (presetBranchId) {
            setScope('branch');
            setBranchId(presetBranchId);
        } else if (branches.length === 0 && companyOptionAvailable) {
            setScope('company');
            setBranchId(null);
        } else {
            setScope(companyOptionAvailable ? 'branch' : 'branch');
            setBranchId(branches.length === 1 ? branches[0].id : null);
        }
    }, [open, presetBranchId, resumeChannel, branches, companyOptionAvailable]);

    const createChannel = async () => {
        if (creating) return;
        setCreating(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/whatsapp/channels', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    branch_id: scope === 'branch' ? branchId : null,
                    consent_accepted: true,
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo crear el canal.');
                return;
            }
            setChannel((json as { data: WhatsappChannel }).data);
            setStep(1);
        } catch {
            setError('Error de conexión al crear el canal.');
        } finally {
            setCreating(false);
        }
    };

    /**
     * §8.4b punto 5: escanear con el WhatsApp equivocado es un error frecuente y
     * sin esta confirmación no habría forma de notarlo hasta que un cliente
     * escribiera al número que no es.
     */
    const disconnectWrongNumber = async () => {
        if (!connected) return;
        try {
            await apiFetch(`/api/v1/whatsapp/channels/${connected.id}`, { method: 'DELETE' });
        } catch {
            // Da igual el resultado: igual se cierra y la lista se recarga con
            // el estado real del servidor.
        }
        onFinished();
        onClose();
    };

    const canContinue = consent && (scope === 'company' || Boolean(branchId));

    const body = connected ? (
        <div className="space-y-4 py-2">
            <div className="flex flex-col items-center gap-2 text-center">
                <CheckCircle2 className="h-12 w-12 text-[color:var(--color-status-safe)]" />
                <p className="text-lg font-semibold">Conectaste el {connected.phone_e164 ?? 'número'}</p>
                {connected.display_name && <p className="text-muted-foreground text-sm">{connected.display_name}</p>}
            </div>

            <Alert>
                <AlertTriangle className="h-4 w-4" />
                <AlertTitle>¿Es el número correcto?</AlertTitle>
                <AlertDescription>Si escaneaste con otro WhatsApp, desconectalo ahora y volvé a intentar con el número correcto.</AlertDescription>
            </Alert>

            <div className="flex flex-col gap-2 sm:flex-row">
                <Button
                    className="w-full sm:w-auto"
                    onClick={() => {
                        onFinished();
                        onGoToChats();
                    }}
                >
                    <MessageCircle className="mr-2 h-4 w-4" />
                    Sí, ir a chats
                </Button>
                <Button
                    variant="outline"
                    className="w-full sm:w-auto"
                    onClick={() => {
                        onFinished();
                        onClose();
                    }}
                >
                    <Plus className="mr-2 h-4 w-4" />
                    Conectar otra sede
                </Button>
                <Button variant="ghost" className="w-full sm:ml-auto sm:w-auto" onClick={() => void disconnectWrongNumber()}>
                    No, desconectar
                </Button>
            </div>
        </div>
    ) : step === 1 && channel ? (
        <QrLinker channel={channel} onConnected={setConnected} onError={setError} />
    ) : (
        <div className="space-y-4">
            {companyOptionAvailable || branches.length > 0 ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectableTile
                        onClick={() => {
                            setScope('company');
                            setBranchId(null);
                        }}
                        disabled={!canManageCompanyChannel || hasCompanyChannel}
                        disabledTooltip={
                            hasCompanyChannel
                                ? 'Ya hay un número para toda la empresa.'
                                : 'Solo el propietario o un administrador puede conectar el número de toda la empresa.'
                        }
                        className={scope === 'company' ? 'border-primary ring-primary/40 ring-2' : ''}
                    >
                        <Building2 className="text-muted-foreground h-6 w-6" />
                        <div className="space-y-1 text-center">
                            <p className="font-medium">Toda la empresa</p>
                            <p className="text-muted-foreground text-xs">Un solo número para todos los pedidos.</p>
                        </div>
                    </SelectableTile>

                    <SelectableTile
                        onClick={() => setScope('branch')}
                        disabled={branches.length === 0}
                        disabledTooltip="Todas tus sedes ya tienen un número conectado."
                        className={scope === 'branch' ? 'border-primary ring-primary/40 ring-2' : ''}
                    >
                        <Store className="text-muted-foreground h-6 w-6" />
                        <div className="space-y-1 text-center">
                            <p className="font-medium">Una sede específica</p>
                            <p className="text-muted-foreground text-xs">Cada sede con su número.</p>
                        </div>
                    </SelectableTile>
                </div>
            ) : null}

            {scope === 'branch' && branches.length > 0 && (
                <div className="space-y-1.5">
                    <Label htmlFor="wizard-branch">Sede</Label>
                    <Select value={branchId ?? ''} onValueChange={(v) => setBranchId(v)}>
                        <SelectTrigger id="wizard-branch" className="w-full">
                            <SelectValue placeholder="Elegí la sede" />
                        </SelectTrigger>
                        <SelectContent>
                            {branches.map((b) => (
                                <SelectItem key={b.id} value={b.id}>
                                    {b.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-muted-foreground text-xs">Las sedes que ya tienen número no aparecen en la lista.</p>
                </div>
            )}

            <Alert variant="warning">
                <AlertTriangle className="h-4 w-4" />
                <AlertTitle>Antes de conectar</AlertTitle>
                <AlertDescription className="space-y-3">
                    <p>
                        flexyflow vincula tu WhatsApp como un dispositivo más (igual que WhatsApp Web). Tu número sigue funcionando en tu celular.
                        WhatsApp no autoriza formalmente este tipo de conexión y <strong>puede bloquear el número</strong>, sobre todo si se usa para
                        enviar mensajes masivos no solicitados.
                    </p>
                    <div className="flex items-start gap-2">
                        <Checkbox id="wa-consent" checked={consent} onCheckedChange={(v) => setConsent(Boolean(v))} />
                        <label htmlFor="wa-consent" className="cursor-pointer text-sm font-medium">
                            Entiendo el riesgo y quiero conectar mi número.
                        </label>
                    </div>
                </AlertDescription>
            </Alert>

            <div className="flex justify-end">
                <Button onClick={() => void createChannel()} disabled={!canContinue || creating}>
                    {creating ? 'Creando…' : 'Continuar'}
                </Button>
            </div>
        </div>
    );

    const content = (
        <div className="space-y-4">
            {!connected && !skipScopeStep && <WizardStepIndicator steps={STEPS} currentStep={step} />}
            {error && !connected && (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            {body}
        </div>
    );

    const title = connected ? 'Número conectado' : step === 1 ? 'Vinculá tu WhatsApp' : '¿Quién lo usa?';

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
                <SheetContent side="bottom" className="h-[100svh] overflow-y-auto">
                    <SheetHeader>
                        <SheetTitle>{title}</SheetTitle>
                    </SheetHeader>
                    <div className="pb-8">{content}</div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {step === 1 && !connected ? 'Escaneá el código con el celular que tiene el WhatsApp del negocio.' : 'Elegí el alcance del número y aceptá el aviso.'}
                    </DialogDescription>
                </DialogHeader>
                {content}
            </DialogContent>
        </Dialog>
    );
}
