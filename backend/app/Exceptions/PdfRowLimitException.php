<?php

namespace App\Exceptions;

use Exception;

class PdfRowLimitException extends Exception
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("El reporte supera el máximo de {$limit} registros por PDF. Reduce el rango de fechas o aplica filtros adicionales.");
    }
}
