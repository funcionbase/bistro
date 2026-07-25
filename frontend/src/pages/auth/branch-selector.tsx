import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { OnboardingPageSkeleton } from '@/components/ui/onboarding-page-skeleton';
import { SelectableTile } from '@/components/ui/selectable-tile';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { apiClient, ApiError } from '@/lib/api-client';
import { reloadContext } from '@/lib/navigate-compat';
import { queryClient } from '@/lib/query-client';
import { route } from '@/lib/route-compat';
import { type Branch } from '@/types';
import { AlertCircle, MapPin, ShieldCheck, Star } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useDocumentTitle } from '@/lib/use-document-title';

interface BranchCardProps {
    branch: Branch;
    onSelect: (id: string) => void;
    disabled?: boolean;
    loading?: boolean;
}

interface SwitchBranchResponse {
    default_route?: string;
    authenticated?: boolean;
}

const LAST_BRANCH_KEY = 'flexyflow.last_branch_id';

function BranchCard({ branch, onSelect, disabled = false, loading = false }: BranchCardProps) {
    return (
        <SelectableTile onClick={() => onSelect(branch.id)} disabled={disabled} loading={loading}>
            <div className="bg-primary/10 text-primary relative flex h-14 w-14 items-center justify-center overflow-hidden rounded-xl">
                <MapPin className="h-7 w-7" />
                {branch.is_default && (
                    <span
                        className="bg-accent text-accent-foreground absolute -right-1 -bottom-1 flex h-5 w-5 items-center justify-center rounded-full"
                        aria-label="Sede principal"
                    >
                        <Star className="h-3 w-3 fill-current" />
                    </span>
                )}
            </div>

            <div className="w-full space-y-1 text-center">
                <p className="leading-snug font-semibold">{branch.name}</p>
                {branch.address && <p className="text-muted-foreground text-xs">{branch.address}</p>}
                {branch.city && <p className="text-muted-foreground text-xs">{branch.city}</p>}
            </div>
        </SelectableTile>
    );
}

/**
 * Selector de sede — ruta SPA (#220, Fase 2, multi-sede #117).
 *
 * branches y activeCompany llegan vía useBootstrap(). La selección llama
 * /api/v1/auth/switch-branch. "Cambiar de empresa" navega a la ruta
 * SPA company-selector con React Router.
 */
