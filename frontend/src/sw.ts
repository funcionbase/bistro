/// <reference lib="webworker" />

/**
 * Service Worker custom de funcionbase Restaurante (#149).
 *
 * Migración de `generateSW` (Workbox auto-genera) a `injectManifest`
 * (escribimos el SW y Workbox inyecta el manifest de precaching). Necesario
 * para poder agregar listeners `push`, `notificationclick` y
 * `pushsubscriptionchange` que `generateSW` no permite cleanly.
 *
 * Estructura:
 *  1. Precaching del shell (JS/CSS/woff2 de Vite vía `__WB_MANIFEST`).
 *  2. Runtime caching idéntico al esquema previo (NetworkFirst APIs,
 *     CacheFirst imágenes/fuentes).
 *  3. Listeners de Web Push (`push`, `notificationclick`,
 *     `pushsubscriptionchange`).
 *  4. `skipWaiting` + `clientsClaim` para activar inmediatamente como antes.
 */

import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst, StaleWhileRevalidate } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope & {
    __WB_MANIFEST: Array<{ url: string; revision: string | null }>;
};

// 1. Precaching shell
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// 1b. Navigation fallback (caja offline-first, plan §14): toda navegación del
// SPA (no-API, no-asset) se sirve con `index.html` precacheado. Sin esto, una
// recarga offline de /caja devolvía el error de red del navegador. El denylist
// excluye API/OAuth/storage para que esas rutas sigan yendo a la red.
//
// ponytail: NO usar createHandlerBoundToURL('/index.html') — Cloudflare Workers
// redirige /index.html con 307 → /. El SW no puede responder a una navegación
// con una respuesta redirigida (redirect mode != 'follow'). En su lugar:
// precache primero; si no hay cache, fetch de la URL original (CF sirve
// index.html para cualquier ruta SPA con 200, no 307).
registerRoute(
    new NavigationRoute(
        async ({ request }) => {
            // matchPrecache resuelve la clave revisionada del precache
            // (`index.html?__WB_REVISION__=...`). Un caches.match('/index.html')
            // directo NUNCA matcheaba esa clave → toda navegación iba a red y
            // la recarga offline del SPA fallaba (el propósito de esta ruta).
            const precached = await matchPrecache('/index.html');
            if (precached) return precached;
            // Sin precache y sin red (primer load offline, cache eviccionado):
            // responder un shell mínimo en vez de dejar que el fetch rechace y
            // el navegador muestre su pantalla de error de red.
            return fetch(request.url).catch(
                () =>
                    new Response(
                        '<!doctype html><html lang="es"><meta charset="utf-8"><title>Sin conexión</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh"><div><h1>Sin conexión</h1><p>Reintenta cuando vuelva la red.</p></div>',
                        { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 },
                    ),
            );
        },
        {
            // `\.[a-z0-9]+$` — navegaciones a archivos reales (sitemap.xml,
            // robots.txt, llms.txt…) van a red; sin esto el SW respondía con
            // el shell del SPA y el router mostraba el 404 (#sitemap).
            denylist: [/^\/api\//, /^\/auth\//, /^\/storage-proxy\//, /^\/storage\//, /\.[a-z0-9]+$/i],
        },
    ),
);

// Activación inmediata — el SW nuevo toma control sin esperar a cerrar tabs.
self.skipWaiting();
self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// 2. Runtime caching (espejo del esquema anterior en vite.config.js)
registerRoute(
    ({ url, request }) =>
        request.method === 'GET' &&
        /^\/api\/v1\/(menus|orders|cash-register\/current)/.test(url.pathname) &&
        !/\/orders\/pending-(approvals|cancellations)$/.test(url.pathname) &&
        !/\/orders\/tables$/.test(url.pathname) &&
        !/\/table-sessions/.test(url.pathname),
    new NetworkFirst({
        cacheName: 'pos-api-get',
        networkTimeoutSeconds: 5,
        plugins: [new ExpirationPlugin({ maxEntries: 64, maxAgeSeconds: 60 * 60 * 24 }), new CacheableResponsePlugin({ statuses: [0, 200] })],
    }),
);

// Fotos de usuario (logos de empresa, imágenes de producto): son inmutables una
// vez subidas → CacheFirst agresivo está bien.
registerRoute(
    ({ url, request }) => request.destination === 'image' && url.pathname.startsWith('/storage/'),
    new CacheFirst({
        cacheName: 'pos-images',
        plugins: [new ExpirationPlugin({ maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 7 }), new CacheableResponsePlugin({ statuses: [0, 200] })],
    }),
);

// Branding de la app (logo, íconos PWA, favicon): cambian en cada deploy. Con
// CacheFirst quedaban pegados hasta 7 días → el usuario no veía el logo nuevo.
// StaleWhileRevalidate sirve el cache al instante pero revalida en segundo plano,
// así el próximo load ya trae el nuevo. El cacheName versionado (`-v2`) fuerza un
// cache limpio en esta activación → el logo nuevo se ve de una.
registerRoute(
    ({ url }) =>
        url.pathname.startsWith('/images/') ||
        url.pathname.startsWith('/icons/') ||
        url.pathname === '/favicon.svg' ||
        url.pathname === '/favicon.ico',
    new StaleWhileRevalidate({
        cacheName: 'pos-branding-v2',
        plugins: [new ExpirationPlugin({ maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 * 30 }), new CacheableResponsePlugin({ statuses: [0, 200] })],
    }),
);

registerRoute(
    /^https:\/\/fonts\.bunny\.net\//,
    new CacheFirst({
        cacheName: 'fonts-bunny',
        plugins: [new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 30 }), new CacheableResponsePlugin({ statuses: [0, 200] })],
    }),
);

