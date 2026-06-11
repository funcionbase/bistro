<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor del modelo "contacto = cliente".
 *
 * Cambios sobre la realidad del negocio: un mismo teléfono puede pertenecer
 * a varios miembros de una familia (mamá y dos hijos), pero el número de
 * documento sí identifica unívocamente al cliente dentro de la empresa.
 *
 * 1. `contacts.phone` pasa a `nullable`. El cliente fiscal puro (empresa NIT
 *    sin móvil) ya puede registrarse. Se reemplaza el UNIQUE compuesto por
 *    un índice no único — phone sigue siendo buscable.
 * 2. `contacts.doc_number` recibe un UNIQUE parcial por empresa
 *    (postgres: WHERE doc_number IS NOT NULL). Esto deja a clientes sin
 *    doc convivir (walk-ins legacy) pero garantiza unicidad real cuando
 *    el doc sí está presente.
 * 3. `orders.contact_id` nuevo (FK nullable a contacts.id). Desambigua a
 *    qué cliente pertenece la orden cuando varios comparten phone. El
 *    cajero lo setea explícitamente en el POS; órdenes legacy con phone
 *    único quedan pobladas por la migración data-only que corre después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['company_nit', 'phone']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->change();
            $table->index(['company_nit', 'phone'], 'contacts_company_phone_idx');
        });

        // Partial unique en PostgreSQL — múltiples NULLs conviven, pero el
        // doc_number presente debe ser único por empresa.
        DB::statement('CREATE UNIQUE INDEX contacts_company_doc_unique
            ON contacts (company_nit, doc_number)
            WHERE doc_number IS NOT NULL');

        // El índice no único previo sobre (company_nit, doc_number) ya no
        // hace falta — el unique parcial lo cubre.
        DB::statement('DROP INDEX IF EXISTS contacts_company_doc_number_idx');

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUuid('contact_id')
                ->nullable()
                ->after('client_phone')
                ->constrained('contacts')
                ->nullOnDelete();
            $table->index(['company_nit', 'contact_id'], 'orders_company_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_contact_idx');
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });

        DB::statement('DROP INDEX IF EXISTS contacts_company_doc_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS contacts_company_doc_number_idx
            ON contacts (company_nit, doc_number)');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_company_phone_idx');
        });

        // Si hay phones NULL al hacer rollback, esto fallará — es intencional,
        // el rollback solo aplica antes de poblar nullables. En PDN no se
        // espera rollback de esta migración.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable(false)->change();
            $table->unique(['company_nit', 'phone']);
        });
    }
};