export default function BranchSelectorRoute() {
    useDocumentTitle('Seleccionar sede');

    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const bootstrap = useBootstrap();
    const [selecting, setSelecting] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [autoSelected, setAutoSelected] = useState(false);

    const branches = useMemo<Branch[]>(() => bootstrap.data?.branches ?? [], [bootstrap.data]);
    const activeCompanyNit = bootstrap.data?.activeCompany?.nit ?? null;
    const activeCompanyName = bootstrap.data?.activeCompany?.name ?? null;

    const heroStats = useMemo(() => {
        const total = branches.length;
        const principal = branches.filter((b) => b.is_default).length;
        return [
            { label: 'Sedes', value: String(total) },
            { label: 'Principales', value: String(principal) },
        ];
    }, [branches]);

    async function handleSelect(branchId: string) {
        setSelecting(branchId);
        setError(null);

        try {
            const data = await apiClient.post<SwitchBranchResponse>('/api/v1/auth/switch-branch', {
                branch_id: branchId,
            });

            if (activeCompanyNit) {
                localStorage.setItem(`${LAST_BRANCH_KEY}:${activeCompanyNit}`, branchId);
            }
            const target = data.default_route && data.default_route !== '' ? data.default_route : 'dashboard';
            // El switch reemplaza la cookie JWT con el nuevo `active_branch_id`.
            // Mismo flujo que BranchSwitcher: refrescar primero bootstrap +
            // business-context y después eliminar las queries branch-scoped
            // para no servir datos de la sede anterior dentro del staleTime.
            await reloadContext();
            queryClient.removeQueries({
                predicate: (q) => q.queryKey[0] !== 'bootstrap' && q.queryKey[0] !== 'business-context',
            });
            navigate(route(target));
        } catch (e) {
            setError(e instanceof ApiError ? e.message || 'No se pudo seleccionar la sede.' : 'Error de conexión. Intenta de nuevo.');
            setSelecting(null);
        }
    }

    // Auto-seleccionar última sede usada SOLO con `?auto=1` (flujo post-login).
    // Sin el flag, la pantalla siempre permite elegir — antes el auto-select
    // incondicional hacía imposible cambiar de sede desde esta ruta.
    const autoRequested = searchParams.get('auto') === '1';
    useEffect(() => {
        if (!autoRequested || bootstrap.isLoading || autoSelected || branches.length === 0 || selecting !== null) {
            return;
        }
        setAutoSelected(true);
        const lastId = localStorage.getItem(`${LAST_BRANCH_KEY}:${activeCompanyNit ?? ''}`);
        if (lastId && branches.some((b) => String(b.id) === lastId)) {
            void handleSelect(lastId);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoRequested, bootstrap.isLoading, branches, autoSelected, selecting, activeCompanyNit]);

    if (bootstrap.isLoading || selecting !== null) {
        return <OnboardingPageSkeleton layout="tiles" tiles={Math.max(2, Math.min(branches.length || 3, 6))} />;
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

    return (
        <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8">
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    <div className="flex flex-col gap-6 sm:gap-8 md:col-span-7 md:gap-10 lg:col-span-7">
                        <img src="/images/logo-black-font.svg" alt="bistro" className="block h-8 w-auto md:h-10 dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt="bistro" className="hidden h-8 w-auto md:h-10 dark:block" />

                        <HeroHeadline
                            eyebrow="Tu sesión"
                            title="Elige sede"
                            description={
                                activeCompanyName
                                    ? `Selecciona en qué sede de ${activeCompanyName} quieres operar esta sesión. Puedes cambiarla cuando quieras desde el menú superior.`
                                    : 'Selecciona en qué sede quieres operar esta sesión. Puedes cambiarla cuando quieras desde el menú superior.'
                            }
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {branches.length === 0 ? (
                            <EditorialEmpty
                                eyebrow="Sin acceso"
                                icon={<MapPin className="h-10 w-10" />}
                                title="No tienes sedes asignadas en esta empresa"
                                description="Contacta al administrador para que te asigne acceso a al menos una sede."
                                action={
                                    <Button variant="outline" onClick={() => navigate('/auth/company-selector')} className="w-full sm:w-auto">
                                        Cambiar de empresa
                                    </Button>
                                }
                            />
                        ) : (
                            <div className="space-y-6">
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {branches.map((branch) => (
                                        <BranchCard
                                            key={branch.id}
                                            branch={branch}
                                            onSelect={handleSelect}
                                            disabled={selecting !== null}
                                            loading={selecting === branch.id}
                                        />
                                    ))}
                                </div>
                                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                                    <Button variant="outline" onClick={() => navigate('/auth/company-selector')} className="w-full sm:w-auto">
                                        Cambiar de empresa
                                    </Button>
                                    <p className="text-muted-foreground text-xs">
                                        {branches.length === 1 ? '1 sede disponible' : `${branches.length} sedes disponibles`}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    <HeroPanel
                        eyebrow="Tu próximo paso"
                        className="order-last md:col-span-5 lg:col-span-5"
                        footer={
                            <div className="space-y-3 text-sm">
                                <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                    <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>Cada sede tiene su propia caja, comanda y horarios. Tu sesión queda asociada a la sede elegida.</span>
                                </div>
                                <div className="flex items-start gap-2.5 leading-relaxed opacity-90">
                                    <Star className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>Recordamos tu última sede — la próxima vez entras directo sin elegir.</span>
                                </div>
                            </div>
                        }
                    >
                        <HeroPanelStats stats={heroStats} />
                    </HeroPanel>
                </div>
            </div>
        </div>
    );
}
