import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { OnboardingPageSkeleton } from '@/components/ui/onboarding-page-skeleton';
import { SelectableTile } from '@/components/ui/selectable-tile';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { ApiError, apiClient } from '@/lib/api-client';
import { companyStatusBadgeVariant, companyStatusLabel, isSelectable } from '@/lib/company-status';
import { markLoginIntro } from '@/lib/intro';
import { route } from '@/lib/route-compat';
import { useLogout } from '@/lib/use-logout';
import { type Company } from '@/types';
import { AlertCircle, Building2, Lock, LogOut, ShieldAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useDocumentTitle } from '@/lib/use-document-title';

interface CompanyCardProps {
    company: Company;
    onSelect: (nit: string) => void;
    disabled?: boolean;
    loading?: boolean;
}

interface SelectCompanyResponse {
    default_route?: string;
    authenticated?: boolean;
    token?: string;
}

function CompanyCard({ company, onSelect, disabled = false, loading = false }: CompanyCardProps) {
    const isUnlinked = company.linked === false;
    const isStatusSelectable = isSelectable(company.status);
    const canSelect = !isUnlinked && isStatusSelectable;
    const initial = company.name?.charAt(0) ?? '';

    const tooltipText = isUnlinked
        ? 'No tienes acceso activo a esta empresa.'
        : !isStatusSelectable
          ? 'Esta empresa no está disponible para operar.'
          : undefined;

    return (
        <SelectableTile onClick={() => onSelect(company.nit)} disabled={!canSelect || disabled} disabledTooltip={tooltipText} loading={loading}>
            <div className="relative">
                <div className="bg-primary/10 text-primary flex h-14 w-14 items-center justify-center overflow-hidden rounded-xl text-xl font-bold">
                    {company.logo_url ? <img src={company.logo_url} alt={company.name} className="h-14 w-14 rounded-xl object-cover" /> : initial}
                </div>
                {isUnlinked && (
                    <div
                        className="bg-muted text-muted-foreground absolute -right-1 -bottom-1 flex h-5 w-5 items-center justify-center rounded-full"
                        aria-label="Sin vínculo activo"
                    >
                        <Lock className="h-3 w-3" />
                    </div>
                )}
            </div>

            <div className="w-full space-y-1 text-center">
                <p className="leading-snug font-semibold">{company.name}</p>
                <p className="text-muted-foreground font-mono text-xs tabular-nums">NIT {company.nit}</p>
            </div>

            {isUnlinked ? (
                <Badge variant="secondary">Sin vínculo</Badge>
            ) : (
                <Badge variant={companyStatusBadgeVariant(company.status)}>{companyStatusLabel(company.status)}</Badge>
            )}
        </SelectableTile>
    );
}

/**
 * Selector de empresa — ruta SPA (Fase 2).
 *
 * La data de empresas llega vía useBootstrap() (GET /api/v1/bootstrap).
 * La selección llama /api/v1/auth/select-company y navega al destino
 * (`default_route` del backend) con React Router.
 */
