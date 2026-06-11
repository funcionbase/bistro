<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log append-only de transiciones de estado de delivery.
 *
 * Cada cambio de status que pasa por `DeliveryService` (assignDeliverer,
 * selfAssign, completeDelivery, revertDelivery, rejectDelivery,
 * cancelDelivery, reassignDeliverer) escribe una fila acá. La tabla es
 * append-only — sin updated_at, sin deleted_at, sin updates.
 *
 * Por qué no usamos solo `audit_logs`:
 *  - `audit_logs` es genérico (cualquier modelo / acción). Reconstruir la
 *    historia de un delivery exige filtrar por auditable_type+id y
 *    parsear el `data` JSON. Aquí la consulta directa por delivery_id es
 *    O(1) gracias al índice y el shape es estable (4 columnas + reason).
 *  - El `audit_logs` se sigue generando para trazabilidad cross-recurso;
 *    este log es complementario, no sustituto.
 *
 * Reglas: branch_id NOT NULL, FK restrictOnDelete a branches,
 * índice compuesto (company_nit, branch_id) para BranchScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_status_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 20);
            $table->uuid('branch_id');
            $table->foreignUuid('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('reason', 64)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['delivery_id', 'created_at'], 'delivery_status_logs_delivery_idx');
            $table->index(['company_nit', 'branch_id'], 'delivery_status_logs_company_branch_idx');
        });

        // Defensa en BD: lista cerrada de razones estructuradas. NULL permitido
        // (transiciones que no requieren motivo: assign, self_assign, complete).
        // El backend valida lo mismo a nivel servicio; este check es cinturón.
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_status_logs
            ADD CONSTRAINT delivery_status_logs_reason_check
            CHECK (reason IS NULL OR reason IN ('error_usuario', 'pedido_rechazado', 'reassigned'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_status_logs');
    }
};
