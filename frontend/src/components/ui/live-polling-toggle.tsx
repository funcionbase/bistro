import { Button } from '@/components/ui/button';
import { Pause, Play } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    enabled: boolean;
    onToggle: () => void;
    activatedAt: number | null;
    autoOffMs: number;
    intervalMs: number;
    label?: string;
}

/**
 * Boton para activar/desactivar polling en vivo. Cuando esta encendido
 * muestra el countdown hasta el auto-apagado (5 min).
 */
export function LivePollingToggle({ enabled, onToggle, activatedAt, autoOffMs, intervalMs, label = 'En vivo' }: Props) {
    const [remaining, setRemaining] = useState(autoOffMs);

    useEffect(() => {
        if (!enabled || activatedAt === null) {
            setRemaining(autoOffMs);
            return;
        }
        const update = () => {
            const left = autoOffMs - (Date.now() - activatedAt);
            setRemaining(Math.max(0, left));
        };
        update();
        const id = setInterval(update, 1000);
        return () => clearInterval(id);
    }, [enabled, activatedAt, autoOffMs]);

    const mins = Math.floor(remaining / 60000);
    const secs = Math.floor((remaining % 60000) / 1000);
    const countdown = `${mins}:${String(secs).padStart(2, '0')}`;

    return (
        <Button
            type="button"
            variant={enabled ? 'default' : 'outline'}
            size="sm"
            onClick={onToggle}
            title={
                enabled
                    ? `Actualiza cada ${intervalMs / 1000}s. Se apaga en ${countdown}.`
                    : 'Activar auto-actualización (se apaga sola en 5 min)'
            }
        >
            {enabled ? (
                <>
                    <Pause className="mr-1 h-3 w-3" />
                    {label} {countdown}
                </>
            ) : (
                <>
                    <Play className="mr-1 h-3 w-3" />
                    Auto-actualizar
                </>
            )}
        </Button>
    );
}
