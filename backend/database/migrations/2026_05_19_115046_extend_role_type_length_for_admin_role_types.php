<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía `permission_templates.role_type` de varchar(16) a varchar(32).
 *
 * Introduce el role_type `inventory_manager` (17 chars), que
 * desborda el ancho original. La fundacional declaraba 16 cuando solo
 * existían `owner|admin|employee|waiter|cook|cashier`. El nuevo ancho deja
 * margen para futuros role_types descriptivos sin volver a migrar.
 *
 * `down()` colapsa de vuelta a 16 — sólo seguro si se borran los registros
 * con role_type largo previo. Se mantiene por simetría; en práctica no se
 * usa porque la BD de PDN aún no tiene datos productivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_templates', function (Blueprint $table) {
            $table->string('role_type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('permission_templates', function (Blueprint $table) {
            $table->string('role_type', 16)->change();
        });
    }
};
