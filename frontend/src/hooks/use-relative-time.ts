import { useEffect, useState } from 'react';

export function useRelativeTime(timestamp: Date | string | undefined): string {
    const [label, setLabel] = useState('');

    useEffect(() => {
        if (!timestamp) {
            setLabel('');
            return;
        }

        const update = () => {
            const date = typeof timestamp === 'string' ? new Date(timestamp) : timestamp;
            const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

            if (seconds < 60) {
                setLabel(`hace ${seconds} segundo${seconds !== 1 ? 's' : ''}`);
            } else {
                const minutes = Math.floor(seconds / 60);
                setLabel(`hace ${minutes} minuto${minutes !== 1 ? 's' : ''}`);
            }
        };

        update();
        const id = setInterval(update, 1000);
        return () => clearInterval(id);
    }, [timestamp]);

    return label;
}
