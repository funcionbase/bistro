import { AppLink } from '@/components/app-link';
import Heading from '@/components/heading';
import { cn } from '@/lib/utils';
import { Bell, Lock, Palette, User, type LucideIcon } from 'lucide-react';

interface SettingsNavItem {
    title: string;
    url: string;
    icon: LucideIcon;
}

const sidebarNavItems: SettingsNavItem[] = [
    { title: 'Perfil', url: '/settings/profile', icon: User },
    { title: 'Contraseña', url: '/settings/password', icon: Lock },
    { title: 'Notificaciones', url: '/settings/notifications', icon: Bell },
    { title: 'Apariencia', url: '/settings/appearance', icon: Palette },
];

/**
 * Layout compartido para `/settings/*`.
 *
 * - Mobile (`<lg`): tab bar horizontal con scroll si no caben, touch
 *   targets 44px, indicador activo con underline.
 * - Desktop (`lg+`): sidebar vertical.
 */
export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const activePath = typeof window !== 'undefined' ? window.location.pathname : '';

    return (
        <div className="px-4 py-6 sm:px-6 lg:px-8">
            <Heading title="Configuración" description="Administra tu perfil y preferencias de cuenta" />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-12">
                <aside className="lg:w-48 lg:shrink-0">
                    {/* Mobile: scrollable tab row with 44px touch targets */}
                    <nav
                        aria-label="Navegación de configuración"
                        className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:gap-1 lg:overflow-visible lg:px-0 lg:pb-0"
                    >
                        {sidebarNavItems.map((item) => {
                            const isActive = activePath === item.url;
                            const Icon = item.icon;
                            return (
                                <AppLink
                                    key={item.url}
                                    href={item.url}
                                    prefetch
                                    className={cn(
                                        'focus-visible:ring-ring focus-visible:ring-offset-background inline-flex shrink-0 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                                        'min-h-[44px] lg:min-h-9 lg:w-full lg:justify-start lg:px-3',
                                        isActive ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                    )}
                                >
                                    <Icon className="h-4 w-4 shrink-0" aria-hidden />
                                    <span>{item.title}</span>
                                </AppLink>
                            );
                        })}
                    </nav>
                </aside>

                <div className="min-w-0 flex-1 lg:max-w-2xl">
                    <section className="space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
