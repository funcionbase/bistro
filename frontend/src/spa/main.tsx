import '../css/app.css';
import '../lib/zod-config';

import { QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { SavePageEasterEgg } from '../components/save-page-easter-egg';
import { initializeTheme } from '../hooks/use-appearance';
import { BOOTSTRAP_QUERY_KEY, type BootstrapResponse } from '../hooks/use-bootstrap';
import { resubscribePush } from '../hooks/use-push-subscription';
import { attachCtaListener } from '../lib/analytics';
import { installChunkRecoveryHandlers } from '../lib/chunk-recovery';
import { isAnyDirty } from '../lib/dirty-state';
import { activateSpanishValidation } from '../lib/native-validation-i18n';
import { queryClient } from '../lib/query-client';
import { router } from './router';

// Anti "Failed to fetch dynamically imported module": intercepta chunk
// errors (rebuild de Vite / deploy con hashes nuevos) y recarga la
// pestaña una vez para que el usuario nunca vea la pantalla de error
// técnica por algo que se resuelve solo con un reload.
installChunkRecoveryHandlers();

/**
 * Entry point del frontend SPA standalone.
 *
 * React Router puro — sin Inertia. El backend Laravel solo sirve la API.
 */

// Anti-tamper: en producción neutraliza el hook global de React
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

// Mensajes SW → página. El SW no puede re-suscribir push ni navegar el router
// por sí mismo; delega en la pestaña activa:
//  - `pwa:push:resubscribe` (evento `pushsubscriptionchange`): el navegador
//    rotó el endpoint push → re-suscribir con la clave VAPID del bootstrap
//    cacheado y re-registrar en el backend. Sin este listener, el mensaje del
//    SW se perdía y la sub quedaba zombie hasta el próximo 410.
//  - `pwa:navigate` (click en notificación): navegación SPA con el router,
//    sin full reload. El SW conserva openWindow cuando no hay pestañas.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event: MessageEvent) => {
        const msg = event.data as { type?: string; url?: string } | null;
        if (msg?.type === 'pwa:push:resubscribe') {
            const vapid = queryClient.getQueryData<BootstrapResponse>(BOOTSTRAP_QUERY_KEY)?.vapidPublicKey;
            if (vapid) {
                void resubscribePush(vapid).catch(() => undefined);
            }
        } else if (msg?.type === 'pwa:navigate' && typeof msg.url === 'string') {
            void router.navigate(msg.url);
        }
    });
}

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
            // La recarga silenciosa en background solo si NO hay trabajo sin
            // guardar (isAnyDirty): recargar con un formulario a medias
            // perdía los cambios sin aviso. Con dirty, el banner de update
            // lo maneja cuando el usuario vuelve.
            if (document.visibilityState === 'hidden' && !isAnyDirty()) {
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
                if (!reg) return;

                // Chequeo periódico: cubre la PWA/tablet abierta 24/7 (caja, KDS)
                // que sin esto pasaba hasta 24h sin detectar una versión nueva.
                setInterval(() => void reg.update(), 5 * 60 * 1000);

                // Y al volver a primer plano: una PWA instalada que se abre,
                // se usa un rato y se cierra puede NO llegar nunca a los 5 min
                // del intervalo, y se quedaba pegada en la versión con la que
                // abrió (el caso reportado: seguía en una build vieja pese a
                // varios deploys). Checkear en `visibilitychange` detecta el
                // deploy nuevo apenas el usuario reabre la app. Barato: `update()`
                // hace un fetch condicional del sw.js (no descarga si no cambió).
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') void reg.update();
                });
            })
            .catch(() => {
                // Falla silenciosa: la app sigue funcionando sin SW.
            });
    });
}
