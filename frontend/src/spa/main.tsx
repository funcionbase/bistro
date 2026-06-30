import '../css/app.css';
import '../lib/zod-config';

import { QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { SavePageEasterEgg } from '../components/save-page-easter-egg';
import { initializeTheme } from '../hooks/use-appearance';
import { attachCtaListener } from '../lib/analytics';
import { installChunkRecoveryHandlers } from '../lib/chunk-recovery';
import { activateSpanishValidation } from '../lib/native-validation-i18n';
import { queryClient } from '../lib/query-client';
import { setToken } from '../lib/token';
import { router } from './router';

// Anti "Failed to fetch dynamically imported module": intercepta chunk
// errors (rebuild de Vite / deploy con hashes nuevos) y recarga la
// pestaña una vez para que el usuario nunca vea la pantalla de error
// técnica por algo que se resuelve solo con un reload.
installChunkRecoveryHandlers();

/**
 * Entry point del frontend SPA standalone (#220).
 *
 * React Router puro — sin Inertia. El backend Laravel solo sirve la API.
 */

// Anti-tamper (#220): en producción neutraliza el hook global de React
// DevTools para que el árbol de componentes no sea inspeccionable. Corre
// antes del primer render; en dev queda inerte (import.meta.env.PROD=false).
if (import.meta.env.PROD) {
    const devtoolsHook = (window as unknown as { __REACT_DEVTOOLS_GLOBAL_HOOK__?: Record<string, unknown> })
        .__REACT_DEVTOOLS_GLOBAL_HOOK__;
    if (devtoolsHook) {
        for (const key of Object.keys(devtoolsHook)) {
            devtoolsHook[key] = typeof devtoolsHook[key] === 'function' ? () => undefined : null;
        }
    }
}

// Migración legacy: si el OAuth callback dejó `?token=` en la URL, persistirlo
// para que apiFetch envíe Bearer hasta que la cookie HttpOnly migre.
const urlToken = new URLSearchParams(window.location.search).get('token');
if (urlToken) {
    setToken(urlToken);
}

const el = document.getElementById('spa-root');
if (el) {
    createRoot(el).render(
        <QueryClientProvider client={queryClient}>
            <SavePageEasterEgg />
            <RouterProvider router={router} />
        </QueryClientProvider>,
    );
}

initializeTheme();
attachCtaListener();
activateSpanishValidation();

// Service Worker (PWA): registra `/sw.js` para cache de assets y soporte offline.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    void import('workbox-window').then(({ Workbox }) => {
        const hadController = !!navigator.serviceWorker.controller;
        const wb = new Workbox('/sw.js');

        // controlling: con skipWaiting() incondicional en sw.ts el SW nuevo nunca
        // entra en "waiting" → este es el único evento que se dispara de forma
        // confiable cuando un deploy nuevo toma control de la página.
        wb.addEventListener('controlling', () => {
            if (!hadController) return; // primera instalación, no actualización
            if (document.visibilityState === 'hidden') {
                window.location.reload(); // background → recarga silenciosa
            } else {
                window.dispatchEvent(new CustomEvent('pwa:update-available'));
            }
        });

        // waiting: fallback si en algún momento skipWaiting pasa a ser message-driven.
        wb.addEventListener('waiting', () => {
            window.dispatchEvent(new CustomEvent('pwa:update-available'));
        });

        wb.register()
            .then((reg) => {
                // Chequeo periódico: sin esto una PWA abierta 24/7 puede pasar
                // hasta 24h sin detectar una nueva versión.
                if (reg) setInterval(() => void reg.update(), 5 * 60 * 1000);
            })
            .catch(() => {
                // Falla silenciosa: la app sigue funcionando sin SW.
            });
    });
}
