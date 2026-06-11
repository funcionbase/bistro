import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const alertVariants = cva(
    'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground',
                destructive: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
                safe: 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)] [&>svg]:text-[color:var(--color-status-safe)]',
                warning: 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)] [&>svg]:text-[color:var(--color-status-warning)]',
                critical: 'border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)] [&>svg]:text-[color:var(--color-status-critical)]',
                accent: 'border-accent/40 bg-accent/30 text-accent-foreground [&>svg]:text-accent-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

const Alert = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>>(
    ({ className, variant, ...props }, ref) => <div ref={ref} role="alert" className={cn(alertVariants({ variant }), className)} {...props} />,
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => (
    <h5 ref={ref} className={cn('mb-1 font-medium leading-none tracking-tight', className)} {...props} />
));
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('text-sm [&_p]:leading-relaxed', className)} {...props} />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertDescription, AlertTitle };
