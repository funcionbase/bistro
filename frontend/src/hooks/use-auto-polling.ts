import { useEffect, useRef } from 'react';

interface Options {
    /** Intervalo en ms entre ticks consecutivos. */
    intervalMs: number;
    /** Callback ejecutado en cada tick. Puede ser async. */
    onTick: () => void | Promise<void>;
    /**
     * Si `true` (default), pausa el polling cuando la pestaña deja de estar
     * visible. Recomendado para vistas tipo dashboard / KDS donde no tiene
     * sentido seguir golpeando el backend con la tableta dormida.
     */
    pauseWhenHidden?: boolean;
    /** Si `false`, el hook no inicia polling. Default `true`. */
    enabled?: boolean;
}

/**
 * Polling continuo sin botón de toggle, pensado para vistas kiosk (KDS
 * standalone). A diferencia de `useLivePolling` (que requiere activación
 * manual y se apaga a los 5 min), `useAutoPolling` mantiene el ritmo todo el
 * tiempo que el componente está montado y la pestaña visible.
 *
 * Reutilizable desde cualquier pantalla que se ejecute de fondo en una
 * tableta fija o monitor.
 */
export function useAutoPolling({ intervalMs, onTick, pauseWhenHidden = true, enabled = true }: Options): void {
    const onTickRef = useRef(onTick);
    onTickRef.current = onTick;

    useEffect(() => {
        if (!enabled) return;

        let cancelled = false;
        let timer: ReturnType<typeof setInterval> | null = null;
        let jitterTimer: ReturnType<typeof setTimeout> | null = null;

        const start = () => {
            if (timer !== null) return;
            timer = setInterval(() => {
                if (cancelled) return;
                void onTickRef.current();
            }, intervalMs);
        };

        const stop = () => {
            // Cancela también el jitter pendiente: si la pestaña se vuelve a
            // ocultar dentro de la ventana de 0–500ms, su setTimeout dispararía
            // un tick fantasma (start + onTick) con la pestaña ya oculta.
            if (jitterTimer !== null) {
                clearTimeout(jitterTimer);
                jitterTimer = null;
            }
            if (timer === null) return;
            clearInterval(timer);
            timer = null;
        };

        const visibilityHandler = () => {
            if (!pauseWhenHidden) return;
            if (typeof document === 'undefined') return;
            if (document.hidden) {
                stop();
            } else {
                // Jitter aleatorio (0–500ms) al despertar la pestaña para
                // evitar thundering herd: si 10 tabletas se despiertan al
                // mismo tiempo, todas hitearían el backend en el mismo tick.
                // Lo suficientemente corto para no percibir la espera.
                const jitterMs = Math.floor(Math.random() * 500);
                jitterTimer = setTimeout(() => {
                    jitterTimer = null;
                    if (cancelled || (pauseWhenHidden && typeof document !== 'undefined' && document.hidden)) return;
                    start();
                    void onTickRef.current();
                }, jitterMs);
            }
        };

        start();

        if (pauseWhenHidden && typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', visibilityHandler);
        }

        return () => {
            cancelled = true;
            stop();
            if (pauseWhenHidden && typeof document !== 'undefined') {
                document.removeEventListener('visibilitychange', visibilityHandler);
            }
        };
    }, [enabled, intervalMs, pauseWhenHidden]);
}
