<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Expira los descuentos de suscripción cuya fecha de fin (ends_at) ha pasado.
 *
 * Cron: diario. Muta la tabla subscription_discounts (status→expired).
 * --date permite simular una fecha distinta a hoy para pruebas o correcciones manuales.
 */
class ExpireDiscountsCommand extends Command
{
    protected $signature = 'billing:expire-discounts
                            {--date= : Fecha de referencia YYYY-MM-DD (default: hoy)}';

    protected $description = 'Expira descuentos de suscripción cuya fecha de fin ha pasado';

    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption ? Carbon::parse($dateOption) : now();

        $this->info("Expirando descuentos al: {$today->toDateString()}");

        $count = $this->billingService->expireDiscounts($today);

        $this->info("Descuentos expirados: {$count}");

        return self::SUCCESS;
    }
}
