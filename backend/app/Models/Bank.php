<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de bancos disponibles para configurar la cuenta bancaria de una empresa.
 *
 * Los registros se siembran via seeder; el campo bank_id en Company referencia esta tabla.
 */
class Bank extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
    ];
}
