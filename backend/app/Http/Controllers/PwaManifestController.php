<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanySettingsService;
use App\Services\JwtService;
use App\Support\SignedAssetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sirve el Web App Manifest (PWA), el Service Worker y los iconos derivados.
 *
 * Estrategia: una sola URL `/manifest.webmanifest` que resuelve la empresa
 * activa desde el JWT cookie cuando está presente y devuelve un manifest con
 * el branding de esa empresa (`name`, `theme_color`, `background_color` desde
 * `companies.menu_primary_color`, e `icons[]` rasterizados desde su logo).
 *
 * Si no hay JWT válido, no hay empresa activa, o la empresa nunca subió logo,
 * se devuelve el manifest con branding flexyflow (logo black-font sobre blanco).
 *
 * El navegador re-pide el manifest periódicamente; un Cache-Control corto
 * (5 min, privado) evita golpear DB en cada visita sin atrasar mucho un
 * cambio de color por parte del usuario.
 */
class PwaManifestController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly CompanySettingsService $settingsService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        // Default "principal": fondo blanco de marca (#f6f5f3). Se sobreescribe
        // con el `menu_primary_color` de la empresa activa si la hay.
        $brandColor = '#f6f5f3';
        $companyName = null;
        $companyNit = null;

        $token = $this->jwtService->extractTokenFromRequest($request);
        if (is_string($token) && $token !== '') {
            try {
                $payload = $this->jwtService->verify($token);
                $activeCompanyNit = $payload['active_company_nit'] ?? null;

                if ($activeCompanyNit !== null) {
                    // Lookup por nit (UNIQUE). La PK pasó a id uuid, así que
                    // find() ya no aplica acá.
                    $company = Company::query()
                        ->select(['nit', 'commercial_name', 'logo_path'])
                        ->where('nit', $activeCompanyNit)
                        ->first();

                    if ($company !== null) {
                        $companyName = $company->commercial_name;
                        $companyNit = $company->nit;
                        $brandColor = (string) $this->settingsService->get(
                            $activeCompanyNit,
                            'menu_primary_color',
                            '#FF6B35'
                        );
                    }
                }
            } catch (RuntimeException) {
                // Token inválido o expirado: caer al manifest por defecto.
            }
        }

        $name = $companyName !== null
            ? "panel flexy · {$companyName}"
            : 'panel flexy';

        $manifest = [
            'name' => $name,
            'short_name' => 'panel flexy',
            'description' => 'POS, caja y menú digital para tu empresa.',
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $brandColor,
            'theme_color' => $brandColor,
            'lang' => 'es-CO',
            'dir' => 'ltr',
            'categories' => ['business', 'productivity', 'food'],
            'icons' => $this->resolveIcons($companyNit),
            'shortcuts' => [
                [
                    'name' => 'Caja',
                    'short_name' => 'Caja',
                    'url' => '/orders/cashier',
                ],
                [
                    'name' => 'Tablero de órdenes',
                    'short_name' => 'Tablero',
                    'url' => '/orders/board',
                ],
                [
                    'name' => 'Menú',
                    'short_name' => 'Menú',
                    'url' => '/menu',
                ],
            ],
        ];

        return response()
            ->json($manifest, 200, [
                'Content-Type' => 'application/manifest+json; charset=UTF-8',
                'Cache-Control' => 'private, max-age=300',
            ]);
    }

    /**
     * Resuelve los 4 iconos del manifest. Si la empresa tiene logo rasterizado
     * (vía LogoIconRasterizer), se usan esos PNGs por-empresa; si no, se cae
     * a los iconos flexyflow por defecto en `/icons/`.
     *
     * @return array<int, array<string, string>>
     */
    private function resolveIcons(?string $companyNit): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $base = $companyNit ? "companies/logos/{$companyNit}" : null;

        $useCompany = $base !== null
            && $disk->exists("{$base}/icon-192.png")
            && $disk->exists("{$base}/icon-512.png");

        if ($useCompany) {
            return [
                ['src' => SignedAssetUrl::for("{$base}/icon-192.png"), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => SignedAssetUrl::for("{$base}/icon-512.png"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => SignedAssetUrl::for("{$base}/icon-192-maskable.png"), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => SignedAssetUrl::for("{$base}/icon-512-maskable.png"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];
        }

        return [
            // SVG adaptativo al theme del sistema (fondo blanco/letra oscura por
            // defecto, invierte en dark). Los PNG quedan de fallback estático.
            ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-192-maskable.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/icons/icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    /**
     * Sirve el apple-touch-icon dinámico. iOS Safari NO consulta el manifest
     * para esto; lee el `<link rel="apple-touch-icon">` del HTML, que apunta
     * fijo a `/apple-touch-icon.png`. Esta ruta resuelve a la versión de la
     * empresa activa (si existe) o al logo flexyflow por defecto.
     */
    public function appleTouchIcon(Request $request): Response
    {
        $disk = Storage::disk(config('filesystems.default'));
        $token = $this->jwtService->extractTokenFromRequest($request);
        $companyNit = null;

        if (is_string($token) && $token !== '') {
            try {
                $payload = $this->jwtService->verify($token);
                $companyNit = $payload['active_company_nit'] ?? null;
            } catch (RuntimeException) {
                $companyNit = null;
            }
        }

        if ($companyNit !== null && $disk->exists("companies/logos/{$companyNit}/apple-touch-180.png")) {
            return response((string) $disk->get("companies/logos/{$companyNit}/apple-touch-180.png"), 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        $defaultPath = public_path('icons/apple-touch-icon-180.png');
        if (! is_file($defaultPath)) {
            return response('', 404);
        }

        return response((string) file_get_contents($defaultPath), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Sirve el Service Worker generado por Workbox desde la raíz del sitio.
     *
     * vite-plugin-pwa emite `sw.js` dentro de `public/build/` (mismo `outDir`
     * que el resto del bundle). Registrarlo desde `/build/sw.js` deja el
     * `scope` por defecto en `/build/`, así que el SW no puede interceptar
     * la navegación. Servirlo desde `/sw.js` da scope `/` automáticamente,
     * pero exige reescribir las URLs internas (`./workbox-*.js` y
     * `assets/*` del precache) para que sigan resolviendo a `/build/...`.
     */
    public function serviceWorker(): Response
    {
        $path = public_path('build/sw.js');

        if (! is_file($path)) {
            return response('// Service worker not built. Run: npm run build', 404, [
                'Content-Type' => 'application/javascript; charset=UTF-8',
            ]);
        }

        $contents = (string) file_get_contents($path);

        $rewritten = strtr($contents, [
            'define(["./workbox-' => 'define(["/build/workbox-',
            'url:"assets/' => 'url:"/build/assets/',
        ]);

        return response($rewritten, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
    }
}
