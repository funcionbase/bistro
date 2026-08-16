import AppLogoIcon from '@/components/app-logo-icon';
import { useApiForm } from '@/hooks/use-api-form';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel } from '@/components/ui/hero-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { apiFetch } from '@/lib/api';
import { ApiError, apiClient } from '@/lib/api-client';
import { AlertCircle, Loader2, MapPin, Phone, QrCode, User, Users, UtensilsCrossed } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

interface TableJoinContext {
    qrToken: string;
    table: { number: string; capacity: number };
    branch: { name: string; city: string | null };
    company: {
        name: string;
        logo_url: string | null;
        primary_color: string;
    };
    phoneRegexHint: string;
}

/** Respuesta de `GET /api/v1/public/table/{qrToken}` (resource + flag). */
interface TableJoinContextResponse {
    data: TableJoinContext;
    already_joined: boolean;
}

type LoadState =
    | { kind: 'loading' }
    | { kind: 'ready'; context: TableJoinContext }
    | { kind: 'error'; message: string };

/**
 * Pantalla pública de unión a mesa (#191).
 *
 * Sin auth. El cliente escanea el QR físico de la mesa, llega a `/t/{qrToken}`
 * y queda identificado vía cookie firmada `tdt_*` (httpOnly, 12h TTL).
 *
 * Migrada a SPA standalone (#191): el `qrToken` se lee de la URL con
 * `useParams` y el contexto (mesa, sede, branding) se trae con fetch al
 * endpoint `GET /api/v1/public/table/{qrToken}`. Si el dispositivo ya tiene
 * una sesión activa en esta mesa (`already_joined`), redirige al menú sin
 * volver a pedir nombre.
 */
