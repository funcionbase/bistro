<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markers de notificaciones billing para garantizar at-most-once cross-instance.
 *
 * Antes: `BillingService::notifyCompanyUsers()` se llamaba en cada iteración del
 * cron sin guardrail; un re-run del cron diario o un job retry duplicaba el
 * correo. En N-instance EC2, dos workers pueden procesar la misma invoice
 * concurrente y disparar 2 copias.
 *
 * Después: el helper `notifyOnce()` en BillingService toma `lockForUpdate`
 * sobre el guard (invoice o company), valida la columna marker correspondiente
 * y solo envía la primera vez. Si el SMTP/SES falla post-commit, el marker
 * persiste — el operador re-encola manualmente. Mejor perder un mensaje que
 * disparar 3 copias en clientes activos.
 *
 * Columnas agregadas (todas nullable, aditivas, cero impacto en datos existentes):
 *
 *   invoices.generated_notified_at  — timestamp del envío de InvoiceGenerated
 *   invoices.overdue_notified_at    — timestamp del envío de InvoiceOverdue
 *
 *   companies.blocking_soon_notified_on  — DATE (no timestamp): comparar con
 *       today para skip de re-envíos el mismo día. Único de los 4 que se
 *       envía repetidamente (1×/día durante días [1,7] de gracia restantes);
 *       los otros 3 dependen de transición de status (idempotente por
 *       naturaleza) pero igual quedan marker para defensa en profundidad.
 *
 *   companies.past_due_notified_at      — active -> past_due
 *   companies.suspended_notified_at     — past_due -> suspended
 *   companies.reactivated_notified_at   — past_due|suspended -> active
 *
 * Los 3 últimos se RESETEAN a NULL al volver a la transición opuesta para
 * permitir múltiples ciclos past_due / reactivación a lo largo de la vida
 * del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('generated_notified_at')->nullable()->after('updated_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('generated_notified_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->date('blocking_soon_notified_on')->nullable()->after('payment_blocked_at');
            $table->timestamp('past_due_notified_at')->nullable()->after('blocking_soon_notified_on');
            $table->timestamp('suspended_notified_at')->nullable()->after('past_due_notified_at');
            $table->timestamp('reactivated_notified_at')->nullable()->after('suspended_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['generated_notified_at', 'overdue_notified_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'blocking_soon_notified_on',
                'past_due_notified_at',
                'suspended_notified_at',
                'reactivated_notified_at',
            ]);
        });
    }
};
