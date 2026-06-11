<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de recuperación de cuenta por cambio de correo.
 *
 * Cuando alguien intenta enrolarse con una cédula que ya pertenece a otra
 * cuenta (correo distinto), en vez de un dead-end se ofrece mover la cuenta
 * existente al correo nuevo. La confirmación se hace por enlace enviado al
 * correo VIEJO (prueba de control), nunca con la sola cédula.
 *
 * `token_hash`: SHA-256 del token crudo (el crudo solo viaja en el email).
 * Un solo uso (`used_at`) + expira (`expires_at`). Auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_email_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Cuenta a mover (la dueña de la cédula). Si se borra, cae la solicitud.
            $table->foreignUuid('target_user_id')->constrained('users')->cascadeOnDelete();
            // Usuario huérfano recién creado con el correo nuevo (se borra al confirmar).
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('new_email');
            $table->string('new_google_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('target_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_email_change_requests');
    }
};
