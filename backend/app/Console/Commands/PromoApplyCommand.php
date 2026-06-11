<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Billing\CompanyAlreadyHasActivePromoException;
use App\Exceptions\Billing\PromoCodeMaxCompaniesReachedException;
use App\Exceptions\Billing\PromoCodeNotApplicableException;
use App\Models\Company;
use App\Services\PromoCodeService;
use Illuminate\Console\Command;

/**
 * Aplica un PromoCode a una empresa específica (operación de backoffice) —
 * invocado desde GitHub Action `apply_promo_code`.
 *
 * `applied_via=github_action`, `starts_at` = primer día del próximo mes
 * Bogota (diferido si hay trial activo). Snapshot inmutable.
 *
 * Uso:
 *   php artisan promo:apply --nit=900123456 --code=BLACKFRIDAY2026
 */
class PromoApplyCommand extends Command
{
    protected $signature = 'promo:apply
                            {--nit= : NIT de la empresa (sin DV)}
                            {--code= : Slug del PromoCode}';

    protected $description = 'Aplica un PromoCode a una empresa (operación backoffice)';

    public function __construct(private readonly PromoCodeService $promoCodeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $nit = trim((string) $this->option('nit'));
        $code = strtoupper(trim((string) $this->option('code')));

        if ($nit === '' || $code === '') {
            $this->error('--nit y --code son obligatorios.');

            return self::FAILURE;
        }

        $company = Company::query()->where('nit', $nit)->first();
        if ($company === null) {
            $this->error("Empresa NIT={$nit} no existe.");

            return self::FAILURE;
        }

        try {
            $promo = $this->promoCodeService->validateBySlug($code);
            $application = $this->promoCodeService->applyToCompany(
                $company,
                $promo,
                appliedVia: 'github_action',
                appliedByUserId: null,
            );
        } catch (PromoCodeNotApplicableException|PromoCodeMaxCompaniesReachedException|CompanyAlreadyHasActivePromoException $e) {
            $errorCode = property_exists($e, 'errorCode') ? $e->errorCode : 'PROMO_CODE_ERROR';
            $this->error("[{$errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("OK promo {$promo->code} aplicado a NIT={$company->nit}:");
        $this->line("  starts_at: {$application->starts_at?->toIso8601String()}");
        $this->line("  ends_at:   {$application->ends_at?->toIso8601String()}");
        $this->line("  discount:  {$application->discount_percent}% × {$application->months_duration} meses");

        return self::SUCCESS;
    }
}
