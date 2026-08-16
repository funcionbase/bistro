import { useActiveBranch } from '@/hooks/use-active-branch';
import { cn } from '@/lib/utils';
import { MapPin, Star } from 'lucide-react';

interface ActiveBranchBadgeProps {
    className?: string;
    /**
     * Si `force=true`, el badge se muestra aunque el usuario solo tenga 1
     * sede accesible. Default `false`: cuando hay una sola sede, el dato
     * no es relevante para el usuario y agrega ruido al header.
     */
    force?: boolean;
}

/**
 * Pill que muestra la sede activa actual.
 *
 * Se renderiza dentro del `PageHeader` y en cualquier vista operativa donde
 * el usuario deba tener visibilidad permanente del contexto en el que
 * está operando. Reglas del DS:
 *  - Tokens: `bg-card border-border text-foreground` (no usar colores
 *    hardcoded).
 *  - Estrella de sede default tomada de `lucide-react`.
 *  - Tipografía: 11px uppercase como otros chips informativos.
 *
 * Se omite cuando el usuario solo tiene una sede accesible (la
 * información no agrega valor) salvo que el caller pase `force={true}`
 * — útil en pantallas operativas críticas (caja, KDS) donde queremos
 * recordatorio constante.
 */
export function ActiveBranchBadge({ className, force = false }: ActiveBranchBadgeProps) {
    const { activeBranch, branches } = useActiveBranch();

    if (!activeBranch) {
        return null;
    }

    if (!force && branches.length <= 1) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-[11px] font-medium uppercase tracking-wide text-foreground',
                className,
            )}
            aria-label={`Sede activa: ${activeBranch.name}`}
        >
            <MapPin className="size-3 text-muted-foreground" aria-hidden="true" />
            <span className="truncate max-w-[160px]">{activeBranch.name}</span>
            {activeBranch.is_default && <Star className="size-3 fill-[color:var(--color-category-amber)] text-[color:var(--color-category-amber)]" aria-hidden="true" />}
        </span>
    );
}
