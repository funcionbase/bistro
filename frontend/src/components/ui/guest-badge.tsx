import { cn } from '@/lib/utils';

interface GuestBadgeProps {
    /** Nombre que el comensal eligió en el join (snapshot histórico). */
    displayName: string;
    /** Si se pasa, muestra el teléfono enmascarado bajo el nombre (300 *** 4567). */
    phoneMasked?: string;
    /**
     * Tamaño visual del chip. `sm` para tablas densas, `md` (default) para
     * detalle, `lg` para hero (carrito propio del comensal).
     */
    size?: 'sm' | 'md' | 'lg';
    /**
     * Si se pasa, muestra un punto/etiqueta de estado al lado del nombre
     * (`active` verde, `paid` neutro, `awaiting` ámbar).
     */
    status?: 'active' | 'paid' | 'awaiting';
    className?: string;
}

const sizeClasses: Record<NonNullable<GuestBadgeProps['size']>, { wrap: string; avatar: string; text: string; sub: string }> = {
    sm: { wrap: 'gap-2 px-2 py-1', avatar: 'h-6 w-6 text-xs', text: 'text-xs font-semibold', sub: 'text-[10px]' },
    md: { wrap: 'gap-3 px-3 py-1.5', avatar: 'h-8 w-8 text-sm', text: 'text-sm font-semibold', sub: 'text-xs' },
    lg: { wrap: 'gap-3 px-3 py-2', avatar: 'h-10 w-10 text-base', text: 'text-base font-semibold', sub: 'text-xs' },
};

const statusToDot: Record<NonNullable<GuestBadgeProps['status']>, string> = {
    active: 'bg-[color:var(--color-status-safe)]',
    awaiting: 'bg-[color:var(--color-status-warning)]',
    paid: 'bg-muted-foreground',
};

/**
 * Chip reutilizable que identifica a un comensal dentro del flujo de mesa con
 * QR (#191). Genera un avatar con iniciales y color deterministicos a partir
 * del `displayName`, usando tokens del DS.
 *
 * Casos de uso: encabezado del menú del comensal, lista de comensales de la
 * mesa para el mesero, ticket del KDS, desglose de caja por persona.
 */
export function GuestBadge({ displayName, phoneMasked, size = 'md', status, className }: GuestBadgeProps) {
    const initials = getInitials(displayName);
    const palette = getPalette(displayName);
    const cls = sizeClasses[size];

    return (
        <div
            className={cn(
                'border-border bg-card text-card-foreground inline-flex items-center rounded-full border',
                cls.wrap,
                className,
            )}
            data-testid="guest-badge"
        >
            <span
                aria-hidden
                className={cn(
                    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
                    cls.avatar,
                    palette.bg,
                    palette.fg,
                )}
            >
                {initials}
            </span>
            <span className="flex min-w-0 flex-col leading-tight">
                <span className={cn('truncate', cls.text)}>{displayName}</span>
                {phoneMasked && <span className={cn('text-muted-foreground tabular-nums', cls.sub)}>{phoneMasked}</span>}
            </span>
            {status && (
                <span
                    aria-label={status}
                    className={cn('ml-1 inline-block h-2 w-2 shrink-0 rounded-full', statusToDot[status])}
                />
            )}
        </div>
    );
}

function getInitials(name: string): string {
    const trimmed = name.trim();
    if (!trimmed) return '·';
    const parts = trimmed.split(/\s+/).slice(0, 2);
    return parts.map((p) => p.charAt(0)).join('').toUpperCase() || '·';
}

const palettes: Array<{ bg: string; fg: string }> = [
    { bg: 'bg-primary/15', fg: 'text-primary' },
    { bg: 'bg-accent/40', fg: 'text-accent-foreground' },
    { bg: 'bg-secondary', fg: 'text-secondary-foreground' },
    { bg: 'bg-muted', fg: 'text-foreground' },
];

function getPalette(seed: string): { bg: string; fg: string } {
    let hash = 0;
    for (let i = 0; i < seed.length; i += 1) {
        hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
    }
    return palettes[hash % palettes.length];
}
