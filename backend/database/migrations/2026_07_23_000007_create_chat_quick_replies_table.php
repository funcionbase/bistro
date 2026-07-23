<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3b — respuestas rápidas del operador (§8.4b punto 7).
 *
 * El 80 % de lo que responde un restaurante son cinco frases. Esta tabla las
 * guarda para insertarlas con `/` en el compositor, sin reescribirlas cada vez.
 *
 * `branch_id` nulo = respuesta de TODA la empresa; con sede = solo esa sede
 * (§8.4b punto 7 "por empresa y por sede"). Por eso la tabla NO usa el
 * `BranchScope` global: null significa "todas", y el scope lo escondería. El
 * filtro por sede lo aplica el controlador a mano, como `WhatsappChannelController`.
 *
 * `body` admite `{{cliente}}`, `{{pedido}}` y `{{sede}}` (§8.4b punto 14): son
 * literales que el frontend resuelve al insertar, no columnas.
 *
 * Aditiva: tabla nueva, no toca nada existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_quick_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            // Sin sede = toda la empresa. `nullOnDelete` no dispara en la práctica
            // (las sedes se archivan, no se borran), pero deja la fila consistente
            // si algún día se borra una de verdad.
            $table->uuid('branch_id')->nullable();
            $table->string('title', 80);
            $table->text('body');
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // Listado: siempre por empresa, a menudo filtrando por sede.
            $table->index(['company_nit', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_quick_replies');
    }
};
