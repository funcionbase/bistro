<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * `usage_count` igualó/superó `max_companies`. El promo no puede aplicarse
 * a más empresas — el catálogo permanece activo pero saturado.
 */
class PromoCodeMaxCompaniesReachedException extends RuntimeException
{
    public string $errorCode = 'PROMO_CODE_MAX_COMPANIES_REACHED';

    public string $slug;

    public function __construct(string $message, string $slug)
    {
        parent::__construct($message);
        $this->slug = $slug;
    }

    public static function for(string $slug): self
    {
        return new self("Código promocional '{$slug}' alcanzó el límite de empresas.", $slug);
    }
}
