<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PromoCode;
use Illuminate\Console\Command;

/**
 * Crea o actualiza un PromoCode en el catálogo — invocado desde
 * GitHub Action `promo-codes-ops.yml` (acción `create_promo_code`).
 *
 * Idempotente: si el `code` ya existe, actualiza name/description/percent/
 * months/max_companies. Status queda como esté (toggle se hace aparte).
 *
 * Uso:
 *   php artisan promo:create --code=BLACKFRIDAY2026 \
 *     --name="Black Friday 2026" \
 *     --discount=20 --months=6 [--max=100] [--description="..."]
 */
class PromoCreateCommand extends Command
{
    protected $signature = 'promo:create
                            {--code= : Slug único, uppercase recomendado}
                            {--name= : Nombre interno}
                            {--description= : Descripción opcional}
                            {--discount= : % descuento (1-100)}
                            {--months= : Meses de duración (1-120)}
                            {--max= : Máximo de empresas (vacío = sin tope)}';

    protected $description = 'Crea o actualiza un PromoCode en el catálogo';

    public function handle(): int
    {
        $code = strtoupper(trim((string) $this->option('code')));
        $name = trim((string) $this->option('name'));
        $description = $this->option('description');
        $discount = (int) $this->option('discount');
        $months = (int) $this->option('months');
        $maxRaw = $this->option('max');
        $max = ($maxRaw === null || $maxRaw === '') ? null : (int) $maxRaw;

        if ($code === '' || $name === '') {
            $this->error('--code y --name son obligatorios.');

            return self::FAILURE;
        }
        if ($discount < 1 || $discount > 100) {
            $this->error('--discount debe estar entre 1 y 100.');

            return self::FAILURE;
        }
        if ($months < 1 || $months > 120) {
            $this->error('--months debe estar entre 1 y 120.');

            return self::FAILURE;
        }
        if ($max !== null && $max < 1) {
            $this->error('--max debe ser positivo si se provee.');

            return self::FAILURE;
        }

        $promo = PromoCode::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $description !== null ? (string) $description : null,
                'discount_percent' => $discount,
                'months_duration' => $months,
                'max_companies' => $max,
                'status' => 'active',
            ],
        );

        $this->info("OK promo {$promo->code} (id={$promo->id}) — {$promo->discount_percent}% por {$promo->months_duration} meses".(
            $promo->max_companies !== null ? ", max {$promo->max_companies} empresas" : ', sin tope'
        ));

        return self::SUCCESS;
    }
}
