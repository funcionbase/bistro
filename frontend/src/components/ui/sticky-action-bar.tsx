import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

interface StickyActionBarProps {
    children: ReactNode;
    className?: string;
    /**
     * Si true, fija el contenedor al viewport (`fixed inset-x-0 bottom-0`).
     * Si false, se queda como `sticky bottom-0` dentro de su contenedor —
     * útil para sheets/dialogs internos donde el viewport no es la página.
     * Default: true.
     */
    fixed?: boolean;
    /**
     * Ancho máximo del contenido interno. Sigue el patrón de las páginas
     * públicas (`max-w-2xl` para QR mesa, `max-w-6xl` para landings).
     */
    innerMaxWidth?: string;
}

/**
 * Barra fija inferior con respeto a safe-area iOS.
 *
 * Sustituye el patrón duplicado de `fixed inset-x-0 bottom-0 z-30 px-4 pb-4`
 * que aparece en floating CTAs (carrito QR mesa, botones de cierre de orden,
 * etc.). El `pb-safe-1` hace `max(1rem, env(safe-area-inset-bottom, 1rem))`
 * para que el botón no quede tapado por el home indicator del iPhone.
 *
 * Fondo sólido con border-t para separar del contenido scrollable (sin
 * gradient: generaba un fade inferior→superior no deseado en la PWA).
 *
 * Uso:
 * ```tsx
 * <StickyActionBar>
 *   <Button size="lg" className="w-full">Enviar al mesero</Button>
 * </StickyActionBar>
 * ```
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (Checklist mobile obligatorio).
 */
export function StickyActionBar({ children, className, fixed = true, innerMaxWidth }: StickyActionBarProps) {
    return (
        <div
            className={cn(
                fixed ? 'fixed inset-x-0 bottom-0 z-30' : 'sticky bottom-0 z-30',
                'border-t border-border bg-background px-4 pt-3 pb-safe-1',
                className,
            )}
        >
            <div className={cn('mx-auto w-full', innerMaxWidth ?? 'max-w-2xl')}>{children}</div>
        </div>
    );
}
