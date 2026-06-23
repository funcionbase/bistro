import { BusinessTypeSelector } from '@/components/business-type-selector';
import { PlanInfoBlock } from '@/components/enrollment/plan-info-block';
import { PromoLandingPanel } from '@/components/enrollment/promo-landing-panel';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { WizardStepIndicator } from '@/components/ui/wizard-step-indicator';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { useDefaultPlan } from '@/hooks/use-default-plan';
import { usePromoCodeFromUrl } from '@/hooks/use-promo-code-from-url';
import { apiFetch } from '@/lib/api';
import { reloadContext } from '@/lib/navigate-compat';
import { route } from '@/lib/route-compat';
import { useDocumentTitle } from '@/lib/use-document-title';
import { AlertCircle, CheckCircle2, Clock, LoaderCircle, ShieldCheck } from 'lucide-react';
import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';

const PROOF_MAX_BYTES = 10 * 1024 * 1024;
const PROOF_ACCEPTED_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
];
const PROOF_ACCEPT_ATTR = '.pdf,.doc,.docx,.jpg,.jpeg,.png';

const WIZARD_STEPS = [{ label: 'Contrato' }, { label: 'Tipo de negocio' }, { label: 'Datos' }] as const;

const HERO_HIGHLIGHTS: Array<{ label: string; value: string }> = [
    { label: 'Tarjeta', value: 'No requerida' },
    { label: 'Setup', value: '< 1 minuto' },
    { label: 'Validación', value: 'Humana' },
];

type CompletedCompany = {
    nit: string;
    commercial_name: string;
    status: string;
};

type FormErrors = Record<string, string | undefined>;

