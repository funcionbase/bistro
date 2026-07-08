import { cn } from '@/lib/utils';

interface BrandLogoProps {
    /** Clases de tamaño/espaciado (ej. `h-7`, `h-8 md:h-10`). Se aplican a ambas variantes. */
    className?: string;
    alt?: string;
}

/**
 * Logo de marca con swap automático light/dark (par negro/blanco). Reemplaza
 * el patrón duplicado de dos `<img>` con `dark:hidden` / `dark:block`.
 */
export function BrandLogo({ className, alt = 'bistro' }: BrandLogoProps) {
    return (
        <>
            <img src="/images/logo-black-font.svg" alt={alt} className={cn('block w-auto dark:hidden', className)} />
            <img src="/images/logo-white-font.svg" alt="" aria-hidden className={cn('hidden w-auto dark:block', className)} />
        </>
    );
}