export default function TableJoinPage() {
    const { qrToken } = useParams<{ qrToken: string }>();
    const navigate = useNavigate();
    const [state, setState] = useState<LoadState>({ kind: 'loading' });

    useEffect(() => {
        if (!qrToken) {
            setState({ kind: 'error', message: 'El enlace de la mesa no es válido. Vuelve a escanear el QR.' });
            return;
        }

        let cancelled = false;
        const controller = new AbortController();

        async function load(token: string) {
            try {
                const json = await apiClient.get<TableJoinContextResponse>(
                    `/api/v1/public/table/${encodeURIComponent(token)}`,
                    { signal: controller.signal },
                );
                if (cancelled) {
                    return;
                }
                if (json.already_joined) {
                    // Ya hay sesión activa en este dispositivo: directo al menú.
                    navigate(`/t/${encodeURIComponent(token)}/menu`, { replace: true });
                    return;
                }
                setState({ kind: 'ready', context: json.data });
            } catch (err) {
                if (cancelled || (err instanceof DOMException && err.name === 'AbortError')) {
                    return;
                }
                const message =
                    err instanceof ApiError && err.status === 404
                        ? 'No encontramos esta mesa. Verifica el QR o pídele ayuda a un mesero.'
                        : 'No pudimos cargar la mesa. Revisa tu conexión e intenta de nuevo.';
                setState({ kind: 'error', message });
            }
        }

        void load(qrToken);

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [qrToken, navigate]);

    if (state.kind === 'loading') {
        return <TableJoinSkeleton />;
    }

    if (state.kind === 'error') {
        return (
            <div className="bg-background flex min-h-svh items-center justify-center p-4 md:p-8">
                <div className="w-full max-w-2xl">
                    <EditorialEmpty
                        eyebrow="Mesa con QR"
                        icon={<QrCode className="size-10" aria-hidden="true" />}
                        title="No pudimos abrir la mesa"
                        description={state.message}
                    />
                </div>
            </div>
        );
    }

    return <TableJoinForm context={state.context} />;
}

/**
 * Esqueleto de la pantalla de unión mientras se resuelve el contexto del QR.
 * Replica el grid editorial 7/5 para evitar saltos de layout al hidratar.
 */
function TableJoinSkeleton() {
    return (
        <div className="bg-background flex min-h-svh items-center justify-center p-3 sm:p-4 md:p-8" aria-busy="true" aria-label="Cargando mesa">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    <div className="flex flex-col gap-8 md:col-span-7 md:gap-10">
                        <Skeleton className="h-9 w-36" />
                        <div className="space-y-3">
                            <Skeleton className="h-4 w-24" />
                            <Skeleton className="h-12 w-3/4" />
                            <Skeleton className="h-12 w-2/3" />
                        </div>
                        <div className="max-w-md space-y-5">
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-11 w-40" />
                        </div>
                    </div>
                    <Skeleton className="min-h-72 md:col-span-5" />
                </div>
            </div>
        </div>
    );
}

/**
 * Formulario de unión propiamente dicho — recibe el contexto ya resuelto.
 *
 * Sigue el shell editorial de welcome / company-selector / enrollment:
 * grid 7/5 con HeroHeadline + form a la izquierda y HeroPanel lime con el
 * branding de la empresa a la derecha. El `primary_color` de la empresa
 * va en acentos puntuales (nombre de la empresa) — el lime del DS manda
 * en el panel hero por consistencia visual de marca bistro.
 */
function TableJoinForm({ context }: { context: TableJoinContext }) {
    const { qrToken, table, branch, company, phoneRegexHint } = context;
    const navigate = useNavigate();
    const appName = 'bistro';
    const accent = useMemo(
        () => (/^#[0-9a-fA-F]{6}$/.test(company.primary_color) ? company.primary_color : '#0F172A'),
        [company.primary_color],
    );

    const { data, setData, post, processing, errors } = useApiForm<{
        display_name: string;
        phone: string;
        session?: string;
    }>({
        display_name: '',
        phone: '',
    });

    // Autocompletado del nombre desde Contact existente. Si el celular ya
    // está registrado en la empresa (orden previa, sesión previa de mesa o
    // contacto WhatsApp), se rellena el campo nombre. Sólo si el usuario aún
    // no escribió nada manualmente — respetamos su input.
    const [lookupBusy, setLookupBusy] = useState(false);
    const [autofilled, setAutofilled] = useState(false);
    const lookupTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const lookupAbort = useRef<AbortController | null>(null);
    // Sincroniza el valor actual de display_name al callback de lookup sin
    // forzar el closure a re-crearse en cada keystroke.
    const displayNameRef = useRef(data.display_name);
    useEffect(() => {
        displayNameRef.current = data.display_name;
    }, [data.display_name]);

    const runLookup = useCallback(
        (rawPhone: string) => {
            const digits = rawPhone.replace(/\D/g, '');
            if (digits.length < 7) {
                return;
            }
            lookupAbort.current?.abort();
            const controller = new AbortController();
            lookupAbort.current = controller;
            setLookupBusy(true);
            apiFetch(`/api/v1/public/table/${encodeURIComponent(qrToken)}/contact-lookup?phone=${encodeURIComponent(rawPhone)}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((res) => (res.ok ? res.json() : null))
                .then((json: { name: string | null } | null) => {
                    if (!json || !json.name) return;
                    if (displayNameRef.current.trim() !== '') return;
                    setData('display_name', json.name);
                    setAutofilled(true);
                })
                .catch(() => {
                    // Silencioso — autocompletar es best-effort.
                })
                .finally(() => {
                    if (lookupAbort.current === controller) {
                        setLookupBusy(false);
                    }
                });
        },
        [qrToken, setData],
    );

    const scheduleLookup = useCallback(
        (rawPhone: string) => {
            if (lookupTimer.current) {
                clearTimeout(lookupTimer.current);
            }
            lookupTimer.current = setTimeout(() => runLookup(rawPhone), 3000);
        },
        [runLookup],
    );

    useEffect(() => {
        return () => {
            if (lookupTimer.current) {
                clearTimeout(lookupTimer.current);
            }
            lookupAbort.current?.abort();
        };
    }, []);

    const handlePhoneChange = (value: string) => {
        // Solo dígitos. Cualquier otro caracter (espacios, guiones, +, letras
        // si el teclado las inyecta) se descarta antes de tocar el state. El
        // backend igual valida con regex.
        const digitsOnly = value.replace(/\D/g, '');
        setData('phone', digitsOnly);
        scheduleLookup(digitsOnly);
    };

    const handleDisplayNameChange = (value: string) => {
        // Alfabeto español: letras a-z (con tildes), ñ y espacios. Nada de
        // dígitos, símbolos, apóstrofos ni guiones. El usuario nuevo escribió
        // este campo manual → reset del flag de autocompletado para que no
        // salga el helper "Te reconocimos por tu celular" si cambia su nombre.
        const filtered = value.replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]/g, '');
        setAutofilled(false);
        setData('display_name', filtered);
    };

    const handlePhoneBlur = () => {
        if (lookupTimer.current) {
            clearTimeout(lookupTimer.current);
        }
        runLookup(data.phone);
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        // El POST crea/une al comensal y setea la cookie `tdt_*`. Al volver
        // OK, navegamos al menú — antes lo hacía el redirect server-side de
        // Inertia, ahora lo hace el cliente.
        void post(`/api/v1/public/table/${encodeURIComponent(qrToken)}/join`, {
            onSuccess: () => navigate(`/t/${encodeURIComponent(qrToken)}/menu`),
        });
    };

    return (
        <div className="bg-background flex min-h-svh items-center justify-center p-3 sm:p-4 md:p-8">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    {/* Columna izquierda: logo bistro + headline + form */}
                    <div className="flex flex-col gap-8 md:col-span-7 md:gap-10">
                        <img src="/images/logo-black-font.svg" alt={appName} className="block h-9 w-auto md:h-10 dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt={appName} className="hidden h-9 w-auto md:h-10 dark:block" />

                        <HeroHeadline
                            eyebrow="Tu mesa"
                            title={
                                <>
                                    Hola, <br />
                                    ¿quién se une?
                                </>
                            }
                            description={`Tu pedido y tu cuenta quedan separados del resto de la mesa. Cuéntanos quién eres para empezar a pedir en ${company.name}.`}
                            size="xl"
                        />

                        {errors.session && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{errors.session}</AlertDescription>
                            </Alert>
                        )}

                        <form noValidate onSubmit={handleSubmit} className="max-w-md space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="phone" className="flex items-center gap-1.5">
                                    <Phone className="h-3.5 w-3.5" />
                                    Celular
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="phone"
                                        type="tel"
                                        autoComplete="tel-national"
                                        inputMode="numeric"
                                        pattern="[0-9]*"
                                        maxLength={15}
                                        value={data.phone}
                                        onChange={(e) => handlePhoneChange(e.target.value)}
                                        onBlur={handlePhoneBlur}
                                        onKeyDown={(e) => {
                                            // Bloquear teclas no-numéricas a nivel keydown para
                                            // que ni siquiera lleguen al state. Permitimos las
                                            // teclas de navegación/edición habituales.
                                            if (e.key.length === 1 && !/[0-9]/.test(e.key) && !e.ctrlKey && !e.metaKey) {
                                                e.preventDefault();
                                            }
                                        }}
                                        placeholder={phoneRegexHint}
                                        required
                                        aria-invalid={!!errors.phone}
                                        className={lookupBusy ? 'pr-9' : undefined}
                                    />
                                    {lookupBusy && (
                                        <Loader2
                                            className="text-muted-foreground absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin"
                                            aria-hidden
                                        />
                                    )}
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    10 dígitos, sin el +57. Lo usamos solo para contactarte sobre este pedido.
                                </p>
                                {errors.phone && <p className="text-destructive text-xs">{errors.phone}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="display_name" className="flex items-center gap-1.5">
                                    <User className="h-3.5 w-3.5" />
                                    Tu nombre
                                </Label>
                                <Input
                                    id="display_name"
                                    type="text"
                                    autoComplete="given-name"
                                    inputMode="text"
                                    pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+"
                                    maxLength={80}
                                    value={data.display_name}
                                    onChange={(e) => handleDisplayNameChange(e.target.value)}
                                    placeholder="Ej: María"
                                    required
                                    aria-invalid={!!errors.display_name}
                                />
                                {autofilled && data.display_name !== '' && (
                                    <p className="text-muted-foreground text-xs">
                                        Te reconocimos por tu celular. ¿Eres tú? Si no, edita el nombre.
                                    </p>
                                )}
                                {errors.display_name && <p className="text-destructive text-xs">{errors.display_name}</p>}
                            </div>

                            <Button type="submit" size="lg" className="w-full md:w-auto" disabled={processing}>
                                {processing ? 'Conectando…' : 'Unirme a la mesa'}
                            </Button>
                        </form>

                        <p className="text-muted-foreground max-w-md text-xs">
                            Al continuar aceptas que registremos tu nombre y celular en la agenda de la empresa para operar este pedido.
                        </p>
                    </div>

                    {/* Columna derecha: HeroPanel lime con branding de la empresa */}
                    <HeroPanel
                        eyebrow="Estás en"
                        className="p-5 md:col-span-5 md:p-8 lg:p-10"
                        footer={
                            <div className="space-y-3 text-sm leading-relaxed opacity-80">
                                <p>
                                    Si más personas están en esta mesa, también pueden unirse escaneando el mismo QR — cada uno pide y paga lo
                                    suyo.
                                </p>
                            </div>
                        }
                    >
                        <div className="space-y-6">
                            <div className="flex items-center gap-3">
                                {company.logo_url ? (
                                    <div
                                        className="bg-background border-foreground/10 size-12 shrink-0 overflow-hidden rounded-2xl border sm:size-14"
                                        aria-hidden
                                    >
                                        <img
                                            src={company.logo_url}
                                            alt={company.name}
                                            className="size-full object-contain p-1.5"
                                            loading="eager"
                                            decoding="async"
                                        />
                                    </div>
                                ) : (
                                    <div
                                        className="bg-foreground text-background border-foreground/10 flex size-12 shrink-0 items-center justify-center rounded-2xl border sm:size-14"
                                        aria-label="bistro"
                                    >
                                        <AppLogoIcon className="size-6 fill-current sm:size-7" />
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <p className="text-[10px] font-semibold tracking-[0.18em] uppercase opacity-70">Empresa</p>
                                    <p
                                        className="font-brand truncate text-xl leading-tight font-medium tracking-tight sm:text-2xl md:text-3xl"
                                        style={{ color: accent }}
                                        title={company.name}
                                    >
                                        {company.name}
                                    </p>
                                </div>
                            </div>

                            <div className="border-foreground/10 space-y-2 border-t pt-4 text-sm">
                                <div className="flex items-center gap-2 opacity-85">
                                    <MapPin className="size-4 shrink-0" aria-hidden />
                                    <span className="truncate">
                                        {branch.name}
                                        {branch.city ? ` · ${branch.city}` : ''}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 opacity-85">
                                    <UtensilsCrossed className="size-4 shrink-0" aria-hidden />
                                    <span>Capacidad sugerida: {table.capacity} personas</span>
                                </div>
                            </div>

                            <div className="border-foreground/10 flex items-center justify-between gap-3 border-t pt-4">
                                <div className="min-w-0">
                                    <p className="text-[10px] font-semibold tracking-[0.18em] uppercase opacity-70">Tu mesa</p>
                                    <p className="font-brand truncate text-4xl leading-none font-medium tabular-nums sm:text-5xl md:text-6xl">
                                        {table.number}
                                    </p>
                                </div>
                                <span
                                    aria-hidden
                                    className="bg-foreground/10 text-foreground inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                >
                                    <Users className="size-3.5" />
                                    Sesión grupal
                                </span>
                            </div>
                        </div>
                    </HeroPanel>
                </div>
            </div>
        </div>
    );
}