// 2b. Background Sync (caja offline-first, plan §14): cuando el navegador
// recupera conectividad, dispara el tag `flush-outbox`. El SW no tiene la
// lógica del outbox (vive en el bundle); despierta a los clientes abiertos
// para que el sync-engine drene en foreground. Best-effort: soporte parcial
// (Chromium) y solo útil si hay alguna pestaña/PWA viva.
self.addEventListener('sync', ((event: ExtendableEvent & { tag?: string }) => {
    if (event.tag !== 'flush-outbox') return;
    event.waitUntil(
        (async () => {
            const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of clientsList) {
                client.postMessage({ type: 'pwa:flush-outbox' });
            }
        })(),
    );
}) as EventListener);

// 3. Web Push listeners (#149)
interface PushPayload {
    title: string;
    body: string;
    url?: string;
    tag?: string;
    icon?: string;
    badge?: string;
    data?: Record<string, unknown>;
}

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload: PushPayload;
    try {
        payload = event.data.json() as PushPayload;
    } catch {
        // Si el payload no es JSON, mostramos texto plano sin URL.
        payload = { title: 'bistro', body: event.data.text() };
    }

    // `renotify` no está en el typing standard de NotificationOptions pero
    // sí lo aceptan Chromium/Firefox; cast a record para evitar TS strict.
    const options: NotificationOptions & Record<string, unknown> = {
        body: payload.body,
        icon: payload.icon ?? '/icons/icon-192.png',
        // Fallback a un ícono que SÍ existe en public/icons — el monochrome
        // de 96px nunca se generó y Android mostraba el badge genérico.
        badge: payload.badge ?? '/icons/icon-192.png',
        tag: payload.tag,
        renotify: false,
        data: {
            url: payload.url ?? '/dashboard',
            ...payload.data,
        },
    };

    event.waitUntil(self.registration.showNotification(payload.title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl: string = typeof event.notification.data?.url === 'string' ? event.notification.data.url : '/dashboard';

    event.waitUntil(
        (async () => {
            const allClients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of allClients) {
                const clientUrl = new URL(client.url);
                if (clientUrl.origin === self.location.origin) {
                    await client.focus();
                    // Navegación SPA vía postMessage (listener en spa/main.tsx):
                    // `client.navigate` hacía full reload y perdía el estado de
                    // la pestaña (caja, formularios). El hard-navigate queda
                    // solo para el caso sin clientes (openWindow abajo).
                    client.postMessage({ type: 'pwa:navigate', url: targetUrl });
                    return;
                }
            }

            await self.clients.openWindow(targetUrl);
        })(),
    );
});

/**
 * Re-suscripción automática cuando el navegador rota el endpoint
 * (`pushsubscriptionchange`). Lee la clave pública VAPID del manifest del
 * documento (insertado vía `<meta name="vapid-public-key">`) y POSTea la
 * nueva sub al backend.
 *
 * Sin esto, una sub que el OS invalida silenciosamente queda zombie en BD
 * hasta que el siguiente push falle con 410 y nuestro dispatcher la limpie.
 */
// `pushsubscriptionchange` no está aún en lib.webworker.d.ts mainstream;
// cast del listener a EventListener y del evento a ExtendableEvent para
// poder llamar `waitUntil` con el tipado correcto.
self.addEventListener('pushsubscriptionchange', ((event: ExtendableEvent) => {
    event.waitUntil(
        (async () => {
            try {
                const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
                if (allClients.length === 0) return;

                // Pedimos al primer cliente activo que reemita el endpoint
                // a través de un MessageChannel — la subscripción anterior
                // ya no es válida acá pero el cliente puede pedir una nueva
                // vía `pushManager.subscribe` y hablar con el backend.
                allClients[0].postMessage({ type: 'pwa:push:resubscribe' });
            } catch {
                // No interrumpe el SW; el próximo intento del usuario re-subscribe.
            }
        })(),
    );
}) as EventListener);
