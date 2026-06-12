import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import type { ObfuscatorOptions } from 'javascript-obfuscator';
import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import bundleObfuscator from 'vite-plugin-bundle-obfuscator';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * Build del frontend SPA standalone (#220).
 *
 * El frontend es un proyecto independiente del backend Laravel: usa
 * `index.html` como entry point (no `laravel-vite-plugin`). Las llamadas
 * a `/api/*`, `/auth/google/*` y `/storage-proxy/*` se proxean al backend
 * en dev; en producción el cliente usa `VITE_API_URL`.
 *
 * OJO: solo `/auth/google/*` va al backend. El resto de `/auth/*`
 * (`company-selector`, `branch-selector`, `login`, etc.) son rutas del SPA
 * y las resuelve React Router — proxearlas rompía el flujo post-login.
 *
 * Anti-reversing (solo en `vite build`): el bundle final, ya minificado por
 * terser, se ofusca con javascript-obfuscator — string array encriptado,
 * control-flow flattening, renombrado hexadecimal, debugProtection y
 * selfDefending. `node_modules` y el service worker quedan fuera.
 */

// Opciones balanceadas: ofuscación fuerte sin el peso del modo máximo
// (deadCodeInjection off, control-flow flattening moderado al 50%).
const obfuscatorOptions: ObfuscatorOptions = {
    compact: true,
    controlFlowFlattening: true,
    controlFlowFlatteningThreshold: 0.3,
    deadCodeInjection: false,
    debugProtection: true,
    debugProtectionInterval: 4000,
    disableConsoleOutput: false,
    identifierNamesGenerator: 'hexadecimal',
    ignoreImports: true,
    numbersToExpressions: true,
    renameGlobals: false,
    selfDefending: true,
    simplify: true,
    splitStrings: false,
    stringArray: true,
    stringArrayCallsTransform: true,
    stringArrayCallsTransformThreshold: 0.5,
    stringArrayEncoding: ['base64'],
    stringArrayIndexShift: true,
    stringArrayRotate: true,
    stringArrayShuffle: true,
    stringArrayWrappersChainedCalls: true,
    stringArrayWrappersCount: 2,
    stringArrayWrappersParametersMaxCount: 4,
    stringArrayWrappersType: 'function',
    stringArrayThreshold: 0.75,
    transformObjectKeys: true,
    unicodeEscapeSequence: false,
};

export default defineConfig(({ mode }) => {
    const isProd = mode === 'production';
    // loadEnv lee los archivos .env del proyecto. `process.env` por sí solo
    // NO los incluye dentro de vite.config.ts, así que sin esto el valor de
    // VITE_BACKEND_URL del .env se ignora y siempre cae al fallback.
    const env = loadEnv(mode, process.cwd(), '');
    const backendUrl = env.VITE_BACKEND_URL || 'http://localhost';

    return {
        plugins: [
            react(),
            tailwindcss(),
            // PWA #149: estrategia `injectManifest` para listeners custom
            // (`push`, `notificationclick`, `pushsubscriptionchange`). El SW
            // vive en `src/sw.ts`.
            VitePWA({
                strategies: 'injectManifest',
                srcDir: 'src',
                filename: 'sw.ts',
                injectRegister: false,
                manifest: false,
                devOptions: {
                    enabled: false,
                },
                injectManifest: {
                    // `html` incluido para precachear `index.html` y servir el
                    // app-shell del SPA offline vía NavigationRoute (sw.ts).
                    globPatterns: ['**/*.{js,css,woff2,html}'],
                    maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
                },
            }),
            // Ofuscación anti-reversing. `apply: 'build'` => no corre en dev.
            // Procesa el bundle ya minificado; auto-excluye node_modules y el
            // service worker (sw.js) para no romper la PWA ni inflar el vendor.
            bundleObfuscator({
                apply: 'build',
                log: true,
                autoExcludeNodeModules: true,
                threadPool: true,
                excludes: [/sw\.js$/],
                options: obfuscatorOptions,
            }),
        ],
        resolve: {
            alias: {
                '@': resolve(import.meta.dirname, './src'),
            },
        },
        server: {
            proxy: {
                '/api': { target: backendUrl, changeOrigin: true },
                // Solo el flujo OAuth de Google va al backend. `/auth/google`
                // como prefijo cubre también `/auth/google/callback`. El resto
                // de `/auth/*` son rutas del SPA (las sirve index.html).
                '/auth/google': { target: backendUrl, changeOrigin: true },
                '/storage-proxy': { target: backendUrl, changeOrigin: true },
            },
        },
        esbuild: {
            jsx: 'automatic',
        },
        build: isProd
            ? {
                  sourcemap: false,
                  minify: 'terser',
                  terserOptions: {
                      compress: {
                          drop_console: true,
                          drop_debugger: true,
                      },
                      mangle: {
                          toplevel: true,
                      },
                  },
              }
            : {},
    };
});
