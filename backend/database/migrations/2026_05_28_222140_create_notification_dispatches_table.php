<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro append-only de notificaciones billing despachadas.
 *
 * Defensa de idempotencia complementaria a los markers *_notified_at en
 * companies (que protegen el evento a nivel empresa, pero no a nivel
 * (notif, user destinatario) y no defienden contra reintentos de cola).
 *
 * Una fila = "se le envio a este user X la notif Y con la idempotency_key Z".
 * UNIQUE compuesto (notification_class, idempotency_key, user_id):
 *   - Mismo evento (idempotency_key) puede tener N filas, una por user destinatario.
 *   - Cada (user, evento) recibe el correo exactamente una vez.
 *   - Re-fires del job, reintentos de cola, o ejecuciones manuales del cron
 *     intentan insertar y fallan por UNIQUE -> skip silencioso + audit log.
 *
 * No se borra (append-only). Lleva SoftDeletes por politica global del
 * proyecto (nunca hard delete); el soft delete conserva la fila y el UNIQUE.
 * Si en el futuro hace falta retener menos, agregar scheduled command de purge.
 *
 * @env Tabla pesa: ~10 filas por empresa por mes (1 invoice generated + 1
 *      overdue ocasional + transiciones). Insignificante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // FQN de la clase Notification que se despacho.
            $table->string('notification_class', 200);
            // Clave de idempotencia provista por la notif. Estable para una
            // misma "instancia logica del evento". Ejemplos:
            //  - 'company:{nit}:activated'
            //  - 'invoice:{id}:generated'
            //  - 'company:{nit}:blocking_soon:{date}' (1 por dia)
            $table->string('idempotency_key', 255);
            // Receptor (FK a users; ON DELETE RESTRICT — append-only). Es
            // seguro porque los users NUNCA se borran en este proyecto (ni soft
            // ni hard): su acceso se revoca por membresia (ver User model). El
            // RESTRICT jamas dispara. users.id es uuid v7, no bigint.
            $table->foreignUuid('user_id')->constrained()->cascadeOnUpdate();
            // Empresa asociada — denormalizada para queries por empresa.
            $table->string('company_nit', 50)->nullable()->index();
            // Snapshot del email al momento del envio. Util si despues cambia.
            $table->string('target_email');
            $table->timestampTz('sent_at');
            // Metadata extra (invoice_id, subscription_id, etc.).
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['notification_class', 'idempotency_key', 'user_id'],
                'notification_dispatches_event_user_unique',
            );

            $table->index(['company_nit', 'notification_class', 'sent_at'], 'notification_dispatches_company_class_sent_idx');
            $table->index(['user_id', 'sent_at'], 'notification_dispatches_user_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
