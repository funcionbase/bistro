<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #275 — Ancla de deduplicación e historial de SMS al cliente por cambios de
 * estado de orden (Amazon SNS).
 *
 * Una fila = "se intentó notificar la orden X por el cambio a estado Y".
 * UNIQUE(order_id, to_status) es el mecanismo N-instance-safe: el segundo
 * intento (otra instancia EC2, doble click, reintento de job) viola el unique
 * y no reenvía. La inserción (insertOrIgnore) ocurre dentro de la misma
 * DB::transaction + lockForUpdate del cambio de estado (lock de Postgres es
 * cross-instance — CLAUDE.md §12.6).
 *
 * Append-only (no se hace UPDATE del estado a un valor "anterior"; solo
 * queued → sent | failed). SoftDeletes por política global del proyecto.
 *
 * PDN-safe: CREATE TABLE es aditivo, no toca ni borra datos existentes.
 *
 * @env Volumen: ~1 fila por orden por estado notificable (máx 4/orden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sms_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Orden origen del SMS. ON DELETE CASCADE: si la orden se borra
            // (no ocurre en PDN — soft delete máximo), el historial de SMS la
            // acompaña.
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();

            // Denormalizados para reportes por empresa/sede sin join (Fase 3).
            // El conteo se agrupa por branch_id respetando BranchScope.
            $table->string('company_nit', 50)->index();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();

            // Estado destino que disparó el SMS (config order_notifications.sms_statuses).
            $table->string('to_status', 30);

            // Teléfono destino ya normalizado a E.164 (necesario para reenvío/debug).
            $table->string('phone', 20);

            // Ciclo del envío: queued (registrado, pendiente) → sent | failed.
            $table->string('status', 12)->default('queued')->index();

            // Resultado del publish a SNS.
            $table->string('provider_message_id')->nullable();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->string('error', 500)->nullable();

            // Vínculo al ChatMessage espejo (Fase 2 — visibilidad en el chat).
            $table->uuid('chat_message_id')->nullable();

            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Ancla de deduplicación N-instance-safe.
            $table->unique(['order_id', 'to_status'], 'order_sms_notifications_order_status_unique');

            // Reportes: conteo por sede en un período (Fase 3).
            $table->index(['company_nit', 'branch_id', 'status', 'sent_at'], 'order_sms_notifications_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sms_notifications');
    }
};
