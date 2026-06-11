<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes de pago manuales subidos por el cliente.
 *
 * Tabla append-only: NO se mutan filas existentes (status sí se actualiza al
 * revisar manualmente, único campo mutable + reviewed_by/reviewed_at/notes).
 * Conservación DIAN 10 años — soft-delete máximo, jamás truncate.
 *
 * `invoice_ids` (jsonb) referencia las facturas que el cliente declara estar
 * pagando con este comprobante. Es informativo: la aprobación operativa puede
 * marcar otras invoices según el monto efectivamente recibido.
 *
 * Identificador externo: el endpoint `/api/v1/billing/payment-proofs/{uuid}`
 * recibe la columna `uuid` (UUID v4 generada por Postgres con
 * `gen_random_uuid()` — requiere extensión pgcrypto, ya activada en
 * la migración base). El BIGSERIAL `id` se conserva como PK interno y
 * destino de FKs (audit_logs.auditable_id). El UUID evita enumeración
 * secuencial (`/1`, `/2`, …) por usuarios con cookie válida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'));
            $table->string('company_nit', 20);
            $table->jsonb('invoice_ids')->nullable();
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->string('original_name');
            $table->enum('status', ['submitted', 'accepted', 'rejected'])->default('submitted');
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique('uuid', 'payment_proofs_uuid_unique');
            $table->index(['company_nit', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
