import { AppContent } from '@/components/app-content';
import { AppFooterMeta } from '@/components/app-footer-meta';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import CashRegisterAlertBanner from '@/components/cash-register/cash-register-alert-banner';
import OfflineBanner from '@/components/offline/offline-banner';
import StorageQuotaWarning from '@/components/offline/storage-quota-warning';
import SyncToast from '@/components/offline/sync-toast';
import OrderSmsFailureWatcher from '@/components/orders/order-sms-failure-watcher';
import { PwaUpdateBanner } from '@/components/pwa-update-banner';
import { ToastProvider } from '@/components/ui/toast';
import { putCachedBootstrap, requestPersistentStorage } from '@/lib/offline/db';
import { setActiveCompanyForSync, startSyncEngine } from '@/lib/offline/sync-engine';
import { useSharedData } from '@/lib/shared-data';
import { useEffect } from 'react';

function DynamicFavicon() {
    const { activeCompany } = useSharedData();

    useEffect(() => {
        // El favicon por defecto (/favicon.svg) ya trae su propio fondo, así que
        // no depende del tema; solo se sobreescribe con el logo de la empresa activa.
        const href = activeCompany?.logo_url ?? '/favicon.svg';

        let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            link.type = 'image/svg+xml';
            document.head.appendChild(link);
        }
        link.href = href;
    }, [activeCompany?.logo_url]);

    return null;
}

function OfflineBootstrap() {
    const shared = useSharedData();
    const activeCompany = shared.activeCompany;
    useEffect(() => {
        const stop = startSyncEngine();
        void requestPersistentStorage();
        return () => stop();
    }, []);
    useEffect(() => {
        setActiveCompanyForSync(activeCompany?.nit ?? null);
    }, [activeCompany?.nit]);

    // Snapshot de RBAC/catálogos para operar offline (plan §7.3, §11). Los
    // permisos llegan vía SharedData; al guardarlos, la caja puede leerlos tras
    // una recarga sin red (best-effort: el server revalida cada op al sync).
    useEffect(() => {
        const nit = activeCompany?.nit;
        if (!nit) return;
        void putCachedBootstrap(nit, {
            permissions: shared.permissions,
            role: shared.role,
            activeBranch: shared.activeBranch,
            branches: shared.branches,
            orderStatuses: shared.orderStatuses,
            paymentMethods: shared.paymentMethods,
            rbacActions: shared.rbacActions,
        }).catch(() => undefined);
    }, [
        activeCompany?.nit,
        shared.permissions,
        shared.role,
        shared.activeBranch,
        shared.branches,
        shared.orderStatuses,
        shared.paymentMethods,
        shared.rbacActions,
    ]);
    return null;
}

export default function AppSidebarLayout({ children }: { children: React.ReactNode }) {
    return (
        <ToastProvider>
            <AppShell variant="sidebar">
                <DynamicFavicon />
                <OfflineBootstrap />
                <SyncToast />
                <OrderSmsFailureWatcher />
                <AppSidebar />
                <AppContent variant="sidebar">
                    <AppSidebarHeader />
                    <OfflineBanner />
                    <StorageQuotaWarning />
                    <CashRegisterAlertBanner />
                    {children}
                    <AppFooterMeta />
                    <PwaUpdateBanner />
                </AppContent>
            </AppShell>
        </ToastProvider>
    );
}
