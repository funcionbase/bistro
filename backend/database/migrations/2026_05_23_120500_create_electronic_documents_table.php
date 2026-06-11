<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos electrónicos DIAN.
 *
 * Tabla central de la HU. Cada fila representa un DEE POS, FEV, NC o ND
 * con su CUFE/CUDE, XML/PDF en S3, status y track de proveedor.
 *
 * Características críticas:
 *  - INMUTABLE post-`accepted`: `boot::updating` en el modelo bloquea cualquier
 *    cambio en campos financieros + `unique_code`. Para "corregir" un doc
 *    aceptado se emite nota crédito (otra fila con `references_document_id`).
 *  - UNIQUE compuesta (`company_nit`, `document_type`, `prefix`, `consecutive`)
 *    — última red de seguridad si el allocator devolviera duplicados.
 *  - UNIQUE en `unique_code` — el CUFE/CUDE es determinístico sobre el input
 *    canónico; misma orden + mismos datos = mismo CUFE → la UNIQUE atrapa
 *    cualquier doble emisión en concurrencia (N-instance safe).
 *  - `provider_slug` + `dian_environment_code` se snapshot-ean al emitir.
 *    Cambios futuros de provider/ambiente NO afectan documentos previos.
 *  - `references_document_id` apunta a la fila original cuando este doc es
 *    una nota crédito/débito (FK self-referencing, restrict on delete).
 *  - Conservación 10 años (CLAUDE.md §13): soft-delete máximo, jamás
 *    `truncate`. No se agrega `deleted_at` ahora porque el plan no lo pide;
 *    si más adelante hace falta, se agrega aditivamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('dian_resolution_id')->constrained('dian_resolutions')->restrictOnDelete();

            $table->string('document_type', 30);
            $table->string('prefix', 10);
            $table->unsignedBigInteger('consecutive');
            $table->string('full_number', 50);

            // CUFE (96 chars) o CUDE (96 chars). Index para búsqueda por QR/auditoría.
            $table->string('unique_code', 96);
            $table->string('unique_code_type', 10);

            $table->timestamp('issued_at');

            // S3 prefix companies/{nit}/dian/{yyyy}/{mm}/{full_number}.{xml|pdf}
            $table->string('xml_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->text('qr_data')->nullable();

            $table->string('status', 30)->default('pending');
            $table->string('provider_slug', 30)->nullable();
            $table->string('provider_track_id', 100)->nullable();
            $table->jsonb('provider_response_log')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();

            $table->string('dian_environment_code', 20)->nullable();

            $table->uuid('references_document_id')->nullable();

            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches');

            $table->unique(['company_nit', 'document_type', 'prefix', 'consecutive'], 'electronic_documents_full_number_unique');
            $table->unique('unique_code', 'electronic_documents_unique_code_unique');

            $table->index(['company_nit', 'status']);
            $table->index(['company_nit', 'branch_id', 'issued_at']);
            $table->index('provider_track_id');
            $table->index(['order_id', 'document_type']);
        });

        // Self-reference FK aplicado fuera del create() — ver invoices block.
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->foreign('references_document_id')->references('id')->on('electronic_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
    }
};
