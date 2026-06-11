import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { UtensilsCrossed } from 'lucide-react';
import type { ReactNode } from 'react';

export interface MenuItemRowItem {
    name: string;
    description?: string | null;
    image_url?: string | null;
    thumbnail_url?: string | null;
    price: number;
    available?: boolean;
}

interface MenuItemRowProps {
    item: MenuItemRowItem;
    formatPrice: (value: number) => string;
    action?: ReactNode;
    /**
     * Si se proporciona, la imagen (o placeholder) se convierte en un botón
     * que dispara este callback. Pensado para abrir un modal con el detalle
     * del plato (foto grande + descripción). Si se omite, la imagen es
     * presentacional.
     */
    onImageClick?: () => void;
    className?: string;
}

/**
 * Fila visual de un item de menú para listados densos (/menus público y
 * /t/{qr_token}/menu). Layout: imagen + (nombre + descripción) + (precio +
 * acción opcional alineados a la derecha).
 *
 * - No aplica color de marca del cliente; usa tokens del DS (bg-card,
 *   border-border, muted-foreground). El primary_color de la empresa se
 *   reserva al nombre de la empresa en el hero.
 * - Estado `available=false` baja opacidad y muestra etiqueta "No disponible".
 * - `action` es opcional: en menú público no hay acción, en sesión grupal va
 *   un botón "Agregar".
 */
export function MenuItemRow({ item, formatPrice, action, onImageClick, className }: MenuItemRowProps) {
    const src = item.thumbnail_url ?? item.image_url ?? null;
    const unavailable = item.available === false;

    const thumbInner = src ? (
        <img
            src={src}
            alt={item.name}
            className="size-20 rounded-xl object-cover md:size-24"
            loading="lazy"
        />
    ) : (
        <div
            aria-hidden
            className="bg-muted text-muted-foreground flex size-20 items-center justify-center rounded-xl md:size-24"
        >
            <UtensilsCrossed className="size-7" />
        </div>
    );

    return (
        <div
            className={cn(
                'group bg-card text-card-foreground flex items-start gap-4 rounded-2xl px-3 py-3 transition-colors hover:bg-muted/40 md:px-4 md:py-4',
                unavailable && 'opacity-60 hover:bg-card',
                className,
            )}
        >
            {onImageClick ? (
                <button
                    type="button"
                    onClick={onImageClick}
                    aria-label={`Ver detalle de ${item.name}`}
                    className="border-border focus:ring-ring flex-shrink-0 overflow-hidden rounded-xl border transition-transform hover:scale-[1.02] focus:outline-none focus:ring-2"
                >
                    {thumbInner}
                </button>
            ) : (
                <div className="border-border flex-shrink-0 overflow-hidden rounded-xl border">
                    {thumbInner}
                </div>
            )}

            <div className="min-w-0 flex-1 self-stretch">
                <h3 className="text-foreground text-base font-semibold leading-snug">
                    {item.name}
                </h3>
                {item.description && (
                    <p className="text-muted-foreground mt-1 line-clamp-2 text-xs leading-relaxed">
                        {item.description}
                    </p>
                )}
                {unavailable && (
                    <p className="mt-2 inline-flex text-[10px] font-semibold uppercase tracking-[0.18em] text-[color:var(--color-status-warning)]">
                        No disponible
                    </p>
                )}
            </div>

            <div className="flex shrink-0 flex-col items-end justify-between gap-2 self-stretch">
                <p className="text-foreground text-base font-semibold tabular-nums">
                    {formatPrice(item.price)}
                </p>
                {action && <div>{action}</div>}
            </div>
        </div>
    );
}

interface MenuItemRowSkeletonProps {
    /**
     * Si true, reserva espacio para el botón de acción a la derecha
     * (carrito QR). Default true — combina mejor con la página de menú
     * del comensal. Para el menú público sin acción, pasar `false`.
     */
    withAction?: boolean;
    className?: string;
}

/**
 * Skeleton de `MenuItemRow` para estados de carga del catálogo público
 * (`/menus/:nit`, `/t/:qr/menu`). Replica el layout: thumb 80–96px,
 * nombre + descripción + precio + acción.
 *
 * Pulsing usa el shadcn `Skeleton` (`animate-pulse rounded-lg bg-muted`).
 * Pensado para repetirse N veces dentro de una lista mientras carga.
 */
export function MenuItemRowSkeleton({ withAction = true, className }: MenuItemRowSkeletonProps) {
    return (
        <div
            aria-hidden
            className={cn(
                'bg-card text-card-foreground flex items-start gap-4 rounded-2xl px-3 py-3 md:px-4 md:py-4',
                className,
            )}
        >
            <Skeleton className="size-20 shrink-0 rounded-xl md:size-24" />
            <div className="flex-1 space-y-2 self-stretch">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-3 w-full" />
                <Skeleton className="h-3 w-2/3" />
            </div>
            <div className="flex shrink-0 flex-col items-end justify-between gap-2 self-stretch">
                <Skeleton className="h-4 w-14" />
                {withAction && <Skeleton className="h-10 w-20 rounded-md" />}
            </div>
        </div>
    );
}
