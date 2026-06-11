<?php

declare(strict_types=1);

namespace App\Console\Commands\Pwa;

use App\Models\Company;
use App\Services\LogoIconRasterizer;
use Illuminate\Console\Command;

/**
 * Regenera los iconos PWA derivados del logo subido por cada empresa.
 *
 * Útil tras instalar el feature offline (#140) en una BD existente, donde
 * las empresas ya tenían `logo_path` pero nunca pasaron por el pipeline de
 * rasterización.
 */
class RasterizeCompanyLogos extends Command
{
    protected $signature = 'pwa:rasterize-logos {--nit= : Procesar solo esta empresa}';

    protected $description = 'Genera los iconos PWA (192/512/maskable/apple-touch) a partir del logo de cada empresa';

    public function handle(LogoIconRasterizer $rasterizer): int
    {
        $query = Company::query()->whereNotNull('logo_path');
        if ($nit = $this->option('nit')) {
            $query->where('nit', $nit);
        }

        $companies = $query->get(['nit', 'logo_path']);
        if ($companies->isEmpty()) {
            $this->info('No hay empresas con logo para procesar.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($companies as $company) {
            try {
                $rasterizer->rasterize($company->nit, (string) $company->logo_path);
                $this->line("  ✓ {$company->nit}");
                $ok++;
            } catch (\Throwable $e) {
                $this->line("  ✗ {$company->nit} — {$e->getMessage()}");
                $fail++;
            }
        }

        $this->info("Rasterización completa: {$ok} OK, {$fail} fallidas.");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }
}
