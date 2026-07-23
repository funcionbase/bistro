import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

/** El badge no necesita ser inmediato: el push ya cubre la inmediatez. */
const POLL_MS = 60_000;
const SOUND_KEY = 'chats:sound-enabled';

interface UseChatNotificationsReturn {
    pending: number;
    soundEnabled: boolean;
    setSoundEnabled: (enabled: boolean) => void;
    refresh: () => Promise<void>;
}

/** El sonido se recuerda POR DISPOSITIVO: en una cocina, uno sí y en caja no. */
export function readSoundPreference(): boolean {
    try {
        return localStorage.getItem(SOUND_KEY) === '1';
    } catch {
        return false;
    }
}

export function writeSoundPreference(enabled: boolean): void {
    try {
        localStorage.setItem(SOUND_KEY, enabled ? '1' : '0');
    } catch {
        // Modo privado o storage lleno: la preferencia dura la sesión.
    }
}

/**
 * Beep corto por WebAudio.
 *
 * ponytail: oscilador en vez de un archivo de audio. Evita meter un binario al
 * repo y un request más al cargar la app, por un sonido de 150 ms que nadie va a
 * pedir personalizar. Si algún día se quiere un sonido de marca, se cambia acá.
 */
function playBeep(): void {
    try {
        const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
        if (!Ctx) return;

        const ctx = new Ctx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.value = 880;
        // Rampa de salida: un corte seco suena a click roto en algunos parlantes.
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.15);

        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.16);
        osc.onended = () => void ctx.close();
    } catch {
        // El browser puede bloquear audio sin gesto previo del usuario. No es
        // un error que valga la pena mostrar: el badge y el título ya avisaron.
    }
}

/**
 * Conversaciones sin responder: badge del sidebar, título de pestaña y sonido
 * opcional (§8.4b punto 1).
 *
 * Vive FUERA de la bandeja a propósito. El operador tiene el panel en otra
 * pestaña o en otra pantalla del sistema — que es exactamente cuando el aviso
 * hace falta — así que el poll cuelga del layout, no de `/chats`.
 *
 * El sonido arranca APAGADO. En una cocina un sonido sorpresa es peor que
 * ninguno: lo silencian una vez y ya nunca lo vuelven a prender.
 */
export function useChatNotifications(enabled: boolean): UseChatNotificationsReturn {
    const [pending, setPending] = useState(0);
    const [soundEnabled, setSoundEnabledState] = useState(readSoundPreference);
    const previousRef = useRef<number | null>(null);

    const refresh = useCallback(async (): Promise<void> => {
        if (!enabled) return;
        try {
            const res = await apiFetch('/api/v1/chats/pending-count');
            if (!res.ok) return;
            const json = await res.json();
            setPending((json as { data: { pending: number } }).data.pending ?? 0);
        } catch {
            // Silencioso: es un contador, no vale una alerta en pantalla.
        }
    }, [enabled]);

    useEffect(() => {
        if (!enabled) return;

        void refresh();
        const interval = setInterval(() => {
            if (document.hidden) {
                // Con la pestaña oculta el push es el canal que avisa; seguir
                // polleando gastaría backend para un badge que nadie ve.
                return;
            }
            void refresh();
        }, POLL_MS);

        // Al volver a la pestaña se refresca de inmediato: el operador acaba de
        // mirar y el número tiene que estar bien AHORA, no en 60 s.
        const onVisibility = () => {
            if (document.visibilityState === 'visible') void refresh();
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [enabled, refresh]);

    // Título de pestaña: "(3) …". Es donde mira el operador que dejó el panel
    // de fondo, y no cuesta nada mantenerlo.
    useEffect(() => {
        if (!enabled) return;

        const base = document.title.replace(/^\(\d+\)\s*/, '');
        document.title = pending > 0 ? `(${pending}) ${base}` : base;
    }, [pending, enabled]);

    // Sonido solo cuando el contador SUBE. Con `pending` a secas sonaría en cada
    // poll mientras haya pendientes, que es la forma más rápida de que lo apaguen.
    //
    // La preferencia se relee de localStorage en el momento de sonar, no del
    // estado: así el toggle puede vivir en la página de chats mientras el beep
    // lo dispara este hook (montado en el sidebar), sin cablear un contexto.
    useEffect(() => {
        const previous = previousRef.current;
        previousRef.current = pending;

        if (previous === null || pending <= previous) return;
        if (readSoundPreference()) playBeep();
    }, [pending]);

    const setSoundEnabled = useCallback((next: boolean) => {
        setSoundEnabledState(next);
        writeSoundPreference(next);
    }, []);

    return { pending, soundEnabled, setSoundEnabled, refresh };
}
