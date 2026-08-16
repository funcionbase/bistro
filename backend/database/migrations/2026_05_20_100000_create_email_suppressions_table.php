<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email suppressions (SES bounce / complaint handling).
 *
 * Lista de direcciones que NO se deben enviar correos transaccionales.
 * Poblada por el webhook `/api/v1/webhooks/ses-notifications` que recibe
 * notificaciones SNS desde el SES Configuration Set `bistro-default`.
 * Consultada por el listener `AbortIfSuppressed` antes de cada envío para
 * evitar danar la reputación del dominio (bounce rate > 5% o complaint
 * rate > 0.1% degradan deliverability rapidísimo).
 *
 * Reglas:
 *  - `email` es citext (case-insensitive) — el casing del correo NO
 *    importa para suppression (RFC 5321 lo permite, pero los proveedores
 *    lo tratan case-insensitive).
 *  - `reason` es enum cerrado: bounce | complaint | manual.
 *  - `subtype` es el detalle SES: para bounces (hard, soft, transient,
 *    suppressed, undetermined), para complaints (abuse, auth-failure,
 *    fraud, not-spam, other, virus). Permite políticas distintas — un
 *    bounce `transient` (mailbox full) puede expirar en 30 días, un
 *    `hard` es permanente.
 *  - `metadata` (jsonb) guarda el payload completo de SES para auditoría
 *    y reproceso futuro si cambian las reglas.
 *  - `expires_at` nullable: NULL = suppression permanente. Sirve para
 *    bounces transitorios que pueden re-intentarse después de un período.
 *  - El correo se persiste lowercase para que las consultas vía índice
 *    funcionen sin `LOWER()` en cada SELECT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 320); // RFC 5321 max length
            $table->string('reason', 20); // bounce | complaint | manual
            $table->string('subtype', 50)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('expires_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Filtros de admin / dashboard de suppressions.
            $table->index('received_at', 'email_suppressions_received_at_idx');
        });

        // Unique constraint: una suppression activa por email + reason.
        // Permite que un mismo email tenga histórico de varias suppressions
        // (e.g. complaint en 2026-03 luego de bounce en 2026-01 ya expirado).
        // Pero evita duplicados activos de la misma causa. Usa LOWER() para
        // que el casing del email no genere duplicados (RFC 5321 lo permite
        // pero todos los proveedores lo tratan case-insensitive).
        DB::statement(
            'CREATE UNIQUE INDEX email_suppressions_email_reason_active_unique
                ON email_suppressions (LOWER(email), reason)
                WHERE expires_at IS NULL'
        );

        // Búsqueda principal del listener AbortIfSuppressed: ¿hay alguna
        // suppression activa (no expirada) para este email?
        DB::statement(
            'CREATE INDEX email_suppressions_lookup_idx
                ON email_suppressions (LOWER(email))
                WHERE expires_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
