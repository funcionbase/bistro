<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\LoyaltyRedemption;
use App\Services\LoyaltyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expira balances inactivos y cupones de canje vencidos (#122).
 *
 * Recorre todas las empresas y, para cada una, aplica LoyaltyService::expireStale
 * según su configuración. Además, marca como 'expired' los LoyaltyRedemption
 * en status 'issued' cuyo expires_at ya pasó (los puntos NO se devuelven: el
 * canje ya consumió el balance al crearse — esto es regla contable, no bug).
 *
 * Schedule diario. Ver bootstrap/app.php / routes/console.php.
 */
class LoyaltyExpireStale extends Command
{
    protected $signature = 'loyalty:expire-stale
                            {--company= : NIT específico (default: todas las empresas)}
                            {--dry-run : Solo reporta lo que haría sin mutar BD}';

    protected $description = 'Expira balances inactivos y cupones de canje vencidos del programa de fidelización';

    public function __construct(private readonly LoyaltyService $loyaltyService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyNit = $this->option('company');
        $dryRun = (bool) $this->option('dry-run');

        $companies = $companyNit
            ? Company::where('nit', $companyNit)->get(['nit'])
            : Company::all(['nit']);

        $totalAccounts = 0;
        $totalPoints = 0;
        $totalRedemptionsExpired = 0;

        foreach ($companies as $company) {
            if (! $this->loyaltyService->isEnabledFor($company->nit)) {
                continue;
            }

            $this->line("→ {$company->nit}");

            $expired = DB::transaction(function () {
                return LoyaltyRedemption::where('status', 'issued')
                    ->where('expires_at', '<', now())
                    ->update(['status' => 'expired', 'updated_at' => now()]);
            });
            $totalRedemptionsExpired += $expired;

            if ($dryRun) {
                $this->info('   [dry-run] saltando expireStale');

                continue;
            }

            $result = $this->loyaltyService->expireStale($company->nit);
            $totalAccounts += $result['accounts_expired'];
            $totalPoints += $result['points_expired'];

            $this->info("   accounts_expired={$result['accounts_expired']} points_expired={$result['points_expired']} redemptions_expired={$expired}");
        }

        $this->info("DONE accounts={$totalAccounts} points={$totalPoints} redemptions={$totalRedemptionsExpired}");

        return self::SUCCESS;
    }
}
