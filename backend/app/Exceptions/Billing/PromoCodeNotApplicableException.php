<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Promo code que no aplica al momento de la validación — slug no existe,
 * fuera de vigencia o desactivado.
 *
 * El controller mapea esta excepción a 422 con `error_code` específico para
 * que el frontend pueda mostrar el mensaje localizado correcto sin parsear
 * texto.
 */
class PromoCodeNotApplicableException extends RuntimeException
{
    public string $errorCode;

    public string $slug;

    public function __construct(string $message, string $errorCode, string $slug)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->slug = $slug;
    }

    public static function notFound(string $slug): self
    {
        return new self("Código promocional '{$slug}' no existe.", 'PROMO_CODE_NOT_FOUND', $slug);
    }

    public static function inactive(string $slug): self
    {
        return new self("Código promocional '{$slug}' está desactivado.", 'PROMO_CODE_INACTIVE', $slug);
    }

    public static function notYetActive(string $slug): self
    {
        return new self("Código promocional '{$slug}' aún no es válido.", 'PROMO_CODE_NOT_YET_ACTIVE', $slug);
    }

    public static function expired(string $slug): self
    {
        return new self("Código promocional '{$slug}' expiró.", 'PROMO_CODE_EXPIRED', $slug);
    }
}
