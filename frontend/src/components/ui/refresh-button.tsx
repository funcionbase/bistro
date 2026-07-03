import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';

interface RefreshButtonProps extends Omit<ComponentProps<typeof Button>, 'onClick' | 'children'> {
    /** Acción de refresh. Si retorna una promesa, el icono gira hasta que resuelva/rechace. */
    onRefresh: () => Promise<unknown> | void;
    /** Fuerza el giro desde afuera (ej. estado `loading` de un hook que ya trackea el fetch). */
    refreshing?: boolean;
    /** Texto del botón. Default: "Refrescar". */
    label?: string;
}

/**
 * Botón "Refrescar" del DS: dispara un refetch de datos (nunca recarga la
 * página) y hace girar el RefreshCw mientras la petición está en vuelo.
 * Único punto para el patrón — no duplicar `<Button><RefreshCw/></Button>`.
 */
export function RefreshButton({
    onRefresh,
    refreshing = false,
    label = 'Refrescar',
    disabled,
    variant = 'outline',
    size = 'sm',
    ...props
}: RefreshButtonProps) {
    const [busy, setBusy] = useState(false);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const spinning = busy || refreshing;

    return (
        <Button
            type="button"
            variant={variant}
            size={size}
            disabled={disabled || busy}
            onClick={() => {
                const result = onRefresh();
                if (result instanceof Promise) {
                    setBusy(true);
                    void result.finally(() => {
                        if (mounted.current) setBusy(false);
                    });
                }
            }}
            {...props}
        >
            <RefreshCw className={cn('mr-1.5 h-3.5 w-3.5', spinning && 'animate-spin')} />
            {label}
        </Button>
    );
}
