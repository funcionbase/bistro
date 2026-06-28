import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Plus, Users } from 'lucide-react';
import { type ReactNode } from 'react';

interface GroupSessionInfo {
    /** Cantidad de comensales activos en la sesión grupal. */
    guestsCount: number;
    /** True si la sesión ya tiene una orden activa con al menos un item. */
    hasActiveOrder: boolean;
    /** Cantidad de items aún no servidos (en cocina/listos). */
    itemsInFlight: number;
    /** Items pendientes de aprobación del mesero. */
    pendingApprovalCount: number;
    /** Acción opcional renderizada bajo el desglose (ej. botón "Cobrar mesa"). */
    releaseAction?: ReactNode;
}

interface TableCardProps {
    /** Numero/etiqueta de la mesa (ej. "1", "12", "Terraza A"). */
    number: string;
    /** True si la mesa tiene una orden abierta. */
    occupied: boolean;
    /** Cantidad de items de la orden (solo si occupied). */
    itemCount?: number;
    /** Total formateado de la orden (solo si occupied). Ya formateado por el caller. */
    total?: string;
    /** Label del status operativo de la orden (solo si occupied). */
    statusLabel?: string;
    onClick?: () => void;
    /** Override del CTA cuando esta disponible. Default: "Abrir mesa". */
    availableCta?: ReactNode;
    /**
     * Si la mesa tiene una `TableSession` grupal activa, este objeto
     * configura el render alterno: tono info (clientes pidiendo desde sus
     * celulares, NO disponible para abrir orden tradicional) y acción
     * opcional para liberar la mesa.
     */
    groupSession?: GroupSessionInfo;
    className?: string;
}

/**
 * Tarjeta de una mesa en la grilla del POS. Visualiza si la mesa tiene una
 * orden abierta (ocupada) o esta libre (disponible) usando tokens semanticos
 * del semaforo del design system v3.1:
 *
 *  - Ocupada -> warning (atencion del mesero)
 *  - Disponible -> safe (libre, lista para abrir)
 *
 * Conserva touch target >= 44px via padding interno + min-height. Se renderiza
 * como `<button>` para que sea accesible con teclado y respete focus ring.
 */
export function TableCard({ number, occupied, itemCount, total, statusLabel, onClick, availableCta, groupSession, className }: TableCardProps) {
    const inGroupSession = !!groupSession;

    const tone = inGroupSession
        ? 'border-[color:var(--color-status-info)]/30 bg-[color:var(--color-status-info)]/10 hover:bg-[color:var(--color-status-info)]/15'
        : occupied
          ? 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 hover:bg-[color:var(--color-status-warning)]/15'
          : 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 hover:bg-[color:var(--color-status-safe)]/15';

    // Lenguaje preciso por estado:
    //   - Sesión sin orden: "Esperando pedido" / "N comensales en la mesa".
    //   - Sesión + orden activa: status badge prominente + "N comensales · M items".
    //   - Ocupada tradicional (caja): items + total + status.
    //   - Disponible: CTA "Abrir mesa".
    // Antes decía "0 comensales pidiendo" para sesiones recién abiertas que
    // aún nadie había usado para pedir — confuso para el cajero que veía
    // mesas marcadas como activas sin actividad real.
    const sessionGuestsCopy = (() => {
        if (!groupSession) return '';
        const n = groupSession.guestsCount;
        if (n === 0) return 'Comensales por unirse';
        return `${n} ${n === 1 ? 'comensal' : 'comensales'} en la mesa`;
    })();

    const content = (
        <>
            <div className="flex w-full flex-col items-start gap-1">
                <span className="w-full truncate text-base font-semibold tracking-tight">Mesa {number}</span>
                {inGroupSession ? (
                    <div className="flex flex-wrap items-center gap-1">
                        <Badge
                            variant="secondary"
                            className="bg-[color:var(--color-status-info)]/15 px-2 py-0 text-[11px] text-[color:var(--color-status-info)]"
                        >
                            En sesión
                        </Badge>
                        {groupSession.pendingApprovalCount > 0 && (
                            <Badge className="border-transparent bg-[color:var(--color-status-critical)]/15 px-2 py-0 text-[11px] text-[color:var(--color-status-critical)]">
                                {groupSession.pendingApprovalCount} por aprobar
                            </Badge>
                        )}
                        {groupSession.hasActiveOrder && statusLabel && (
                            <Badge variant="warning" className="px-2 py-0 text-[11px]">
                                {statusLabel}
                            </Badge>
                        )}
                    </div>
                ) : (
                    <Badge variant={occupied ? 'warning' : 'safe'} className="px-2 py-0 text-[11px]">
                        {occupied ? 'Ocupada' : 'Disponible'}
                    </Badge>
                )}
            </div>

            {inGroupSession ? (
                <div className="flex w-full flex-col gap-1 text-xs">
                    <span className="inline-flex items-center gap-1 font-medium text-[color:var(--color-status-info)]">
                        <Users className="h-3 w-3" />
                        {sessionGuestsCopy}
                    </span>
                    {groupSession.hasActiveOrder ? (
                        <>
                            <span className="text-muted-foreground">
                                {groupSession.itemsInFlight} {groupSession.itemsInFlight === 1 ? 'item en preparación' : 'items en preparación'}
                            </span>
                            {total && <span className="text-foreground font-medium tabular-nums">{total}</span>}
                        </>
                    ) : (
                        <span className="text-muted-foreground italic">Esperando pedido</span>
                    )}
                </div>
            ) : occupied ? (
                <div className="flex flex-col gap-0.5 text-xs">
                    <span className="text-muted-foreground">
                        {itemCount ?? 0} {itemCount === 1 ? 'item' : 'items'}
                    </span>
                    {total && <span className="text-foreground font-medium tabular-nums">{total}</span>}
                    {statusLabel && (
                        <Badge variant="warning" className="mt-1 self-start px-2 py-0 text-[11px]">
                            {statusLabel}
                        </Badge>
                    )}
                </div>
            ) : (
                <div className="flex items-center gap-1 text-xs font-medium text-[color:var(--color-status-safe)]">
                    {availableCta ?? (
                        <>
                            <Plus className="h-3 w-3" /> Abrir mesa
                        </>
                    )}
                </div>
            )}
        </>
    );

    // Cuando la mesa está en sesión grupal y hay una acción de liberar, el
    // wrapper es un <div role="button"> para evitar el `<button>` anidado del
    // releaseAction. Si no hay acción, el clásico <button> sigue siendo
    // accesible con teclado.
    if (inGroupSession && groupSession.releaseAction) {
        return (
            <div className={cn('group flex min-h-[7rem] flex-col items-start gap-2 rounded-lg border p-3 text-left transition', tone, className)}>
                <div
                    role={onClick ? 'button' : undefined}
                    tabIndex={onClick ? 0 : undefined}
                    onClick={onClick}
                    onKeyDown={
                        onClick
                            ? (e) => {
                                  if (e.key === 'Enter' || e.key === ' ') {
                                      e.preventDefault();
                                      onClick();
                                  }
                              }
                            : undefined
                    }
                    className={cn('flex w-full flex-col gap-2', onClick && 'focus:ring-ring focus:ring-2 focus:outline-none')}
                >
                    {content}
                </div>
                <div className="mt-1 w-full">{groupSession.releaseAction}</div>
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'group focus:ring-ring flex min-h-[7rem] flex-col items-start gap-2 rounded-lg border p-3 text-left transition focus:ring-2 focus:outline-none',
                tone,
                className,
            )}
        >
            {content}
        </button>
    );
}
