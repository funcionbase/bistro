import { Button } from '@/components/ui/button';
import { apiClient } from '@/lib/api-client';
import { isFullyBlocked } from '@/lib/company-status';
import { useSharedData } from '@/lib/shared-data';
import { type Company } from '@/types';
import { AlertTriangle, ArrowRight, LoaderCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

interface SwitchCompanyResponse {
    authenticated?: boolean;
    default_route?: string;
}

/**
 * Banner de recuperación visible cuando el usuario está en una empresa
 * "fully blocked" (suspended) y tiene OTRA empresa en status `active`
 * a la que puede saltar.
 *
 * Sin esto, un usuario con varias empresas que entra por accidente a la
 * suspendida solo ve Dashboard + Mi empresa y queda sin
 * pista clara de cómo recuperarse. Este banner ofrece switch en 1 clic.
 *
 * Sirve para CUALQUIER rol — owner, admin, cashier, waiter — porque la
 * causa raíz (JWT apuntando a empresa bloqueada con alternativa activa
 * disponible) no depende del rol.
 *
 * No se renderiza si:
 *  - La empresa activa NO está blocked, o
 *  - No hay otra empresa active alternativa.
 *
 * Tras un switch exitoso, recarga la página para que el bootstrap fresco
 * traiga el contexto correcto (permissions, branches, etc.).
 */
export function BlockedCompanySwitchBanner() {
    const { activeCompany, companies = [] } = useSharedData();
    const [switching, setSwitching] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const isBlocked = activeCompany ? isFullyBlocked(activeCompany.status) : false;

    const activeAlternative = useMemo<Company | null>(() => {
        if (!isBlocked || !activeCompany) return null;
        const list = (companies as Company[]) ?? [];
        return (
            list.find((c) => c.nit !== activeCompany.nit && c.linked !== false && c.status === 'active') ?? null
        );
    }, [isBlocked, activeCompany, companies]);

    if (!isBlocked || !activeAlternative) {
        return null;
    }

    async function handleSwitch() {
        if (!activeAlternative) return;
        setSwitching(activeAlternative.nit);
        setError(null);
        try {
            await apiClient.post<SwitchCompanyResponse>('/api/v1/auth/switch-company', {
                company_nit: activeAlternative.nit,
            });
            // Reload completo: bootstrap fresco trae los nuevos permissions,
            // branches y catálogos del contexto correcto. SPA navigation no
            // basta porque tendríamos que re-fetch todo manualmente.
            window.location.assign('/dashboard');
        } catch (e) {
            setError((e as Error).message || 'No fue posible cambiar de empresa.');
            setSwitching(null);
        }
    }

    return (
        <div className="border-[color:var(--color-status-warning)]/40 bg-[color:var(--color-status-warning)]/10 mx-2 mt-2 rounded-md border p-3 text-xs group-data-[collapsible=icon]:hidden">
            <div className="mb-2 flex items-start gap-2">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-[color:var(--color-status-warning)]" />
                <div className="flex-1 leading-snug">
                    <p className="font-medium">Esta empresa está suspendida.</p>
                    <p className="text-muted-foreground mt-0.5">Cambia a otra empresa activa para seguir operando.</p>
                </div>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="w-full justify-between"
                onClick={() => void handleSwitch()}
                disabled={switching !== null}
            >
                <span className="truncate">{activeAlternative.name ?? `NIT ${activeAlternative.nit}`}</span>
                {switching === activeAlternative.nit ? (
                    <LoaderCircle className="h-3.5 w-3.5 shrink-0 animate-spin" />
                ) : (
                    <ArrowRight className="h-3.5 w-3.5 shrink-0" />
                )}
            </Button>
            {error && (
                <p className="mt-2 text-[color:var(--color-status-critical)]" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
