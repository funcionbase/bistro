<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PromoCodeService;
use Illuminate\Console\Command;

/**
 * Cancela el PromoCode activo de una empresa — invocado desde GitHub Action
 * `cancel_promo_code`.
 *
 * NO afecta invoices ya emitidas. Solo deja de aplicar en futuros períodos.
 * Idempotente: si la empresa no tiene promo activo, retorna OK con no-op.
 *
 * Uso:
 *   php artisan promo:cancel --nit=900123456
 */
class PromoCancelCommand extends Command
{
    protected $signature = 'promo:cancel
                            {--nit= : NIT de la empresa (sin DV)}';

    protected $description = 'Cancela el PromoCode activo de una empresa';

    public function __construct(private readonly PromoCodeService $promoCodeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $nit = trim((string) $this->option('nit'));
        if ($nit === '') {
            $this->error('--nit es obligatorio.');

            return self::FAILURE;
        }

        $company = Company::query()->where('nit', $nit)->first();
        if ($company === null) {
            $this->error("Empresa NIT={$nit} no existe.");

            return self::FAILURE;
        }

        $cancelled = $this->promoCodeService->cancelForCompany($company, cancelledByUserId: null);

        if ($cancelled === null) {
            $this->info("NIT={$company->nit} no tiene promo activo (no-op).");

            return self::SUCCESS;
        }

        $this->info("OK promo {$cancelled->id} cancelado para NIT={$company->nit} a las {$cancelled->cancelled_at?->toIso8601String()}.");

        return self::SUCCESS;
    }
}
