<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Convierte el logo subido por la empresa en los iconos PWA requeridos:
 *  - icon-192.png        (any · 192x192)
 *  - icon-512.png        (any · 512x512)
 *  - icon-192-maskable.png (maskable · 192x192 con safe area 12%)
 *  - icon-512-maskable.png (maskable · 512x512 con safe area 12%)
 *  - apple-touch-180.png   (apple-touch-icon · 180x180)
 *
 * Los maskable llevan un padding interior y un fondo sólido (color de marca de la
 * empresa) para que Android los recorte sin amputar el logo. Si el logo trae alpha,
 * se respeta sobre ese fondo.
 *
 * Output va a `storage/public/companies/logos/{nit}/icon-*.png`. Las URLs públicas
 * resuelven a `/storage/companies/logos/{nit}/...`.
 */
class LogoIconRasterizer
{
    public function __construct(
        private readonly ImageManager $imageManager,
        private readonly CompanySettingsService $settings,
    ) {}

    /**
     * Genera todos los iconos a partir del path del logo dentro del disk `public`.
     *
     * @return array{ icon_192: string, icon_512: string, maskable_192: string, maskable_512: string, apple_touch: string }
     *                                                                                                                      Paths relativos al disk `public` (sin prefijo `/storage/`).
     */
    public function rasterize(string $companyNit, string $sourceLogoPath): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($sourceLogoPath)) {
            throw new RuntimeException("Logo source not found: {$sourceLogoPath}");
        }

        $brandColor = (string) $this->settings->get($companyNit, 'menu_primary_color', '#FF6B35');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
            $brandColor = '#FF6B35';
        }

        $sourceBytes = (string) $disk->get($sourceLogoPath);
        $directory = "companies/logos/{$companyNit}";

        $outputs = [
            'icon_192' => ['size' => 192, 'maskable' => false, 'name' => 'icon-192.png'],
            'icon_512' => ['size' => 512, 'maskable' => false, 'name' => 'icon-512.png'],
            'maskable_192' => ['size' => 192, 'maskable' => true, 'name' => 'icon-192-maskable.png'],
            'maskable_512' => ['size' => 512, 'maskable' => true, 'name' => 'icon-512-maskable.png'],
            'apple_touch' => ['size' => 180, 'maskable' => false, 'name' => 'apple-touch-180.png'],
        ];

        $paths = [];
        foreach ($outputs as $key => $spec) {
            $rendered = $this->renderIcon($sourceBytes, $spec['size'], $brandColor, $spec['maskable']);
            $relativePath = "{$directory}/{$spec['name']}";
            $disk->put($relativePath, $rendered);
            $paths[$key] = $relativePath;
        }

        return $paths;
    }

    /**
     * Renderiza una variante del icono. Para `maskable`, el logo se contiene en
     * el 80% central (deja 10% de safe area por lado) sobre un fondo sólido del
     * color de marca. Para `any`, se centra sin padding pero con fondo color
     * de marca también (evita transparencias raras al instalar).
     */
    private function renderIcon(string $sourceBytes, int $size, string $brandColor, bool $maskable): string
    {
        $canvas = $this->imageManager->create($size, $size)->fill($brandColor);

        $logo = $this->imageManager->read($sourceBytes);
        $contentSize = $maskable ? (int) round($size * 0.78) : (int) round($size * 0.92);
        $logo->scaleDown($contentSize, $contentSize);

        $offsetX = (int) round(($size - $logo->width()) / 2);
        $offsetY = (int) round(($size - $logo->height()) / 2);
        $canvas->place($logo, 'top-left', $offsetX, $offsetY);

        return (string) $canvas->toPng();
    }

    /**
     * Helper estático para uso desde controllers/comandos: instancia el manager
     * con el driver GD (instalado por defecto en PHP) si no se provee.
     */
    public static function makeDefault(?CompanySettingsService $settings = null): self
    {
        return new self(
            ImageManager::gd(),
            $settings ?? app(CompanySettingsService::class),
        );
    }
}
