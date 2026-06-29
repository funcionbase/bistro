<?php

declare(strict_types=1);

use App\Models\Table;
use Illuminate\Database\Migrations\Migration;

/**
 * Regenera todos los qr_token existentes al nuevo formato corto:
 * 13 caracteres alfabéticos mayúsculos (A–Z). El formato anterior
 * era 40 chars alfanuméricos (Str::random(40)).
 *
 * Los QR impresos con el formato viejo quedan inválidos; el dueño
 * debe reimprimir los posters desde /company/tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $used = [];

        Table::withoutBranchScope()->get(['id'])->each(function (Table $table) use (&$used): void {
            do {
                $token = implode('', array_map(fn () => chr(random_int(65, 90)), range(1, 13)));
            } while (isset($used[$token]));

            $used[$token] = true;
            $table->timestamps = false;
            $table->qr_token = $token;
            $table->saveQuietly();
        });
    }

    public function down(): void
    {
        // No reversible: no hay forma de recuperar los tokens originales.
    }
};
