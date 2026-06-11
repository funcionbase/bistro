import { useEffect, useState } from 'react';

/**
 * True cuando la app corre como PWA instalada (display-mode: standalone) o como
 * web app en iOS (navigator.standalone). En ese modo no hay chrome del navegador
 * —ni botón atrás ni gesto de back fiable—, así que la UI ofrece su propio back.
 */
export function useIsStandalone(): boolean {
    const [standalone, setStandalone] = useState<boolean>(readStandalone);

    useEffect(() => {
        const mql = window.matchMedia?.('(display-mode: standalone)');
        if (!mql) {
            return;
        }
        const update = () => setStandalone(readStandalone());
        update();
        mql.addEventListener('change', update);
        return () => mql.removeEventListener('change', update);
    }, []);

    return standalone;
}

function readStandalone(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    return (
        window.matchMedia?.('(display-mode: standalone)').matches === true ||
        (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}
