import { cn } from '@/lib/utils';

interface RoleBadgeProps {
    name: string;
    color?: string | null;
    isSystem?: boolean;
    /**
     * Clases extra para el outer span. Por defecto el badge respeta el ancho
     * del contenedor (`max-w-full`) y trunca el nombre con elipsis — útil
     * cuando el badge vive en celdas/cards angostas (mobile DataCardList,
     * tabla densa).
     */
    className?: string;
}

function hexToLuminance(hex: string): number {
    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;
    const toLinear = (c: number) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    return 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
}

const BASE_CLASSES = 'inline-flex max-w-full min-w-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium align-middle';

export default function RoleBadge({ name, color, isSystem, className }: RoleBadgeProps) {
    if (color) {
        const luminance = hexToLuminance(color);
        // Texto blanco / oscuro segun luminancia del fondo (color custom de
        // company_roles.color, definido por el admin). Hex literal aqui es
        // valido por ser color del usuario, no token del sistema de diseno.
        const textColor = luminance > 0.35 ? '#111827' : '#ffffff';
        return (
            <span className={cn(BASE_CLASSES, className)} style={{ backgroundColor: color, color: textColor }} title={name}>
                <span className="truncate">{name}</span>
            </span>
        );
    }

    if (isSystem) {
        return (
            <span className={cn(BASE_CLASSES, 'bg-primary/10 text-primary', className)} title={name}>
                <span className="truncate">{name}</span>
            </span>
        );
    }

    return (
        <span className={cn(BASE_CLASSES, 'bg-muted text-muted-foreground', className)} title={name}>
            <span className="truncate">{name}</span>
        </span>
    );
}
