import { AppLink } from '@/components/app-link';
import { cn } from '@/lib/utils';
import { type ComponentProps } from 'react';

type TextLinkProps = ComponentProps<typeof AppLink>;

/** Enlace de texto subrayado, agnóstico del transporte (#220). */
export default function TextLink({ className = '', children, ...props }: TextLinkProps) {
    return (
        <AppLink
            className={cn(
                'text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500',
                className,
            )}
            {...props}
        >
            {children}
        </AppLink>
    );
}
