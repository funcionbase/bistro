<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correos transaccionales del flujo de enrolamiento.
 *
 * Agrega dos columnas de tracking en `companies`:
 *
 *   - `welcome_email_sent_at`: marca cuando se envió OK el correo al propietario
 *     que confirma "registro exitoso + pendiente de aprobación".
 *   - `ops_alert_sent_at`: marca cuando se envió OK el correo al equipo interno
 *     (`MAIL_OPS_ALERT_ADDRESS`) avisando que hay una empresa nueva pendiente
 *     de aprobación.
 *
 * Ambas son la 3ª capa de protección contra envíos duplicados cuando la
 * app corre en el ASG con N≥2 EC2. Cada Job tiene su propio `ShouldBeUnique` y
 * consulta su columna correspondiente con `lockForUpdate` antes de enviar.
 *
 * Las 4 capas en conjunto:
 *
 *   1. `SELECT ... FOR UPDATE SKIP LOCKED` del driver `database` de la cola
 *      — un solo worker procesa cada fila de `jobs`.
 *   2. `ShouldBeUnique` con `uniqueId` distinto por Job — bloquea encolado
 *      duplicado por 24 h vía cache store `database`.
 *   3. **Estas columnas** — guardas de aplicación independientes del estado de
 *      la cola. El job consulta el timestamp antes de enviar; si está populado
 *      no envía. Lo actualiza dentro de la transacción que loggea el audit.
 *   4. `after_commit: true` en `config/queue.php` — el job no existe si la
 *      transacción del enrollment revierte.
 *
 * Nullable porque las empresas previas no tienen registro de envío
 * (la columna refleja el envío real del job, no asume nada retroactivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable()->after('last_paid_at');
            $table->timestamp('ops_alert_sent_at')->nullable()->after('welcome_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['welcome_email_sent_at', 'ops_alert_sent_at']);
        });
    }
};
