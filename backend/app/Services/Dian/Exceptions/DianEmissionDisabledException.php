<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use RuntimeException;

/**
 * Guardarrail: la emisión DIAN de órdenes está apagada globalmente
 * (`DIAN_EMISSION_ENABLED=false`, default). Se dispara desde
 * `DianDispatchService::emit()`/`retry()` antes de tocar consecutivo,
 * provider o storage — ningún flujo (manual, job, cron de recuperación)
 * puede generar ni reintentar documentos mientras el flag esté en false.
 */
class DianEmissionDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'La emisión de documentos electrónicos DIAN está deshabilitada en este entorno (DIAN_EMISSION_ENABLED=false).'
        );
    }
}
