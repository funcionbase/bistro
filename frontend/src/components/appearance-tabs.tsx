import { Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { LucideIcon, Monitor, Moon, Sun } from 'lucide-react';
import { HTMLAttributes } from 'react';

export default function AppearanceToggleTab({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Claro' },
        { value: 'dark', icon: Moon, label: 'Oscuro' },
        { value: 'system', icon: Monitor, label: 'Sistema' },
    ];

    return (
        <div
            role="radiogroup"
            aria-label="Tema visual"
            className={cn('bg-muted grid w-full grid-cols-3 gap-1 rounded-lg p-1 sm:inline-flex sm:w-auto', className)}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => {
                const isActive = appearance === value;
                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={isActive}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'focus-visible:ring-ring focus-visible:ring-offset-background inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none sm:min-h-9 sm:justify-start sm:px-3.5',
                            isActive ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                        )}
                    >
                        <Icon className="h-4 w-4 shrink-0" aria-hidden />
                        <span>{label}</span>
                    </button>
                );
            })}
        </div>
    );
}
