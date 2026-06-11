import { useEffect, useState } from 'react';

function formatElapsed(seconds: number): string {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

export function useTimer(startTime: string) {
    const calcElapsed = () => Math.max(0, Math.floor((Date.now() - new Date(startTime).getTime()) / 1000));

    const [elapsed, setElapsed] = useState<number>(calcElapsed);

    useEffect(() => {
        setElapsed(calcElapsed());
        const interval = setInterval(() => setElapsed(calcElapsed()), 1000);
        return () => clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [startTime]);

    return { elapsed, formatted: formatElapsed(elapsed) };
}
