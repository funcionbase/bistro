<?php

namespace App\Exceptions;

use Exception;

class PdfEmptyDataException extends Exception
{
    public function __construct()
    {
        parent::__construct('No hay datos para exportar en el rango seleccionado.');
    }
}
