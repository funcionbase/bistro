<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación de propiedad de la empresa.
 *
 * Persiste la referencia (no el contenido binario) del documento de propiedad
 * subido durante el enrolamiento. El archivo vive en S3 (`s3_documents` disk,
 * prefijo `enrollment-proofs/`). La fila es 1:1 con `companies` (UNIQUE en
 * `company_nit`).
 *
 * Soft-delete obligatorio: las reglas contables/DIAN exigen conservar la
 * evidencia 5–10 años. Nunca se hace DELETE físico desde la app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit')->unique();
            $table->string('disk', 30)->default('s3_documents');
            $table->string('s3_key', 512);
            $table->string('mime_type', 120);
            $table->unsignedInteger('file_size');
            $table->string('original_filename', 255);
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')
                ->references('nit')
                ->on('companies')
                ->cascadeOnDelete();

            $table->index('company_nit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_proofs');
    }
};
