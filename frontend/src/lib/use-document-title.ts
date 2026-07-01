import { useEffect } from 'react';

const APP_NAME = 'bistro';

/**
 * Setea `document.title` en el shell SPA.
 *
 * Restaura el título previo al desmontar para no dejar residuos al navegar
 * entre rutas SPA.
 */
export function useDocumentTitle(title: string): void {
    useEffect(() => {
        const previous = document.title;
        document.title = `${title} - ${APP_NAME}`;
        return () => {
            document.title = previous;
        };
    }, [title]);
}
