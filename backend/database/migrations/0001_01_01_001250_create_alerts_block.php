<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas accionables de margen y costos.
 *
 *  - alert_rules: una fila por (company_nit, type). Cada regla tiene un único
 *    threshold/period_days y los flags de canales de notificación. En v1 sólo
 *    notify_dashboard se respeta (notify_whatsapp es placeholder de v2).
 *
 *  - alert_events: append-only en cuanto a triggered_at; dismissed_at y
 *    actioned_at se setean al manejar. El payload (jsonb) lleva el snapshot
 *    de los datos que el evaluator usó al disparar para que el feed pueda
 *    renderizar sin re-consultar y los deep-links sean estables aunque el
 *    target cambie después.
 *
 *  - Dedup diario: índice UNIQUE PARCIAL sobre la fecha de triggered_at +
 *    target — el AlertEngine intenta INSERT y captura SQLSTATE 23505 para
 *    actualizar el evento existente del día en vez de duplicarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('type', 30);
            $table->decimal('threshold', 12, 4);
            $table->unsignedSmallInteger('period_days');
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_dashboard')->default(true);
            $table->boolean('notify_whatsapp')->default(false);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique(['company_nit', 'type'], 'alert_rules_company_type_unique');
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_rule_id')->constrained('alert_rules')->cascadeOnDelete();
            $table->string('company_nit');
            $table->string('type', 30);
            $table->string('severity', 20);
            $table->string('target_type', 20);
            $table->string('target_id', 100)->nullable();
            $table->jsonb('payload');
            $table->timestamp('triggered_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->text('actioned_note')->nullable();
            $table->uuid('actioned_by')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('actioned_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_nit', 'triggered_at'], 'alert_events_company_triggered_idx');
            $table->index(['company_nit', 'dismissed_at', 'actioned_at'], 'alert_events_company_status_idx');
        });

        // Dedup diario: una sola alerta por (rule, target, fecha) — si el
        // evaluator dispara dos veces el mismo día sobre el mismo target,
        // se reusa el evento existente actualizando payload.
        DB::statement('CREATE UNIQUE INDEX alert_events_daily_dedup_unique
            ON alert_events (alert_rule_id, target_type, COALESCE(target_id, \'\'), DATE(triggered_at))');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
    }
};
