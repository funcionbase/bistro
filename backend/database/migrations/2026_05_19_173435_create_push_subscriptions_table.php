<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions.
 *
 * Persiste las suscripciones del navegador (PushManager) por usuario. Un
 * mismo `user` puede tener N suscripciones (una por dispositivo / browser);
 * el navegador devuelve un `endpoint` único + claves `p256dh` y `auth` que
 * se usan para cifrar el payload con la VAPID privada del servidor.
 *
 * Reglas:
 *  - `endpoint` es text porque los endpoints VAPID pueden ser muy largos
 *    (FCM/Mozilla/Apple) y exceder el límite de columna `string`. La
 *    uniqueness se hace por MD5 hash dentro del índice parcial.
 *  - `branch_id` es nullable porque una suscripción es **cross-branch**: un
 *    cajero asignado a 2 sedes recibe push relevante a ambas con la misma
 *    sub. Si se elimina la sede, queda NULL (no se borra la sub).
 *  - Soft-revoke vía `revoked_at`: si el endpoint responde 410 Gone, se
 *    marca pero no se elimina (audit trail). El cron de limpieza puede
 *    purgarlas pasados N días.
 *  - Índice único parcial garantiza que un mismo user no tenga el mismo
 *    endpoint activo dos veces. Pero permite re-suscribirse después de un
 *    revoke (la fila previa queda en `revoked_at != NULL` y no choca).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('company_nit');
            $table->uuid('branch_id')->nullable();
            $table->text('endpoint');
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->index(['user_id', 'revoked_at'], 'push_subscriptions_user_active_idx');
            $table->index(['company_nit', 'revoked_at'], 'push_subscriptions_company_active_idx');
        });

        // Unique partial (PostgreSQL): un endpoint activo por user.
        // `MD5(endpoint)` evita el límite de tamaño de índice sobre text.
        DB::statement('CREATE UNIQUE INDEX push_subscriptions_user_endpoint_unique
            ON push_subscriptions (user_id, MD5(endpoint))
            WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
