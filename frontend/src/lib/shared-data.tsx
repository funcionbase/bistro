import { type BootstrapResponse } from '@/hooks/use-bootstrap';
import { useGa4 } from '@/hooks/use-ga4';
import { type SharedData } from '@/types';
import { type ReactNode, createContext, useContext, useMemo } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Contexto de SharedData del shell SPA (#220).
 *
 * Lo puebla `<SpaSharedDataBridge>` desde `useBootstrap()` (GET
 * /api/v1/bootstrap). Los componentes lo leen vía `useSharedData()` y la
 * URL actual vía `useCurrentUrl()`.
 */
const SharedDataContext = createContext<SharedData | null>(null);
const CurrentUrlContext = createContext<string | null>(null);

/** Lee el contexto de SharedData. Lanza si se usa fuera del bridge. */
export function useSharedData(): SharedData {
    const ctx = useContext(SharedDataContext);
    if (ctx === null) {
        throw new Error('useSharedData() debe usarse dentro de <SpaSharedDataBridge>.');
    }
    return ctx;
}

/** Path actual (pathname de React Router). Usado por el sidebar para el item activo. */
export function useCurrentUrl(): string {
    return useContext(CurrentUrlContext) ?? '';
}

/**
 * Provee SharedData + URL actual al árbol SPA. Mapea la respuesta de
 * `/api/v1/bootstrap` al shape `SharedData`.
 */
export function SpaSharedDataBridge({ bootstrap, children }: { bootstrap: BootstrapResponse; children: ReactNode }) {
    const location = useLocation();

    // GA4: carga diferida + page_view por navegación. No-op si gaMeasurementId
    // es null (local/qa). Vive acá porque el bridge ya está dentro del router
    // (useLocation disponible) y cubre todo el árbol autenticado.
    useGa4(bootstrap.gaMeasurementId);

    const value = useMemo<SharedData>(
        () => ({
            name: 'flexyflow',
            quote: { message: '', author: '' },
            auth: { user: bootstrap.auth.user as SharedData['auth']['user'] },
            activeCompany: bootstrap.activeCompany,
            companies: bootstrap.companies,
            activeBranch: bootstrap.activeBranch,
            branches: bootstrap.branches,
            needsProfileCompletion: bootstrap.needsProfileCompletion,
            role: bootstrap.role,
            permissions: bootstrap.permissions,
            orderStatuses: bootstrap.orderStatuses,
            paymentMethods: bootstrap.paymentMethods,
            rbacActions: bootstrap.rbacActions,
            employeeStatuses: bootstrap.employeeStatuses,
            vapidPublicKey: bootstrap.vapidPublicKey,
            gaMeasurementId: bootstrap.gaMeasurementId,
            printingConfig: bootstrap.printingConfig,
            availableBanks: bootstrap.availableBanks,
        }),
        [bootstrap],
    );

    return (
        <SharedDataContext.Provider value={value}>
            <CurrentUrlContext.Provider value={location.pathname}>{children}</CurrentUrlContext.Provider>
        </SharedDataContext.Provider>
    );
}