export default function CompanySelectorRoute() {
    useDocumentTitle('Seleccionar empresa');

    const navigate = useNavigate();
    const logout = useLogout();
    const bootstrap = useBootstrap();
    const [selecting, setSelecting] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const companies = useMemo<Company[]>(() => (bootstrap.data?.companies as Company[] | undefined) ?? [], [bootstrap.data]);

    const sortedCompanies = useMemo(() => {
        // Orden: linked primero; dentro de linked, priorizar por status
        // operacional (active > past_due > suspended > otros) para que el
        // dueño que tiene una empresa suspendida demo y otra activa NO entre
        // por accidente a la suspendida.
        const statusPriority: Record<string, number> = {
            active: 0,
            past_due: 1,
            suspended: 2,
        };
        return [...companies].sort((a, b) => {
            const aLinked = a.linked !== false ? 0 : 1;
            const bLinked = b.linked !== false ? 0 : 1;
            if (aLinked !== bLinked) return aLinked - bLinked;
            const aPrio = statusPriority[a.status] ?? 99;
            const bPrio = statusPriority[b.status] ?? 99;
            return aPrio - bPrio;
        });
    }, [companies]);

    const stats = useMemo(() => {
        let linked = 0;
        let active = 0;
        for (const c of companies) {
            if (c.linked !== false) linked += 1;
            if (c.status === 'active') active += 1;
        }
        return { linked, active, unlinked: companies.length - linked };
    }, [companies]);

    const allUnlinked = companies.length > 0 && companies.every((c) => c.linked === false);

    if (bootstrap.isLoading) {
        return <OnboardingPageSkeleton layout="tiles" tiles={4} />;
    }

    if (bootstrap.isError) {
        return (
            <div className="bg-background flex min-h-dvh flex-col items-center justify-center px-4 py-8 md:p-8">
                <div className="w-full max-w-md">
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>No pudimos recuperar tu sesión. Vuelve a iniciar sesión.</AlertDescription>
                    </Alert>
                </div>
            </div>
        );
    }

    async function handleSelect(nit: string) {
        setSelecting(nit);
        setError(null);

        try {
            const data = await apiClient.post<SelectCompanyResponse>('/api/v1/auth/select-company', { nit });
            const target = data.default_route && data.default_route !== '' ? data.default_route : 'dashboard';
            // El select reemplaza la cookie JWT con la empresa elegida. Se
            // navega con carga completa: el intro verde del shell (index.html)
            // cubre el arranque mientras bootstrap/context llegan frescos para
            // la nueva empresa (una recarga invalida todo el cache de queries,
            // que era lo que hacía reloadContext antes de navegar por SPA).
            markLoginIntro();
            window.location.href = route(target);
        } catch (e) {
            if (e instanceof ApiError && e.status === 403 && e.code === 'USER_INACTIVE_IN_COMPANY') {
                setError('Tu acceso a esta empresa ha sido revocado. Contacta al administrador.');
            } else if (e instanceof ApiError) {
                setError(e.message || 'No se pudo seleccionar la empresa.');
            } else {
                setError('Error de conexión. Intenta de nuevo.');
            }
            setSelecting(null);
        }
    }

    const heroStats: Array<{ label: string; value: string }> = [
        { label: 'Vinculados', value: String(stats.linked) },
        { label: 'Activos', value: String(stats.active) },
        { label: 'Sin acceso', value: String(stats.unlinked) },
    ];

    return (
        <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    <div className="flex flex-col gap-6 sm:gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                        <img src="/images/logo-black-font.svg" alt="bistro" className="block h-8 w-auto md:h-10 dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt="bistro" className="hidden h-8 w-auto md:h-10 dark:block" />

                        <HeroHeadline
                            eyebrow="Tu sesión"
                            title="Elige empresa"
                            description="Selecciona con cuál empresa quieres operar en esta sesión. Puedes cambiarlo cuando quieras desde el menú superior."
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {allUnlinked && (
                            <Alert variant="warning">
                                <ShieldAlert className="h-4 w-4" />
                                <AlertDescription>
                                    No tienes acceso activo a ninguna empresa. Contacta al administrador para que reactive tu vínculo.
                                </AlertDescription>
                            </Alert>
                        )}

                        {companies.length === 0 ? (
                            <EditorialEmpty
                                eyebrow="Empezar"
                                icon={<Building2 className="h-10 w-10" />}
                                title="No tienes empresas registradas"
                                description="Crea tu primera empresa para empezar a operar. Te tomará menos de un minuto."
                                action={
                                    <Button variant="default" size="lg" onClick={() => navigate('/enrollment/company')}>
                                        Registrar empresa
                                    </Button>
                                }
                            />
                        ) : (
                            <div className="space-y-6">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {sortedCompanies.map((company) => (
                                        <CompanyCard
                                            key={company.nit}
                                            company={company}
                                            onSelect={handleSelect}
                                            disabled={selecting !== null}
                                            loading={selecting === company.nit}
                                        />
                                    ))}
                                </div>
                                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                                    <Button variant="outline" onClick={() => navigate('/enrollment/company')} className="w-full sm:w-auto">
                                        Registrar otra empresa
                                    </Button>
                                    <Button variant="ghost" onClick={() => logout()} className="w-full sm:w-auto">
                                        <LogOut />
                                        Cerrar sesión
                                    </Button>
                                    <p className="text-muted-foreground text-xs">
                                        {companies.length === 1 ? '1 empresa en tu cuenta' : `${companies.length} empresas en tu cuenta`}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    <HeroPanel
                        eyebrow="Acceso seguro"
                        className="order-last md:col-span-5 lg:col-span-5"
                        footer={
                            <p className="text-sm leading-relaxed opacity-80">
                                Tu sesión se asocia a la empresa elegida. Cambia desde el menú de usuario cuando lo necesites — sin volver a iniciar
                                sesión.
                            </p>
                        }
                    >
                        <HeroPanelStats stats={heroStats} />
                    </HeroPanel>
                </div>
            </div>
        </div>
    );
}
