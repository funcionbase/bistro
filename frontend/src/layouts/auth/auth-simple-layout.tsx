import { AppLink } from '@/components/app-link';
import AppLogoIcon from '@/components/app-logo-icon';
import { route } from '@/lib/route-compat';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 px-6 pt-[max(1.5rem,env(safe-area-inset-top,0px))] pb-[max(1.5rem,env(safe-area-inset-bottom,0px))] md:px-10 md:pt-[max(2.5rem,env(safe-area-inset-top,0px))] md:pb-[max(2.5rem,env(safe-area-inset-bottom,0px))]">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <AppLink href={route('home')} className="flex flex-col items-center gap-2 font-medium">
                            <div className="bg-primary mb-1 flex h-9 w-9 items-center justify-center rounded-xl">
                                <AppLogoIcon className="fill-primary-foreground size-6" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </AppLink>

                        <div className="space-y-2 text-center">
                            <h1 className="font-brand text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
