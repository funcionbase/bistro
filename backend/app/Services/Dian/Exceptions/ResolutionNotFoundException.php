<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando no existe resolución activa+vigente para
 * (company_nit, document_type, environment). El owner debe registrarla.
 */
class ResolutionNotFoundException extends RuntimeException
{
    public function __construct(string $companyNit, string $documentType, string $environment)
    {
        parent::__construct(sprintf(
            'No hay resolución DIAN activa y vigente para empresa=%s, document_type=%s, environment=%s. Registrá una desde Configuración → Facturación DIAN → Resoluciones.',
            $companyNit,
            $documentType,
            $environment,
        ));
    }
}
