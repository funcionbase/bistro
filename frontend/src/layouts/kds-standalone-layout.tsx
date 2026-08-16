import { ToastProvider } from '@/components/ui/toast';
import { useDocumentTitle } from '@/lib/use-document-title';
import { ReactNode } from 'react';

interface KdsStandaloneLayoutProps {
    title: string;
    children: ReactNode;
}

/**
 * Layout full-screen para la pantalla del KDS por estación.
 *
 * Pensado para kiosk-mode en tablet (vertical 1280×800 es el target
 * original, pero **totalmente responsive**: 1 columna en mobile portrait
 * 375×667, 2 en tablet portrait, 3 en tablet landscape / 1280×800, 4 en
 * desktop ≥1536). No monta `AppShell` ni `AppSidebar` ni `AppSidebarHeader`
 * porque la tableta no tiene sesión web — autentica con device-token.
 *
 * `min-h-dvh` (dynamic viewport height) en lugar de `min-h-screen` para
 * que en iOS Safari la barra de URL colapsada no oculte el contenido.
 * `overflow-x-hidden` previene scroll lateral incluso si un ticket se
 * pasa accidentalmente del breakpoint (invariante de DS).
 *
 * Sin `OfflineBootstrap`: el sync-engine necesita JWT + active_company y
 * acá no aplica. Si se necesita offline en v1.1, montar un módulo
 * específico de KDS-offline que use el device-token como key.
 */
export default function KdsStandaloneLayout({ title, children }: KdsStandaloneLayoutProps) {
    useDocumentTitle(title);
    return (
        <ToastProvider>
            <div className="bg-background text-foreground pwa-safe-top pwa-safe-bottom flex min-h-dvh w-screen flex-col overflow-x-hidden">{children}</div>
        </ToastProvider>
    );
}
