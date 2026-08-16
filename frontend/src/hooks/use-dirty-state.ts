import { isAnyDirty, registerDirtyTicket, releaseDirtyTicket, setDirty, subscribeDirty } from '@/lib/dirty-state';
import { useEffect, useRef, useState } from 'react';

/**
 * Reporta el estado dirty del componente que lo invoca (Fase 3.2).
 *
 * Uso:
 *   const isDirty = formHasChanges();
 *   useDirtyState(isDirty);
 *
 * El BranchSwitcher (y otros consumidores) verá `isAnyDirty()` retornar
 * true mientras al menos un componente reporte dirty. Al desmontar la
 * página, el ticket se libera automáticamente.
 *
 * Por convención cada caller acepta un `label` opcional (string) que
 * sirve solo de hint cuando se inspecciona el Set en DevTools.
 */
export function useDirtyState(dirty: boolean, label?: string): void {
    const ticketRef = useRef<symbol | null>(null);

    if (ticketRef.current === null) {
        ticketRef.current = registerDirtyTicket(label);
    }

    useEffect(() => {
        const ticket = ticketRef.current;
        if (ticket !== null) {
            setDirty(ticket, dirty);
        }
    }, [dirty]);

    useEffect(() => {
        return () => {
            const ticket = ticketRef.current;
            if (ticket !== null) {
                releaseDirtyTicket(ticket);
            }
        };
    }, []);
}

/**
 * Hook reactivo que expone `isAnyDirty()` para que los componentes que
 * lo consumen se re-rendericen cuando cambia el estado global.
 */
export function useIsAnyDirty(): boolean {
    const [dirty, setDirtyState] = useState<boolean>(() => isAnyDirty());

    useEffect(() => {
        return subscribeDirty(() => setDirtyState(isAnyDirty()));
    }, []);

    return dirty;
}
