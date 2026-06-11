<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM básico de clientes.
 *
 *  - client_notes: notas privadas por cliente (alergias, preferencias, quejas).
 *    Soft-delete: conservamos historial para auditoría legal. Cross-sede (sin branch_id):
 *    el cliente es uno solo para toda la empresa, independiente de dónde pida.
 *  - client_tags: etiquetas configurables por cliente (vip, domicilio, etc.).
 *    Hard-delete (append/remove); el audit_log preserva trazabilidad.
 *
 * Identidad del cliente: (company_nit, client_phone). No hay tabla canónica `clients`
 * — el phone es la llave informal que ya usa orders.client_phone y contacts.phone.
 * Phone normalizado al formato 57XXXXXXXXXX (sin '+', con prefijo país) por
 * CrmService::normalizePhone() antes de persistir/buscar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('client_phone', 30);
            $table->text('note');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_nit', 'client_phone', 'created_at'], 'client_notes_company_phone_created_idx');
        });

        Schema::create('client_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('client_phone', 30);
            $table->string('tag', 50);
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['company_nit', 'client_phone', 'tag'], 'client_tags_company_phone_tag_unique');
            $table->index(['company_nit', 'tag'], 'client_tags_company_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tags');
        Schema::dropIfExists('client_notes');
    }
};
