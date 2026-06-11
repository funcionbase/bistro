<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * La empresa ya tiene un promo activo. Solo 1 por empresa a la vez
 * (UNIQUE parcial DB). Cancelar el actual antes de aplicar otro.
 */
class CompanyAlreadyHasActivePromoException extends RuntimeException
{
    public string $errorCode = 'COMPANY_ALREADY_HAS_ACTIVE_PROMO';

    public string $companyNit;

    public string $existingPromoApplicationId;

    public function __construct(string $message, string $companyNit, string $existingPromoApplicationId)
    {
        parent::__construct($message);
        $this->companyNit = $companyNit;
        $this->existingPromoApplicationId = $existingPromoApplicationId;
    }

    public static function for(string $companyNit, string $existingApplicationId): self
    {
        return new self(
            "La empresa NIT {$companyNit} ya tiene un código promocional activo. Cancélalo antes de aplicar otro.",
            $companyNit,
            $existingApplicationId,
        );
    }
}
