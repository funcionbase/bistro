import { Button } from '@/components/ui/button';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel } from '@/components/ui/hero-panel';
import { companyStatusLabel } from '@/lib/company-status';
import { useSharedData } from '@/lib/shared-data';
import { useLogout } from '@/lib/use-logout';
import { Clock, FileText, ShieldCheck, XCircle } from 'lucide-react';

/**
 * Pantalla que ve el propietario cuando su empresa está en
 * `pending_activation` (esperando verificación) o `rejected`. El middleware
 * `EnsureCompanyVerified` redirige aquí cualquier intento de operar mientras
 * la empresa no esté en `verified`/`active`.
 *
 * Rediseño v3.4 — alineado con onboarding: hero 2-col (headline + panel
 * lime) en md+, stack en mobile. Tokens semánticos de status (warning/
 * critical) en lugar de hex hardcoded (`bg-rose-50` / `bg-amber-50`).
 */
export default function CompanyUnderReview() {
    const { name, activeCompany } = useSharedData();
    const logout = useLogout();
    const company = {
        nit: activeCompany?.nit ?? '',
        name: activeCompany?.name ?? '',
        status: activeCompany?.status ?? 'pending_activation',
        label: activeCompany ? companyStatusLabel(activeCompany.status) : '',
    };
    const isRejected = company.status === 'rejected';

    const Icon = isRejected ? XCircle : Clock;
    const tone = isRejected ? 'critical' : 'warning';
    const calloutTitle = isRejected ? 'Verificación rechazada' : 'Estamos revisando tu solicitud';
    const calloutBody = isRejected
        ? 'La evidencia que adjuntaste no pudo ser validada. Contacta al soporte de bistro para ver los detalles y opciones de reenvío.'
        : 'Tu empresa quedará habilitada en cuanto el equipo de bistro valide el documento de propiedad que adjuntaste. Recibirás una notificación cuando esté lista.';

    const iconWrapperClass =
        tone === 'critical'
            ? 'bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)] ring-[color:var(--color-status-critical)]/20'
            : 'bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)] ring-[color:var(--color-status-warning)]/20';

    const calloutClass =
        tone === 'critical'
            ? 'border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)]'
            : 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]';

    return (
        <>
            <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8">
                <div className="w-full max-w-6xl">
                    <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                        {/* Columna izquierda: logo + hero + estado */}
                        <div className="flex flex-col gap-6 sm:gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                            <img src="/images/logo-black-font.svg" alt={name} className="block h-8 w-auto md:h-10 dark:hidden" />
                            <img src="/images/logo-white-font.svg" alt={name} className="hidden h-8 w-auto md:h-10 dark:block" />

                            <div className="flex items-center gap-4">
                                <span
                                    className={`flex h-14 w-14 shrink-0 items-center justify-center rounded-full ring-4 ${iconWrapperClass}`}
                                    aria-hidden="true"
                                >
                                    <Icon className="h-7 w-7" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-muted-foreground text-[11px] tracking-[0.18em] uppercase">NIT {company.nit}</p>
                                    <h2 className="text-foreground truncate text-xl font-semibold sm:text-2xl">{company.name}</h2>
                                </div>
                            </div>

                            <HeroHeadline
                                size="lg"
                                eyebrow={tone === 'critical' ? 'Verificación rechazada' : 'En revisión'}
                                title={
                                    isRejected ? (
                                        <>
                                            Tu evidencia
                                            <br />
                                            no pudo validarse.
                                        </>
                                    ) : (
                                        <>
                                            Estamos revisando
                                            <br />
                                            tu empresa.
                                        </>
                                    )
                                }
                                description={calloutBody}
                            />

                            <div className={`flex items-start gap-3 rounded-2xl border px-5 py-4 ${calloutClass}`}>
                                <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" />
                                <div>
                                    <p className="text-sm font-semibold">{calloutTitle}</p>
                                    <p className="mt-1 text-sm opacity-90">{calloutBody}</p>
                                </div>
                            </div>

                            <div className="text-muted-foreground flex items-center gap-2 text-xs">
                                <FileText className="h-3.5 w-3.5" />
                                <span>Estado actual: {company.label}</span>
                            </div>

                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
                                <Button variant="outline" className="w-full sm:w-auto" onClick={() => logout()}>
                                    Cerrar sesión
                                </Button>
                            </div>
                        </div>

                        {/* Columna derecha: panel lime con info adicional */}
                        <HeroPanel
                            eyebrow={isRejected ? 'Siguiente paso' : 'Mientras tanto'}
                            className="order-last md:col-span-5 lg:col-span-5"
                            footer={
                                <div className="space-y-3 text-sm">
                                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                        <Clock className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>
                                            Revisamos cada solicitud en menos de 24 horas hábiles. Te avisamos por correo apenas quede activa.
                                        </span>
                                    </div>
                                    <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>La revisión humana protege a todas las empresas registradas — no la salteamos por nadie.</span>
                                    </div>
                                </div>
                            }
                        >
                            <p className="text-base leading-relaxed md:text-lg">
                                {isRejected
                                    ? 'Escribinos a hello@funcionbase.com y te ayudamos a re-enviar la documentación correcta.'
                                    : 'Mientras se aprueba la documentación, no podrás operar esta empresa. Si necesitas apurar la revisión, escribinos a hello@funcionbase.com.'}
                            </p>
                        </HeroPanel>
                    </div>
                </div>
            </div>
        </>
    );
}
