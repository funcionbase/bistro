import { TOOLTIP_DELAY_MS } from '@/lib/shortcuts';
import { ReactElement, cloneElement, useRef, useState } from 'react';

export interface ShortcutTooltipProps {
    /** Sequence of keys to render, e.g. ['G','D'] (secuencia) o ['Ctrl','.'] (acorde). */
    keys: string[];
    /** Short label shown above the key combination. */
    description: string;
    /** true => acorde (chips unidos por `+`); por defecto secuencia (chips adyacentes). */
    chord?: boolean;
    /** Override the delay in ms (default 3000 per issue #50). */
    delay?: number;
    /** Single-element child whose hover triggers the tooltip. */
    children: ReactElement;
}

/**
 * Envuelve un control y muestra un tooltip con su atajo de teclado tras
 * `delay` ms (3000 por defecto) de hover continuo. El tooltip se cancela
 * si el cursor sale antes o si se hace click.
 *
 * Uso:
 *   <ShortcutTooltip keys={['Alt','M']} description="Ir a Menú">
 *     <button onClick={...}>Menú</button>
 *   </ShortcutTooltip>
 */
export function ShortcutTooltip({ keys, description, chord = false, delay = TOOLTIP_DELAY_MS, children }: ShortcutTooltipProps) {
    const [open, setOpen] = useState(false);
    const timerRef = useRef<number | null>(null);

    function clear() {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }

    function handleEnter() {
        clear();
        timerRef.current = window.setTimeout(() => {
            setOpen(true);
            timerRef.current = null;
        }, delay);
    }

    function handleLeave() {
        clear();
        setOpen(false);
    }

    function handleClick(originalOnClick?: (e: unknown) => void, e?: unknown) {
        clear();
        setOpen(false);
        if (originalOnClick) originalOnClick(e);
    }

    const child = children as ReactElement<{
        onMouseEnter?: (e: unknown) => void;
        onMouseLeave?: (e: unknown) => void;
        onClick?: (e: unknown) => void;
    }>;

    const wrappedChild = cloneElement(child, {
        onMouseEnter: (e: unknown) => {
            handleEnter();
            child.props.onMouseEnter?.(e);
        },
        onMouseLeave: (e: unknown) => {
            handleLeave();
            child.props.onMouseLeave?.(e);
        },
        onClick: (e: unknown) => handleClick(child.props.onClick, e),
    });

    return (
        <span className="relative inline-flex" data-testid="shortcut-tooltip-wrapper">
            {wrappedChild}
            {open && (
                <span
                    role="tooltip"
                    data-testid="shortcut-tooltip"
                    className="pointer-events-none absolute -bottom-12 left-1/2 z-50 flex -translate-x-1/2 items-center gap-1 rounded-md bg-gray-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow-lg"
                >
                    <span className="opacity-80">{description}</span>
                    <span className="ml-2 inline-flex items-center gap-0.5">
                        {keys.map((k, idx) => (
                            <KbdChip key={`${k}-${idx}`} text={k} sep={chord && idx > 0 ? '+' : null} />
                        ))}
                    </span>
                </span>
            )}
        </span>
    );
}

function KbdChip({ text, sep }: { text: string; sep: string | null }) {
    return (
        <>
            {sep && <span className="px-0.5 opacity-60">{sep}</span>}
            <kbd className="rounded border border-gray-600 bg-gray-800 px-1.5 py-0.5 font-mono text-[10px] uppercase">{text}</kbd>
        </>
    );
}

interface ShortcutEntry {
    keys: string[];
    description: string;
    /** true => acorde (chips unidos por `+`); por defecto secuencia (chips adyacentes). */
    chord?: boolean;
}

/** Render una grilla con todos los atajos disponibles, para el modal de ayuda. */
export function ShortcutsList({ shortcuts }: { shortcuts: ShortcutEntry[] }) {
    return (
        <ul className="divide-y divide-gray-200" data-testid="shortcuts-list">
            {shortcuts.map((s) => (
                <li key={s.keys.join('+')} className="flex items-center justify-between gap-4 py-2 text-sm">
                    <span className="text-gray-700">{s.description}</span>
                    <span className="inline-flex items-center gap-0.5">
                        {s.keys.map((k, idx) => (
                            <KbdChip key={`${k}-${idx}`} text={k} sep={s.chord && idx > 0 ? '+' : null} />
                        ))}
                    </span>
                </li>
            ))}
        </ul>
    );
}
