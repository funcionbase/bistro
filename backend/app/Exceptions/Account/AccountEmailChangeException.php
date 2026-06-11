<?php

namespace App\Exceptions\Account;

use Exception;

/**
 * Se lanza cuando una confirmación de cambio de correo no es válida:
 * token inexistente/expirado/usado, o la cuenta nueva ya tiene datos.
 */
class AccountEmailChangeException extends Exception
{
    //
}
