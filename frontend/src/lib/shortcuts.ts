/**
 * Mapa central de atajos de teclado de la aplicación (issue #50, rediseño anti-conflictos).
 *
 * ## Por qué secuencias con tecla líder `G`
 *
 * El esquema anterior (`Alt + <letra>`) chocaba con comandos del navegador y del
 * sistema operativo:
 *  - **macOS**: `Option + <letra>` inserta caracteres especiales (µ, π, ∂…).
 *  - **Firefox / Edge / Windows**: `Alt + <letra>` activa los mnemónicos de menú.
 *  - **Chrome**: `Alt + D` enfoca la barra de direcciones, `Alt + Home` va al inicio.
 *
 * La solución es el patrón **"go to" con secuencias** (GitHub, Gmail, Linear):
 * se pulsa la tecla líder `G` y, a continuación, la tecla del destino — p.ej.
 * `G` luego `D` → Dashboard. Como NO usan modificadores (Ctrl/Alt/Cmd) y solo
 * disparan cuando el foco no está en un input, **no se cruzan** con atajos del
 * navegador, de Windows/macOS ni de otros programas.
 *
 * El motor de secuencias vive en `components/global-shortcuts.tsx`. Las
 * combinaciones con modificador (chords) que quedan se validan contra
 * `RESERVED_SHORTCUTS` (`hooks/use-keyboard-shortcut.ts`).
 *
 * Mantener orden lógico, descripciones cortas y segundas teclas únicas.
 */

/**
 * Delay de hover en ms antes de mostrar cualquier tooltip de la app (issue #50).
 * Aplica tanto al `ShortcutTooltip` propio como al `delayDuration` del
 * `TooltipProvider` de Radix UI usado en sidebar, header y demás componentes.
 */
export const TOOLTIP_DELAY_MS = 3000;

/** Tecla líder del patrón "go to" — se pulsa antes del destino. */
export const LEADER_KEY = 'G';

/** Ventana (ms) para pulsar la segunda tecla tras la líder antes de cancelar. */
export const SEQUENCE_TIMEOUT_MS = 1500;

export interface AppShortcut {
    /**
     * Teclas del atajo.
     *  - Por defecto (`chord` ausente/false) son una **secuencia**: se pulsan en
     *    orden, p.ej. `['G','D']` = pulsar `G` y luego `D`.
     *  - Con `chord: true` son un **acorde**: se pulsan a la vez, p.ej.
     *    `['Ctrl','.']`.
     *  - Un solo elemento (`['?']`) es una tecla suelta.
     */
    keys: string[];
    /** Description shown in the help modal and tooltips. */
    description: string;
    /** Categoría agrupadora. */
    category: 'Navegación' | 'Productividad' | 'Ayuda';
    /** Ruta a la que navega (para atajos de navegación en secuencia). */
    route?: string;
    /** true => las teclas se pulsan simultáneamente (acorde), no en secuencia. */
    chord?: boolean;
}

export const APP_SHORTCUTS: ReadonlyArray<AppShortcut> = Object.freeze([
    // ── Navegación — secuencia: pulsar `G` y luego la tecla del destino ──
    { keys: ['G', 'D'], description: 'Ir al Dashboard', category: 'Navegación', route: '/dashboard' },
    { keys: ['G', 'O'], description: 'Órdenes › Tablero', category: 'Navegación', route: '/orders/board' },
    { keys: ['G', 'C'], description: 'Órdenes › Caja', category: 'Navegación', route: '/orders/cashier' },
    { keys: ['G', 'V'], description: 'Órdenes › Ventas del día', category: 'Navegación', route: '/orders/deliveries' },
    { keys: ['G', 'M'], description: 'Ir a Menú', category: 'Navegación', route: '/menu' },
    { keys: ['G', 'S'], description: 'Ir a Chats', category: 'Navegación', route: '/chats' },
    { keys: ['G', 'P'], description: 'Ir a Cupones', category: 'Navegación', route: '/coupons' },
    { keys: ['G', 'H'], description: 'Ir a Horarios', category: 'Navegación', route: '/hours' },
    { keys: ['G', 'E'], description: 'Mi Empresa › Información', category: 'Navegación', route: '/company/settings' },
    { keys: ['G', 'F'], description: 'Mi Empresa › Configuraciones', category: 'Navegación', route: '/company/preferences' },
    { keys: ['G', 'T'], description: 'Mi Empresa › Métricas', category: 'Navegación', route: '/company/metrics' },
    { keys: ['G', 'R'], description: 'Mi Empresa › Informes', category: 'Navegación', route: '/company/reports' },
    { keys: ['G', 'U'], description: 'Identidades › Usuarios', category: 'Navegación', route: '/identities/users' },
    { keys: ['G', 'L'], description: 'Identidades › Roles', category: 'Navegación', route: '/identities/roles' },

    // ── Productividad ──
    { keys: ['Ctrl', '.'], description: 'Mostrar/ocultar barra lateral', category: 'Productividad', chord: true },

    // ── Ayuda ──
    { keys: ['?'], description: 'Mostrar atajos disponibles', category: 'Ayuda' },
]);
