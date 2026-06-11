import { ChevronLeft } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

import { AutoBreadcrumb } from '@/components/ui/auto-breadcrumb';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useIsStandalone } from '@/hooks/use-is-standalone';

export function AppSidebarHeader() {
    const navigate = useNavigate();
    const isStandalone = useIsStandalone();

    return (
        <header className="border-sidebar-border/50 bg-background/80 supports-[backdrop-filter]:bg-background/60 pwa-safe-top sticky top-0 z-30 flex min-h-14 shrink-0 items-center gap-2 border-b px-4 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:min-h-12 sm:min-h-16 sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1 shrink-0" />
                {/* En la PWA instalada no hay back del navegador: lo reponemos acá. */}
                {isStandalone && (
                    <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => navigate(-1)} aria-label="Atrás">
                        <ChevronLeft className="size-5" />
                    </Button>
                )}
                <div className="bg-sidebar-border/60 hidden h-5 w-px shrink-0 sm:block" />
                <AutoBreadcrumb className="min-w-0" />
            </div>
        </header>
    );
}
