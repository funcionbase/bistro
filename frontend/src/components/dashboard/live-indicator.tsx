import { useRelativeTime } from '@/hooks/use-relative-time';

interface LiveIndicatorProps {
    timestamp?: Date | string;
    /**
     * Gate del dot pulsante. Debe ser true SOLO cuando hay polling activo
     * (auto-refresh corriendo, datos efectivamente vivos). En estado pausado o
     * mientras se carga el primer payload pasar `false` — la pulsacion lime en
     * estados estables viola la regla anti-saturacion (§3) y la politica de
     * motion (§14: "Lime pulse solo cuando polling activo").
     *
     * No tiene default: el caller decide segun su propia senal de loading/poll.
     */
    isLive: boolean;
}

/**
 * Indicador "en vivo / pausado" con dot pulsante y tiempo relativo.
 *
 * - `isLive=true` -> dot lime con `animate-pulse-subtle` (loop infinito de baja
 *   amplitud, ver §14 catalogo). Apaga la pulsacion en cuanto el caller flipea
 *   a false: no dejarlo pulseando en background.
 * - `isLive=false` -> dot gris estatico, copy "Pausado".
 *
 * Ver FRONTEND_UI_GUIDELINES.md §14 (motion) y §3 (anti-saturacion lime).
 */
export default function LiveIndicator({ timestamp, isLive }: LiveIndicatorProps) {
    const relativeTime = useRelativeTime(timestamp);

    return (
        <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
            <span
                className={`h-2 w-2 shrink-0 rounded-full ${
                    isLive ? 'animate-pulse-subtle bg-[color:var(--color-status-safe)]' : 'bg-muted-foreground'
                }`}
                aria-hidden="true"
            />
            <span>
                {isLive ? 'En vivo' : 'Pausado'}
                {relativeTime && ` · ${relativeTime}`}
            </span>
        </div>
    );
}
