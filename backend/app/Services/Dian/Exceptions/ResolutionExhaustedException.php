<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use App\Models\DianResolution;
use RuntimeException;

/**
 * Se lanza cuando la resolución activa alcanzó su `range_to`.
 *
 * El owner debe registrar una resolución nueva (range_to mayor o nueva
 * resolución DIAN autorizada) antes de continuar emitiendo. El endpoint
 * captura la excepción y devuelve 422 con código `dian.resolution_exhausted`.
 */
class ResolutionExhaustedException extends RuntimeException
{
    public function __construct(public readonly DianResolution $resolution)
    {
        parent::__construct(sprintf(
            'Resolución DIAN agotada (id=%s, prefix=%s, range_to=%d). Registrá una nueva resolución antes de continuar.',
            (string) $resolution->getKey(),
            $resolution->prefix,
            $resolution->range_to,
        ));
    }
}
