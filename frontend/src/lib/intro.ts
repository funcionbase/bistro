/**
 * Coordinación con el intro de carga del shell (`index.html` → #intro-shell).
 *
 * El intro pinta con el primer byte del HTML y su salida espera a que la app
 * avise que está lista (`notifyIntroReady`), con mínimo de exhibición y
 * fallback duro — ver el script inline en index.html.
 */

/**
 * Marca que la PRÓXIMA carga completa es una transición dentro del flujo de
 * autenticación (hoy: selección de empresa → dashboard): el shell muestra el
 * intro en variante verde branding (#c0fd79) en cualquier ruta, cubriendo el
 * bootstrap/API de la app. Flag de un solo uso (el shell lo consume al leerlo).
 */
export function markLoginIntro(): void {
    try {
        sessionStorage.setItem('ff.intro', 'green');
    } catch {
        // sin sessionStorage (modo privado antiguo) — el intro simplemente no sale
    }
}

/** Avisa al shell que la app ya montó/cargó datos — inicia la salida del intro. */
export function notifyIntroReady(): void {
    (window as unknown as { __introReady?: () => void }).__introReady?.();
}
