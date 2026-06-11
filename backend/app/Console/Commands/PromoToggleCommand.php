<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PromoCode;
use Illuminate\Console\Command;

/**
 * Activa o desactiva un PromoCode del catálogo — invocado desde GitHub Action
 * (`toggle_promo_code`).
 *
 * Solo afecta la disponibilidad para NUEVAS aplicaciones. Las empresas con
 * `company_promo_codes` ya activos mantienen su descuento hasta `ends_at`.
 *
 * Uso:
 *   php artisan promo:toggle --code=BLACKFRIDAY2026 --status=inactive
 */
class PromoToggleCommand extends Command
{
    protected $signature = 'promo:toggle
                            {--code= : Slug del PromoCode}
                            {--status= : active|inactive}';

    protected $description = 'Cambia el status de un PromoCode (active|inactive)';

    public function handle(): int
    {
        $code = strtoupper(trim((string) $this->option('code')));
        $status = trim((string) $this->option('status'));

        if ($code === '' || ! in_array($status, ['active', 'inactive'], true)) {
            $this->error('--code y --status (active|inactive) son obligatorios.');

            return self::FAILURE;
        }

        $promo = PromoCode::query()->where('code', $code)->first();
        if ($promo === null) {
            $this->error("PromoCode '{$code}' no existe.");

            return self::FAILURE;
        }

        if ($promo->status === $status) {
            $this->info("PromoCode {$code} ya estaba en status={$status} (no-op).");

            return self::SUCCESS;
        }

        $promo->forceFill(['status' => $status])->save();
        $this->info("PromoCode {$code} → status={$status}.");

        return self::SUCCESS;
    }
}
