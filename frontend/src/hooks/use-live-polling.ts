import { useEffect, useRef, useState } from 'react';

/**
 * Polling controlado por el usuario. Por defecto desactivado.
 * Cuando se activa, dispara `onTick` cada `intervalMs` y se apaga
 * automaticamente despues de 5 minutos para no saturar el backend.
 */
const AUTO_OFF_MS = 5 * 60 * 1000;

interface Options {
    intervalMs: number;
    onTick: () => void | Promise<void>;
}

interface Return {
    enabled: boolean;
    toggle: () => void;
    activatedAt: number | null;
    autoOffMs: number;
    intervalMs: number;
}

export function useLivePolling({ intervalMs, onTick }: Options): Return {
    const [enabled, setEnabled] = useState(false);
    const [activatedAt, setActivatedAt] = useState<number | null>(null);
    const onTickRef = useRef(onTick);
    onTickRef.current = onTick;

    useEffect(() => {
        if (!enabled) return;
        const interval = setInterval(() => {
            if (document.hidden) return; // pestaña oculta: no gastar backend
            void onTickRef.current();
        }, intervalMs);
        const autoOff = setTimeout(() => {
            setEnabled(false);
            setActivatedAt(null);
        }, AUTO_OFF_MS);
        return () => {
            clearInterval(interval);
            clearTimeout(autoOff);
        };
    }, [enabled, intervalMs]);

    const toggle = () =>
        setEnabled((prev) => {
            const next = !prev;
            setActivatedAt(next ? Date.now() : null);
            return next;
        });

    return { enabled, toggle, activatedAt, autoOffMs: AUTO_OFF_MS, intervalMs };
}
