import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WizardStepIndicator } from '@/components/ui/wizard-step-indicator';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { reloadContext } from '@/lib/navigate-compat';
import { route } from '@/lib/route-compat';
import { setToken } from '@/lib/token';
import { useDocumentTitle } from '@/lib/use-document-title';
import { AlertCircle, BadgeCheck, FileLock, LoaderCircle, MailCheck, UserRound } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useNavigate } from 'react-router-dom';

interface FieldErrors {
    first_name?: string;
    last_name?: string;
    cedula?: string;
    accept_tos?: string;
    accept_privacy?: string;
    general?: string;
}

const WIZARD_STEPS = [{ label: 'Datos personales' }, { label: 'Aceptaciones' }, { label: 'Empresa' }] as const;

const HERO_HIGHLIGHTS: Array<{ label: string; value: string }> = [
    { label: 'Acceso', value: 'Con Google' },
    { label: 'Plan', value: 'Gratis hoy' },
    { label: 'Soporte', value: 'En español' },
];

export default function EnrollmentUser() {
    useDocumentTitle('Completar perfil');
    const navigate = useNavigate();
    const name = 'bistro';
    const legalUrls = useBootstrap().data?.legalUrls;

    const [step, setStep] = useState(1);
    const [firstName, setFirstName] = useState('');
    const [lastName, setLastName] = useState('');
    const [cedula, setCedula] = useState('');
    const [acceptTos, setAcceptTos] = useState(false);
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [processing, setProcessing] = useState(false);
    // Cuando la cédula ya pertenece a otra cuenta, el backend envía un enlace de
    // recuperación al correo viejo y devuelve este aviso (no es un error).
    const [recoveryNotice, setRecoveryNotice] = useState<string | null>(null);

    function validateStep1(): boolean {
        const newErrors: FieldErrors = {};

        if (!firstName.trim()) {
            newErrors.first_name = 'El nombre es obligatorio.';
        }

        if (!lastName.trim()) {
            newErrors.last_name = 'El apellido es obligatorio.';
        }

        if (!cedula.trim()) {
            newErrors.cedula = 'La cédula es obligatoria.';
        } else if (!/^\d{1,20}$/.test(cedula.trim())) {
            newErrors.cedula = 'La cédula debe contener solo números (máx. 20 dígitos).';
        }

        setErrors(newErrors);

        return Object.keys(newErrors).length === 0;
    }

    function handleNextStep(e: React.FormEvent) {
        e.preventDefault();

        if (validateStep1()) {
            setStep(2);
        }
    }

    const handleSubmit: FormEventHandler = async (e) => {
        e.preventDefault();
        setErrors({});

        if (!acceptTos) {
            setErrors((prev) => ({ ...prev, accept_tos: 'Debes aceptar los Términos y Condiciones.' }));
            return;
        }

        if (!acceptPrivacy) {
            setErrors((prev) => ({ ...prev, accept_privacy: 'Debes aceptar la Política de Privacidad.' }));
            return;
        }

        setProcessing(true);

        try {
            const response = await apiFetch('/api/v1/enrollment/user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    // Saneo cliente antes de enviar (strip HTML, control chars,
                    // colapsa whitespace, cap 100). El backend revalida con
                    // SafePlainText(maxBytes:100) — fuente de verdad.
                    first_name: sanitizePlainText(firstName, 100, false),
                    last_name: sanitizePlainText(lastName, 100, false),
                    cedula: cedula.trim(),
                    accept_tos: true,
                    accept_privacy: true,
                }),
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 409 && data.status === 'cedula_belongs_to_other_account') {
                    setRecoveryNotice(
                        data.message ?? 'Esta cédula ya tiene una cuenta. Te enviamos un enlace a tu correo anterior para confirmar el cambio a este correo.',
                    );
                    return;
                }
                if (response.status === 422 && data.errors) {
                    const fieldErrors: FieldErrors = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        fieldErrors[field as keyof FieldErrors] = messages[0];
                    }
                    setErrors(fieldErrors);
                    if (Object.keys(fieldErrors).some((k) => ['first_name', 'last_name', 'cedula'].includes(k))) {
                        setStep(1);
                    }
                } else {
                    setErrors({ general: data.message ?? 'Ocurrió un error. Intenta de nuevo.' });
                }
                return;
            }

            if (data.authenticated || data.token) setToken('present');
            // El enrollment cambia el contexto del servidor (perfil del usuario
            // ya `active`). El guard de /enrollment/company decide con
            // `needsProfileCompletion` del bootstrap; si navegamos con el cache
            // viejo (usuario aún pending) rebota de vuelta acá. Esperamos el
            // refetch del bootstrap ANTES de navegar para montar el destino con
            // el estado fresco.
            await reloadContext();
            if (data.enrollment_step === 'company') {
                navigate(route('enrollment.company'));
            } else {
                navigate(route('dashboard'));
            }
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

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
                                eyebrow="Tu perfil"
                                title={
                                    <>
                                        Completa
                                        <br />
                                        tu cuenta.
                                    </>
                                }
                                description="Necesitamos algunos datos personales para crear tu cuenta y registrar tus aceptaciones legales. Luego pasarás a registrar la empresa."
                            />

                            <WizardStepIndicator steps={WIZARD_STEPS} currentStep={step} className="sm:self-start" />

                            {errors.general && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{errors.general}</AlertDescription>
                                </Alert>
                            )}

                            {recoveryNotice && (
                                <Alert variant="safe">
                                    <MailCheck className="h-4 w-4" />
                                    <AlertDescription>
                                        {recoveryNotice} Abre ese correo y confirma el cambio para entrar con esta cuenta. El enlace vence en 1 hora.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {!recoveryNotice && step === 1 && (
                                <form noValidate className="flex flex-col gap-6" onSubmit={handleNextStep} autoComplete="off">
                                    <div className="grid gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="first_name">Nombres *</Label>
                                            <Input
                                                id="first_name"
                                                type="text"
                                                autoFocus
                                                autoComplete="given-name"
                                                maxLength={100}
                                                value={firstName}
                                                onChange={(e) => setFirstName(e.target.value)}
                                                placeholder="Ej: Juan Carlos"
                                            />
                                            <InputError message={errors.first_name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="last_name">Apellidos *</Label>
                                            <Input
                                                id="last_name"
                                                type="text"
                                                autoComplete="family-name"
                                                maxLength={100}
                                                value={lastName}
                                                onChange={(e) => setLastName(e.target.value)}
                                                placeholder="Ej: García López"
                                            />
                                            <InputError message={errors.last_name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="cedula">Número de cédula *</Label>
                                            <Input
                                                id="cedula"
                                                type="text"
                                                inputMode="numeric"
                                                value={cedula}
                                                onChange={(e) => setCedula(e.target.value.replace(/\D/g, '').slice(0, 20))}
                                                placeholder="Ej: 1234567890"
                                            />
                                            <InputError message={errors.cedula} />
                                        </div>
                                    </div>

                                    <Button type="submit" className="w-full sm:w-auto sm:self-start">
                                        Continuar
                                    </Button>
                                </form>
                            )}

                            {!recoveryNotice && step === 2 && (
                                <form noValidate className="flex flex-col gap-6" onSubmit={handleSubmit} autoComplete="off">
                                    <p className="text-muted-foreground text-sm">Para continuar, debes aceptar nuestros términos legales.</p>

                                    <div className="border-border bg-muted/30 space-y-4 rounded-lg border p-4">
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="accept_tos"
                                                checked={acceptTos}
                                                onCheckedChange={(checked) => setAcceptTos(checked === true)}
                                            />
                                            <div className="grid gap-1">
                                                <Label htmlFor="accept_tos" className="cursor-pointer leading-snug">
                                                    Acepto los{' '}
                                                    <a
                                                        href={legalUrls?.terms ?? '#'}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary hover:text-primary/80 focus:ring-primary/40 font-medium underline underline-offset-2 focus:ring-2 focus:outline-none"
                                                    >
                                                        Términos y Condiciones
                                                    </a>
                                                </Label>
                                                <InputError message={errors.accept_tos} />
                                            </div>
                                        </div>

                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="accept_privacy"
                                                checked={acceptPrivacy}
                                                onCheckedChange={(checked) => setAcceptPrivacy(checked === true)}
                                            />
                                            <div className="grid gap-1">
                                                <Label htmlFor="accept_privacy" className="cursor-pointer leading-snug">
                                                    Acepto la{' '}
                                                    <a
                                                        href={legalUrls?.privacy ?? '#'}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary hover:text-primary/80 focus:ring-primary/40 font-medium underline underline-offset-2 focus:ring-2 focus:outline-none"
                                                    >
                                                        Política de Privacidad
                                                    </a>
                                                </Label>
                                                <InputError message={errors.accept_privacy} />
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setStep(1)}
                                            disabled={processing}
                                            className="w-full sm:w-auto"
                                        >
                                            Volver
                                        </Button>
                                        <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                                            {processing && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                                            Finalizar y continuar
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>

                        {/* Columna derecha: bloque lime con value props */}
                        <HeroPanel
                            eyebrow="Bienvenido"
                            className="order-last md:col-span-5 lg:col-span-5"
                            footer={
                                <div className="space-y-3 text-sm">
                                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                        <UserRound className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>Tu nombre y cédula sirven para emitir comprobantes y vincular al equipo de la empresa.</span>
                                    </div>
                                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                        <FileLock className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>Tus datos viven cifrados. No los usamos para publicidad ni los compartimos con terceros.</span>
                                    </div>
                                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                        <BadgeCheck className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>Cada aceptación se registra con fecha y versión del documento — auditable cuando lo necesites.</span>
                                    </div>
                                </div>
                            }
                        >
                            <HeroPanelStats stats={HERO_HIGHLIGHTS} />
                        </HeroPanel>
                    </div>
                </div>
            </div>
        </>
    );
}
