import { useEffect } from 'react';

export type ShortcutKey = 'Ctrl' | 'Alt' | 'Shift' | 'Cmd' | string;

export interface ShortcutDefinition {
    /** Sequence of keys, e.g. ['Ctrl','.'] or ['?']. */
    keys: ShortcutKey[];
    /** Handler invoked when the shortcut fires. */
    handler: (event: KeyboardEvent) => void;
    /** Short description for the help modal. */
    description: string;
    /** When true, allow firing while focus is in inputs/textareas/contenteditable. Default false. */
    allowInInput?: boolean;
}

/**
 * Combinaciones (acordes con modificador) **reservadas** por el navegador o el
 * sistema operativo — usarlas para un atajo de la app es un error: el navegador
 * o el SO las captura antes de llegar a la página, o producen un efecto
 * inesperado. La navegación de la app evita el problema de raíz usando
 * secuencias sin modificador (ver `lib/shortcuts.ts`); esta lista valida los
 * pocos acordes que sí quedan y sirve de referencia para no reintroducir
 * conflictos.
 *
 * Normalizadas con `normalizeKeys` (orden Ctrl > Alt > Shift > tecla). `Cmd` se
 * mapea a `Ctrl` porque tratamos `metaKey` y `ctrlKey` como equivalentes.
 */
export const RESERVED_SHORTCUTS: ReadonlyArray<string> = Object.freeze([
    // ── Navegador: pestañas y ventanas ──
    'Ctrl+N',
    'Ctrl+Shift+N',
    'Ctrl+T',
    'Ctrl+Shift+T',
    'Ctrl+W',
    'Ctrl+Shift+W',
    'Ctrl+Tab',
    'Ctrl+Shift+Tab',
    'Ctrl+1',
    'Ctrl+2',
    'Ctrl+3',
    'Ctrl+4',
    'Ctrl+5',
    'Ctrl+6',
    'Ctrl+7',
    'Ctrl+8',
    'Ctrl+9',
    // ── Navegador: barra de direcciones / búsqueda / navegación ──
    'Ctrl+L',
    'Alt+D',
    'F6',
    'Ctrl+K',
    'Ctrl+E',
    'Alt+Home',
    'Alt+ArrowLeft', // atrás
    'Alt+ArrowRight', // adelante
    // ── Navegador: página, zoom, historial, marcadores, DevTools ──
    'Ctrl+P',
    'Ctrl+S',
    'Ctrl+F',
    'Ctrl+G',
    'Ctrl+Shift+G',
    'Ctrl+U',
    'Ctrl+D', // marcador (Chrome/Edge)
    'Ctrl+Shift+D',
    'Ctrl+B', // barra de marcadores (Firefox); evitar
    'Ctrl+Shift+B',
    'Ctrl+H',
    'Ctrl+J',
    'Ctrl+0',
    'Ctrl+Plus',
    'Ctrl+Minus',
    'F3',
    'F11',
    'F12',
    'Ctrl+Shift+I',
    'Ctrl+Shift+J',
    'Ctrl+Shift+C',
    'Ctrl+R',
    'Ctrl+Shift+R',
    'F5',
    // ── Sistema operativo (Windows / macOS) ──
    'Alt+F4', // cerrar ventana (Windows)
    'Alt+Tab', // cambiar app (Windows)
    'Ctrl+Alt+Delete',
    'Ctrl+Shift+Escape',
]);

/** @deprecated Renombrada a `RESERVED_SHORTCUTS`. Alias por compatibilidad. */
export const CHROME_RESERVED_SHORTCUTS = RESERVED_SHORTCUTS;

/**
 * Normaliza una secuencia de teclas a una clave canónica para comparación.
 * Mantiene el orden Ctrl > Alt > Shift > letra/función. `Cmd` cuenta como `Ctrl`.
 */
export function normalizeKeys(keys: ShortcutKey[]): string {
    const order: Record<string, number> = { Ctrl: 0, Cmd: 0, Alt: 1, Shift: 2 };
    const sorted = [...keys].sort((a, b) => {
        const ai = order[a] ?? 99;
        const bi = order[b] ?? 99;
        return ai - bi;
    });
    return sorted.map((k) => (k === 'Cmd' ? 'Ctrl' : k.length === 1 ? k.toUpperCase() : k)).join('+');
}

/** Devuelve true si la combinación (acorde) está reservada por navegador/SO. */
export function isReserved(keys: ShortcutKey[]): boolean {
    return RESERVED_SHORTCUTS.includes(normalizeKeys(keys));
}

/** @deprecated Renombrada a `isReserved`. */
export const isChromeReserved = isReserved;

function eventToCombo(event: KeyboardEvent): string {
    const parts: string[] = [];
    if (event.ctrlKey || event.metaKey) parts.push('Ctrl');
    if (event.altKey) parts.push('Alt');
    if (event.shiftKey) parts.push('Shift');
    const key = event.key;
    if (key && !['Control', 'Alt', 'Shift', 'Meta'].includes(key)) {
        parts.push(key.length === 1 ? key.toUpperCase() : key);
    }
    return parts.join('+');
}

export function isFocusInInput(target: EventTarget | null): boolean {
    if (!target || !(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (target.isContentEditable) return true;
    return false;
}

/**
 * Registra un atajo de teclado global (acorde con modificador). Si la
 * combinación está reservada por el navegador o el SO, emite warning en consola
 * (solo en desarrollo) y NO registra el listener.
 *
 * La navegación de la app NO usa este hook — usa el motor de secuencias de
 * `components/global-shortcuts.tsx`. Este hook queda disponible para acordes
 * puntuales (p.ej. atajos de acción dentro de una pantalla).
 */
export function useKeyboardShortcut(definition: ShortcutDefinition): void {
    const { keys, handler, allowInInput = false } = definition;

    useEffect(() => {
        if (isReserved(keys)) {
            if (import.meta.env.DEV) {
                console.warn(`[useKeyboardShortcut] Combinación "${normalizeKeys(keys)}" reservada por el navegador/SO — atajo NO registrado.`);
            }
            return;
        }

        const target = normalizeKeys(keys);

        function listener(event: KeyboardEvent) {
            if (!allowInInput && isFocusInInput(event.target)) return;
            if (eventToCombo(event) === target) {
                event.preventDefault();
                handler(event);
            }
        }

        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [keys.join('+'), handler, allowInInput]);
}
