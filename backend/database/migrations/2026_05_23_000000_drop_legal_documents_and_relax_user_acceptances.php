<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retira el sistema interno de versionado de documentos legales.
 *
 * Los documentos (TOS, privacidad, contrato) ahora viven en el wiki externo
 * (https://funcionbase.com/wiki/restaurante/legal/...). La tabla `legal_documents`
 * y su sync vía `legal:sync` quedan obsoletas.
 *
 * `user_acceptances` se conserva con sus registros históricos completos
 * (snapshot incluido) para cumplir trazabilidad legal Habeas Data CO. Las
 * columnas `document_version` y `document_content` pasan a nullable porque las
 * aceptaciones nuevas ya no llevan snapshot — el contenido vive en el wiki
 * versionado externamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('legal_documents');

        Schema::table('user_acceptances', function (Blueprint $table) {
            $table->string('document_version')->nullable()->change();
            $table->text('document_content')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_acceptances', function (Blueprint $table) {
            $table->string('document_version')->nullable(false)->change();
            $table->text('document_content')->nullable(false)->change();
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['terms', 'privacy', 'contract']);
            $table->string('version');
            $table->text('content');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'version']);
        });
    }
};
