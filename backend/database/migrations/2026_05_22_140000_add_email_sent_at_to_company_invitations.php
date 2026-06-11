<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo de invitación de usuario.
 *
 * Agrega `email_sent_at` en `company_invitations`: marca cuando se envió OK el
 * correo al invitado avisándole que tiene acceso a la empresa (NIT) que lo
 * convocó y un CTA al login. Es la 3ª capa de protección contra duplicados
 * cuando la app corre en el ASG con N≥2 EC2.
 *
 * El Job `SendUserInvitationEmailJob` consulta este timestamp con
 * `lockForUpdate` antes de enviar; si está populado, omite. Lo actualiza
 * dentro de la transacción que loggea el evento de auditoría
 * `invitation.email_sent`.
 *
 * Nullable porque las invitaciones previas no tienen registro
 * de envío (siguen siendo aceptables vía email auto-match; no se reenvían
 * retroactivamente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropColumn('email_sent_at');
        });
    }
};
