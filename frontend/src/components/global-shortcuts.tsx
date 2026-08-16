import { ShortcutPalette } from '@/components/shortcut-palette';
import { ShortcutsHelpModal } from '@/components/shortcuts-help-modal';
import { isFocusInInput } from '@/hooks/use-keyboard-shortcut';
import { APP_SHORTCUTS, LEADER_KEY, SEQUENCE_TIMEOUT_MS } from '@/lib/shortcuts';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

/** Tiempo (ms) que hay que sostener la tecla líder antes de abrir el palette. */
const PALETTE_HOLD_MS = 350;

/**
 * Motor global de atajos de teclado del shell autenticado (rediseño
 * anti-conflictos). Montado una sola vez en `spa-app-layout.tsx`.
 *
 * Implementa el patrón "go to" con tecla líder en dos modos complementarios:
 *  - **Tap rápido:** pulsar `G` y luego la tecla del destino (p.ej. `G` `D` →
 *    Dashboard). La secuencia queda armada `SEQUENCE_TIMEOUT_MS` tras soltar.
 *  - **Sostener:** mantener `G` pulsada abre, tras `PALETTE_HOLD_MS`, un overlay
 *    que oscurece la UI y muestra los destinos; se elige sin soltar `G`, o se
 *    cierra al soltar / con `Esc`.
 *
 * Al no usar modificadores (Ctrl/Alt/Cmd) y solo disparar fuera de inputs, no se
 * cruza con atajos del navegador ni del SO. También gestiona `?` (modal de
 * ayuda). El toggle de la barra lateral (`Ctrl/Cmd + .`) lo maneja `sidebar.tsx`.
 */
export function GlobalShortcuts() {
    const navigate = useNavigate();
    const [helpOpen, setHelpOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);

    // Tecla líder pendiente (p.ej. 'G') a la espera de la segunda tecla.
    const pendingRef = useRef<string | null>(null);
    // ¿La tecla líder está físicamente sostenida ahora mismo?
    const leaderHeldRef = useRef(false);
    // Timer de gracia tras soltar la líder (modo tap) y timer de apertura del palette.
    const graceTimerRef = useRef<number | null>(null);
    const paletteTimerRef = useRef<number | null>(null);

    useEffect(() => {
        function clearGraceTimer() {
            if (graceTimerRef.current !== null) {
                window.clearTimeout(graceTimerRef.current);
                graceTimerRef.current = null;
            }
        }

        function clearPaletteTimer() {
            if (paletteTimerRef.current !== null) {
                window.clearTimeout(paletteTimerRef.current);
                paletteTimerRef.current = null;
            }
        }

        /** Cancela la secuencia por completo y cierra el palette. */
        function resetSequence() {
            pendingRef.current = null;
            leaderHeldRef.current = false;
            clearGraceTimer();
            clearPaletteTimer();
            setPaletteOpen(false);
        }

        function onKeyDown(event: KeyboardEvent) {
            // Acordes con modificador (browser/SO) y foco en inputs: no nos conciernen.
            if (event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }
            // Escribiendo en un input/textarea/select/contenteditable: NUNCA armamos
            // ni navegamos. Si veníamos con una secuencia armada (la líder se pulsó
            // fuera y el foco saltó a un campo), la cancelamos: el usuario escribe,
            // no navega. Cubre el caso de teclear "g..." dentro de un input.
            if (isFocusInInput(event.target)) {
                if (pendingRef.current) {
                    resetSequence();
                }
                return;
            }

            const key = event.key.length === 1 ? event.key.toUpperCase() : event.key;

            // ¿Secuencia en curso (líder ya pulsada)?
            if (pendingRef.current) {
                // Auto-repeat o re-pulsación de la líder mientras está armada: mantener estado.
                if (key === LEADER_KEY) {
                    return;
                }
                const leader = pendingRef.current;
                resetSequence();
                const match = APP_SHORTCUTS.find(
                    (shortcut) => !shortcut.chord && shortcut.keys.length === 2 && shortcut.keys[0] === leader && shortcut.keys[1] === key,
                );
                if (match?.route) {
                    event.preventDefault();
                    navigate(match.route);
                }
                return;
            }

            // Tecla suelta: ayuda (`?` ya incluye Shift en event.key).
            if (event.key === '?') {
                event.preventDefault();
                setHelpOpen((open) => !open);
                return;
            }

            // Tecla líder: arrancar secuencia + programar apertura del palette si se sostiene.
            if (key === LEADER_KEY) {
                if (event.repeat) {
                    return;
                }
                pendingRef.current = LEADER_KEY;
                leaderHeldRef.current = true;
                clearGraceTimer();
                clearPaletteTimer();
                paletteTimerRef.current = window.setTimeout(() => {
                    if (pendingRef.current === LEADER_KEY && leaderHeldRef.current) {
                        setPaletteOpen(true);
                    }
                }, PALETTE_HOLD_MS);
            }
        }

        function onKeyUp(event: KeyboardEvent) {
            const key = event.key.length === 1 ? event.key.toUpperCase() : event.key;
            if (key !== LEADER_KEY) {
                return;
            }
            leaderHeldRef.current = false;
            clearPaletteTimer();
            setPaletteOpen(false);
            // Mantener la secuencia armada un instante para el modo "tap": soltar G y
            // luego pulsar el destino sigue funcionando.
            if (pendingRef.current) {
                clearGraceTimer();
                graceTimerRef.current = window.setTimeout(resetSequence, SEQUENCE_TIMEOUT_MS);
            }
        }

        // Si la ventana pierde foco (Alt+Tab) con G sostenida, el keyup puede no
        // llegar — limpiamos para no dejar el palette pegado.
        function onBlur() {
            resetSequence();
        }

        // Si un input gana el foco con una secuencia activa (p.ej. clic en un
        // campo mientras se sostiene G), cancelamos de inmediato.
        function onFocusIn(event: FocusEvent) {
            if (pendingRef.current && isFocusInInput(event.target)) {
                resetSequence();
            }
        }

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('keyup', onKeyUp);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focusin', onFocusIn);
        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('keyup', onKeyUp);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focusin', onFocusIn);
            resetSequence();
        };
    }, [navigate]);

    return (
        <>
            <ShortcutPalette open={paletteOpen} />
            <ShortcutsHelpModal isOpen={helpOpen} onClose={() => setHelpOpen(false)} />
        </>
    );
}
