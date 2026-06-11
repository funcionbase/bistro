<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use RuntimeException;

/**
 * Guardarrail: `MockDianProvider` jamás puede usarse en `environment=produccion`.
 *
 * Documentos emitidos por el mock NO son legalmente válidos ante DIAN. Esta
 * excepción se dispara en `MockDianProvider::send` si la config activa de la
 * empresa marca produccion. El owner debe rotar a un provider real antes de
 * cambiar `environment` a producción.
 */
class MockInProductionException extends RuntimeException
{
    public function __construct(string $companyNit)
    {
        parent::__construct(sprintf(
            'MockDianProvider no puede emitir en environment=produccion (empresa=%s). Cambiá a un provider real antes de pasar a producción.',
            $companyNit,
        ));
    }
}
