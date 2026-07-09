<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use RuntimeException;

/**
 * Guardarrail: la empresa no está en un plan que incluya el módulo DIAN
 * (feature `'dian'`, exclusiva del Plan Plus). Se dispara desde
 * `DianDispatchService::emit()`/`retry()` — cubre tanto el endpoint HTTP
 * (bloqueado antes por el middleware `plan.feature:dian`, esto es defensa
 * en profundidad) como `EmitDianDocumentJob`, que no pasa por middleware.
 */
class PlanFeatureNotIncludedException extends RuntimeException
{
    public function __construct(public readonly string $feature = 'dian')
    {
        parent::__construct('Esta opción no está incluida en tu plan actual.');
    }
}
