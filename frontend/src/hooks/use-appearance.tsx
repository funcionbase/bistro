import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

/** Mismos valores que los <meta name="theme-color"> de index.html. */
const THEME_COLOR = { light: '#f6f5f3', dark: '#1E232E' } as const;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);

    // Los <meta theme-color> con media query solo siguen el tema del SO; si el
    // usuario fuerza dark/light in-app quedan desincronizados y en PWA (iOS/
    // Android) la barra de estado se pinta del color equivocado.
    document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => {
        meta.setAttribute('content', isDark ? THEME_COLOR.dark : THEME_COLOR.light);
    });
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem('appearance', mode);
        applyTheme(mode);
    };

    useEffect(() => {
        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
        updateAppearance(savedAppearance || 'system');

        // El listener de cambios del tema del SO lo registra `initializeTheme`
        // una sola vez por pestaña y es dueño de su ciclo de vida. El hook NO
        // debe removerlo en su cleanup: hacerlo mataba el seguimiento de tema
        // "system" para toda la pestaña al desmontar cualquier consumidor.
    }, []);

    return { appearance, updateAppearance };
}
