import { useTimer } from '@/hooks/use-timer';
import { Clock } from 'lucide-react';

interface TimerProps {
    startTime: string;
    format?: 'mm:ss' | 'minutes';
}

export function Timer({ startTime, format = 'mm:ss' }: TimerProps) {
    const { elapsed, formatted } = useTimer(startTime);

    const display = format === 'minutes' ? `${Math.floor(elapsed / 60)} min` : formatted;

    return (
        <span className="inline-flex items-center gap-1 tabular-nums" aria-live="polite" aria-atomic="true">
            <Clock className="h-3.5 w-3.5 shrink-0" />
            {display}
        </span>
    );
}
