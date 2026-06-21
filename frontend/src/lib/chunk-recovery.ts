/**
 * Recovery automático contra "Failed to fetch dynamically imported module".
 *
 * Causa: el navegador tiene una pestaña vieja con referencias a chunks que
 * el bundler ya invalidó (rebuild de Vite o deploy en PDN que cambia los
 * hashes). Cuando React Router intenta lazy-cargar la ruta destino, el
 * fetch del .js falla con `TypeError` / `ChunkLoadError` y el árbol cae
 * al `errorElement` mostrando una pantalla de error técnica.
 *
 * Solución: detectar específicamente esos errores y forzar un hard reload
 * UNA SOLA VEZ. Si tras el reload sigue fallando (poco probable — sería
 * un bug real del bundle), recién mostrar la pantalla de error.
 *
 * Anti-loop: `sessionStorage` marca que ya intentamos recovery en esta
 * sesión de pestaña; al cargar bien, el flag se borra automáticamente
 * tras 10s para que un nuevo chunk error fresco pueda recuperar también.
 */

const RECOVERY_FLAG = 'flexyflow:chunk-recovery-attempted';
const RECOVERY_CLEAR_MS = 10_000;

/**
 * Detecta si el error es un fallo de chunk dinámico (recoverable con reload).
 *
 * Patrones que matchean: ChunkLoadError (webpack/rspack), error.name con
 * "ChunkLoadError", message con "Failed to fetch dynamically imported module"
 * (Vite/native dynamic import), "Importing a module script failed" (Firefox),
 * "error loading dynamically imported module" (Safari).
 */
export function isChunkLoadError(error: unknown): boolean {
    if (!(error instanceof Error)) return false;
    const msg = error.message ?? '';
    const name = error.name ?? '';
    return (
        name === 'ChunkLoadError' ||
        msg.includes('Failed to fetch dynamically imported module') ||
        msg.includes('Importing a module script failed') ||
        msg.includes('error loading dynamically imported module') ||
        msg.includes('Loading chunk ')
    );
}

/**
 * Marca el flag de recovery y recarga. Si ya estamos en un ciclo de
 * recovery (flag presente), no recarga — deja que el caller muestre el
 * error real para que el usuario reporte un bug del bundle.
 *
 * @returns true si disparó reload, false si ya hubo intento previo.
 */
export function attemptChunkRecovery(): boolean {
    try {
        if (sessionStorage.getItem(RECOVERY_FLAG)) {
            // Ya intentamos recuperar en esta sesión, no loopear.
            return false;
        }
        sessionStorage.setItem(RECOVERY_FLAG, String(Date.now()));
    } catch {
        // sessionStorage puede fallar en modo private; aún así recargamos
        // — un loop infinito es preferible a una pantalla de error si la
        // raíz fue un chunk obsoleto.
    }
    window.location.reload();
    return true;
}

/**
 * Listener global instalado al boot del SPA. Captura:
 *  - `vite:preloadError`: evento dedicado que Vite emite al fallar un
 *    preload de chunk (best signal en build de prod).
 *  - `unhandledrejection`: promesas rejectadas con ChunkLoadError —
 *    típicamente el dynamic import del lazy route.
 *  - `error`: errores síncronos sueltos por si algún chunk falla de forma
 *    no-Promise.
 *
 * En todos los casos `preventDefault()` para evitar que el browser muestre
 * la pantalla de error nativa antes del reload.
 */
export function installChunkRecoveryHandlers(): void {
    // Vite-specific: el evento se dispara antes que el rejection propague.
    // Solo prevenimos el default si realmente vamos a recuperar — si el flag ya
    // está puesto y no recargamos, dejar que el navegador muestre el error.
    window.addEventListener('vite:preloadError', (event) => {
        if (attemptChunkRecovery()) {
            event.preventDefault();
        }
    });

    window.addEventListener('unhandledrejection', (event) => {
        if (isChunkLoadError(event.reason)) {
            if (attemptChunkRecovery()) {
                event.preventDefault();
            }
        }
    });

    window.addEventListener('error', (event) => {
        if (isChunkLoadError(event.error)) {
            if (attemptChunkRecovery()) {
                event.preventDefault();
            }
        }
    });

    // Si llegamos al boot exitoso, después de un breve grace period
    // limpiamos el flag — así un chunk error nuevo puede recuperar también.
    window.setTimeout(() => {
        try {
            sessionStorage.removeItem(RECOVERY_FLAG);
        } catch {
            // ignore
        }
    }, RECOVERY_CLEAR_MS);
}
