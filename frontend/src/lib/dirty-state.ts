/**
 * Registro global de "trabajo sin guardar" (#192 Fase 3.2).
 *
 * Las páginas con formularios o editores en uso registran un ticket
 * (un símbolo único) mientras están en estado dirty. El `BranchSwitcher`
 * (y futuros componentes de navegación crítica) consulta `isAnyDirty()`
 * antes de ejecutar un cambio de contexto para pedir confirmación.
 *
 * El registro vive en memoria del cliente — no se persiste; tener una
 * página abierta sin guardar es un estado de UX, no de servidor. Si el
 * usuario refresca la página, el ticket desaparece (junto con los
 * cambios), que es exactamente lo que el navegador hace antes con
 * `beforeunload`.
 *
 * Patrón: cada caller obtiene un símbolo único en mount y lo libera en
 * unmount. Se setea dirty/clean vía `setDirty(ticket, true|false)`.
 */

const dirtyTickets = new Set<symbol>();
const target = new EventTarget();

export function registerDirtyTicket(label?: string): symbol {
    return Symbol(label ?? 'dirty');
}

export function setDirty(ticket: symbol, dirty: boolean): void {
    if (dirty) {
        if (!dirtyTickets.has(ticket)) {
            dirtyTickets.add(ticket);
            target.dispatchEvent(new Event('change'));
        }
    } else if (dirtyTickets.has(ticket)) {
        dirtyTickets.delete(ticket);
        target.dispatchEvent(new Event('change'));
    }
}

export function releaseDirtyTicket(ticket: symbol): void {
    if (dirtyTickets.delete(ticket)) {
        target.dispatchEvent(new Event('change'));
    }
}

export function isAnyDirty(): boolean {
    return dirtyTickets.size > 0;
}

export function subscribeDirty(listener: () => void): () => void {
    target.addEventListener('change', listener);
    return () => target.removeEventListener('change', listener);
}
