import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import CashRegisterAlertBanner from '@/components/cash-register/cash-register-alert-banner';
import OfflineBanner from '@/components/offline/offline-banner';
import StorageQuotaWarning from '@/components/offline/storage-quota-warning';
import SyncToast from '@/components/offline/sync-toast';
import { ToastProvider } from '@/components/ui/toast';
import { requestPersistentStorage } from '@/lib/offline/db';
import { setActiveCompanyForSync, startSyncEngine } from '@/lib/offline/sync-engine';
import { useSharedData } from '@/lib/shared-data';
import { useEffect } from 'react';

function DynamicFavicon() {
    const { activeCompany } = useSharedData();

    useEffect(() => {
        const applyFavicon = () => {
            const href = activeCompany?.logo_url
                ? activeCompany.logo_url
                : document.documentElement.classList.contains('dark')
                  ? '/images/logo-white-font.svg'
                  : '/images/logo-black-font.svg';

            let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
            if (!link) {
                link = document.createElement('link');
                link.rel = 'icon';
                link.type = 'image/svg+xml';
                document.head.appendChild(link);
            }
            link.href = href;
        };

        applyFavicon();

        const observer = new MutationObserver(applyFavicon);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, [activeCompany?.logo_url]);

    return null;
}

function OfflineBootstrap() {
    const { activeCompany } = useSharedData();
    useEffect(() => {
        const stop = startSyncEngine();
        void requestPersistentStorage();
        return () => stop();
    }, []);
    useEffect(() => {
        setActiveCompanyForSync(activeCompany?.nit ?? null);
    }, [activeCompany?.nit]);
    return null;
}

export default function AppSidebarLayout({ children }: { children: React.ReactNode }) {
    return (
        <ToastProvider>
            <AppShell variant="sidebar">
                <DynamicFavicon />
                <OfflineBootstrap />
                <SyncToast />
                <AppSidebar />
                <AppContent variant="sidebar">
                    <AppSidebarHeader />
                    <OfflineBanner />
                    <StorageQuotaWarning />
                    <CashRegisterAlertBanner />
                    {children}
                </AppContent>
            </AppShell>
        </ToastProvider>
    );
}