export default function EnrollmentCompany() {
    useDocumentTitle('Registrar empresa');
    const navigate = useNavigate();
    const name = 'flexyflow';
    const bootstrap = useBootstrap().data;
    const availableBanks = bootstrap?.availableBanks ?? [];
    const contractUrl = bootstrap?.legalUrls?.contract;

    // #246 — promo code desde URL `?promo=` + plan default público.
    const { promoSlug, preview: promoPreview, loading: promoLoading, invalidReason: promoInvalidReason } = usePromoCodeFromUrl();
    const { plan: defaultPlan, loading: defaultPlanLoading } = useDefaultPlan();
    const hasValidPromo = promoPreview !== null;

    const [completedCompany, setCompletedCompany] = useState<CompletedCompany | null>(null);
    const [step, setStep] = useState<number>(1);
    const [errors, setErrors] = useState<FormErrors>({});
    const [processing, setProcessing] = useState<boolean>(false);

    const [acceptContract, setAcceptContract] = useState(false);
    const [businessType, setBusinessType] = useState<string>('restaurant');
    const [nit, setNit] = useState('');
    const [commercialName, setCommercialName] = useState('');
    const [legalName, setLegalName] = useState('');
    const [bankId, setBankId] = useState('');
    const [accountNumber, setAccountNumber] = useState('');
    const [accountType, setAccountType] = useState('');
    const [brebKey, setBrebKey] = useState('');
    const [qrFile, setQrFile] = useState<File | null>(null);

    // #154 — Evidencia de propiedad obligatoria.
    const [proofFile, setProofFile] = useState<File | null>(null);

    const handleSubmitStep1 = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (!acceptContract) {
            setErrors({ accept_contract: 'Debes aceptar el Contrato de Servicio para continuar.' });
            return;
        }

        setStep(2);
    };

    const handleSubmitStep2 = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (!businessType) {
            setErrors({ main_branch_business_type: 'Elige el tipo de negocio para tu primera sede.' });
            return;
        }

        setStep(3);
    };

    const handleSubmitStep3 = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (!proofFile) {
            setErrors({ proof_document: 'Debes adjuntar el documento de propiedad de la empresa.' });
            return;
        }

        if (!PROOF_ACCEPTED_MIMES.includes(proofFile.type)) {
            setErrors({ proof_document: 'Formato no permitido. Acepta PDF, Word (.doc, .docx) o imagen (JPG/PNG).' });
            return;
        }

        if (proofFile.size > PROOF_MAX_BYTES) {
            setErrors({ proof_document: 'El documento supera el tamaño máximo de 10 MB.' });
            return;
        }

        setProcessing(true);

        try {
            const formData = new FormData();
            formData.append('nit', nit);
            formData.append('commercial_name', commercialName);
            formData.append('legal_name', legalName);
            formData.append('bank_id', bankId);
            formData.append('account_number', accountNumber);
            formData.append('account_type', accountType);
            formData.append('breb_key', brebKey);
            formData.append('accept_contract', acceptContract ? '1' : '0');
            formData.append('main_branch_business_type', businessType);
            formData.append('proof_document', proofFile);
            if (qrFile) {
                formData.append('qr_code', qrFile);
            }
            // #246 — promo code desde URL (`?promo=`). El backend lo valida y
            // si es inválido, NO bloquea el enrollment (best-effort).
            if (hasValidPromo && promoSlug !== null) {
                formData.append('promo_code', promoSlug);
            }

            const response = await apiFetch('/api/v1/enrollment/company', {
                method: 'POST',
                body: formData,
            });
            const data = await response.json();
            if (!response.ok) {
                setErrors(data.errors ?? { general: data.message ?? 'No se pudo registrar la empresa.' });
                return;
            }

            setCompletedCompany(data.company);
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

    if (completedCompany) {
        return (
            <>
                <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8">
                    <div className="w-full max-w-3xl space-y-6 sm:space-y-8 md:space-y-10">
                        <HeroPanel
                            eyebrow="Registro completado"
                            footer={
                                <Button
                                    variant="dark"
                                    size="lg"
                                    onClick={async () => {
                                        // La empresa recién creada cambia el contexto del
                                        // servidor: esperamos el refetch del bootstrap antes
                                        // de volver al inicio para que la ruta resuelva el
                                        // destino correcto con el estado fresco (sin esperar,
                                        // el guard monta con el cache viejo y puede rebotar).
                                        await reloadContext();
                                        navigate(route('home'));
                                    }}
                                    className="w-full sm:w-auto"
                                >
                                    Volver al inicio
                                </Button>
                            }
                        >
                            <HeroHeadline
                                title={
                                    <>
                                        Listo, ya quedó
                                        <br />
                                        en revisión.
                                    </>
                                }
                                description={
                                    <>
                                        Tu empresa <span className="font-semibold">{completedCompany.commercial_name}</span> fue registrada
                                        correctamente. Nuestro equipo revisará tu documento de propiedad antes de activar la operación.
                                    </>
                                }
                                className="text-accent-foreground"
                            />
                            <div className="bg-background/30 flex items-start gap-3 rounded-2xl p-5">
                                <Clock className="mt-0.5 h-5 w-5 shrink-0" />
                                <div className="text-sm">
                                    <p className="font-semibold">Pendiente de verificación</p>
                                    <p className="mt-1 opacity-80">
                                        Recibimos tu documento de propiedad. Te avisaremos por correo cuando se apruebe — normalmente en menos de 24
                                        horas hábiles. Hasta entonces no podrás operar esta empresa.
                                    </p>
                                </div>
                            </div>
                        </HeroPanel>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8">
                <div className="w-full max-w-6xl">
                    <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                        {/* Columna izquierda: logo + hero + wizard */}
                        <div className="flex flex-col gap-6 sm:gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                            <img src="/images/logo-black-font.svg" alt={name} className="block h-8 w-auto md:h-10 dark:hidden" />
                            <img src="/images/logo-white-font.svg" alt={name} className="hidden h-8 w-auto md:h-10 dark:block" />

                            <HeroHeadline
                                eyebrow="Registro"
                                title={
                                    <>
                                        Registra tu
                                        <br />
                                        empresa.
                                    </>
                                }
                                description="Acepta el contrato, completa los datos del establecimiento y adjunta la evidencia de propiedad. Tu cuenta queda en revisión hasta que el equipo apruebe la documentación."
                            />

                            <WizardStepIndicator steps={WIZARD_STEPS} currentStep={step} className="sm:self-start" />

                            {errors.general && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{errors.general}</AlertDescription>
                                </Alert>
                            )}

                            {/* #246 — Alert para promo inválido (sin bloquear enrollment). */}
                            {promoSlug !== null && !promoLoading && promoInvalidReason !== null && (
                                <Alert variant="default">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>
                                        El código <strong>{promoSlug}</strong> no es válido. Puedes continuar con el plan default — el descuento no se
                                        aplicará.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {step === 1 && (
                                <form noValidate className="flex flex-col gap-6" onSubmit={handleSubmitStep1}>
                                    <p className="text-muted-foreground text-sm">
                                        Antes de registrar tu empresa debes leer y aceptar el Contrato de Servicio que rige la relación con
                                        flexyflow.
                                    </p>
                                    <div className="border-border bg-muted/30 rounded-lg border p-4">
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="accept_contract"
                                                checked={acceptContract}
                                                onCheckedChange={(checked) => setAcceptContract(checked === true)}
                                            />
                                            <div className="grid gap-1">
                                                <Label htmlFor="accept_contract" className="cursor-pointer leading-snug">
                                                    Acepto el{' '}
                                                    <a
                                                        href={contractUrl ?? '#'}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary hover:text-primary/80 focus:ring-primary/40 font-medium underline underline-offset-2 focus:ring-2 focus:outline-none"
                                                    >
                                                        Contrato de Servicio
                                                    </a>
                                                </Label>
                                                <InputError message={errors.accept_contract} />
                                            </div>
                                        </div>
                                    </div>
                                    <Button type="submit" className="w-full sm:w-auto sm:self-start" disabled={!acceptContract}>
                                        Continuar
                                    </Button>
                                </form>
                            )}

                            {step === 2 && (
                                <form noValidate className="flex flex-col gap-6" onSubmit={handleSubmitStep2}>
                                    <div className="grid gap-3">
                                        <p className="text-muted-foreground text-sm">
                                            Elige cómo opera tu primera sede. Esto define qué módulos y áreas de preparación quedan habilitados de
                                            entrada (puedes ajustarlos después). Pasa el cursor sobre el ícono <span aria-hidden>ℹ</span> de cada
                                            opción para ver el detalle.
                                        </p>
                                        <BusinessTypeSelector value={businessType} onChange={setBusinessType} />
                                        <InputError message={errors.main_branch_business_type} />
                                    </div>
                                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setStep(1)}
                                            className="w-full sm:w-auto"
                                        >
                                            Volver
                                        </Button>
                                        <Button type="submit" className="w-full sm:w-auto" disabled={!businessType}>
                                            Continuar
                                        </Button>
                                    </div>
                                </form>
                            )}

                            {step === 3 && (
                                <form noValidate className="flex flex-col gap-6" onSubmit={handleSubmitStep3}>
                                    <div className="grid gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="nit">NIT *</Label>
                                            <Input
                                                id="nit"
                                                type="text"
                                                autoFocus
                                                maxLength={20}
                                                value={nit}
                                                onChange={(e) => setNit(e.target.value.replace(/[^0-9-]/g, ''))}
                                                placeholder="Ej: 900123456-7"
                                            />
                                            <InputError message={errors.nit} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="commercial_name">Nombre comercial *</Label>
                                            <Input
                                                id="commercial_name"
                                                type="text"
                                                value={commercialName}
                                                onChange={(e) => setCommercialName(e.target.value)}
                                                placeholder="Ej: Mi empresa S.A.S."
                                            />
                                            <InputError message={errors.commercial_name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="legal_name">Razón social *</Label>
                                            <Input
                                                id="legal_name"
                                                type="text"
                                                value={legalName}
                                                onChange={(e) => setLegalName(e.target.value)}
                                                placeholder="Ej: El Sabor S.A.S."
                                            />
                                            <InputError message={errors.legal_name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="bank">Banco *</Label>
                                            <Select value={bankId} onValueChange={setBankId}>
                                                <SelectTrigger id="bank">
                                                    <SelectValue placeholder="Selecciona un banco" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableBanks.map((b) => (
                                                        <SelectItem key={b.id} value={String(b.id)}>
                                                            {b.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.bank_id} />
                                        </div>
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-3">
                                            <div className="grid gap-2">
                                                <Label htmlFor="account_number">Número de cuenta *</Label>
                                                <Input
                                                    id="account_number"
                                                    type="text"
                                                    inputMode="numeric"
                                                    maxLength={30}
                                                    value={accountNumber}
                                                    onChange={(e) => setAccountNumber(e.target.value.replace(/[^0-9]/g, ''))}
                                                    placeholder="Ej: 1234567890"
                                                />
                                                <InputError message={errors.account_number} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="account_type">Tipo de cuenta *</Label>
                                                <Select value={accountType} onValueChange={setAccountType}>
                                                    <SelectTrigger id="account_type">
                                                        <SelectValue placeholder="Tipo" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="ahorros">Ahorros</SelectItem>
                                                        <SelectItem value="corriente">Corriente</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <InputError message={errors.account_type} />
                                            </div>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="breb_key">
                                                Llave BREB <span className="text-muted-foreground text-xs font-normal">(opcional)</span>
                                            </Label>
                                            <Input
                                                id="breb_key"
                                                type="text"
                                                maxLength={255}
                                                value={brebKey}
                                                onChange={(e) => setBrebKey(e.target.value.replace(/[^A-Za-z0-9@.-]/g, ''))}
                                                placeholder="Llave de interoperabilidad"
                                            />
                                            <InputError message={errors.breb_key} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>
                                                QR de pagos <span className="text-muted-foreground text-xs font-normal">(opcional)</span>
                                            </Label>
                                            <FileDropzone
                                                value={qrFile}
                                                onChange={setQrFile}
                                                accept="image/png,image/jpeg,image/jpg"
                                                label="Arrastra tu imagen aquí"
                                                helperText="o haz clic para seleccionar — PNG, JPG (máx. 2 MB)"
                                                showImagePreview
                                            />
                                            <InputError message={errors.qr_code} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>
                                                Documento de propiedad <span className="text-destructive">*</span>
                                            </Label>
                                            <p className="text-muted-foreground text-xs">
                                                Adjunta un documento que acredite que eres el propietario o representante legal de la empresa
                                                (cámara de comercio, RUT, cédula del representante, etc.). Tu cuenta queda en revisión hasta que el
                                                equipo de flexyflow valide la evidencia.
                                            </p>
                                            <FileDropzone
                                                value={proofFile}
                                                onChange={setProofFile}
                                                accept={PROOF_ACCEPT_ATTR}
                                                label="Arrastra el documento aquí"
                                                helperText="o haz clic para seleccionar — PDF, Word, JPG, PNG (máx. 10 MB)"
                                            />
                                            <InputError message={errors.proof_document} />
                                        </div>
                                    </div>
                                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setStep(2)}
                                            disabled={processing}
                                            className="w-full sm:w-auto"
                                        >
                                            Volver
                                        </Button>
                                        <Button type="submit" disabled={processing || !acceptContract || !proofFile} className="w-full sm:w-auto">
                                            {processing && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                                            Registrar empresa
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>

                        {/* Columna derecha: hero panel.
                             - Con ?promo válido: PromoLandingPanel reemplaza todo el aside (#246 decisión #6).
                             - Sin promo o promo inválido: HeroPanel original + PlanInfoBlock debajo. */}
                        <div className="order-last flex flex-col gap-4 md:col-span-5 lg:col-span-5">
                            {hasValidPromo ? (
                                <PromoLandingPanel preview={promoPreview} loading={promoLoading} />
                            ) : (
                                <>
                                    <HeroPanel
                                        eyebrow="Diseñado para tu empresa"
                                        footer={
                                            <div className="space-y-3 text-sm">
                                                <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                                    <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
                                                    <span>
                                                        Tus datos bancarios se cifran y solo se usan para configurar la pasarela de cobros de la
                                                        empresa.
                                                    </span>
                                                </div>
                                                <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                                                    <span>
                                                        El documento de propiedad lo revisa una persona, no un robot. Te avisamos por correo cuando se
                                                        apruebe.
                                                    </span>
                                                </div>
                                            </div>
                                        }
                                    >
                                        <HeroPanelStats stats={HERO_HIGHLIGHTS} />
                                    </HeroPanel>
                                    <PlanInfoBlock plan={defaultPlan} loading={defaultPlanLoading} />
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
