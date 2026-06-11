<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 01 — Companies.
 *
 * Empresas (entidad raíz de tenancy) + configuración por empresa.
 * Las membresías (company_users) y las invitaciones se crean en el bloque 02
 * porque dependen de company_roles.
 *
 *  - companies: id (UUID PK), NIT (UNIQUE inmutable), datos comerciales, banco,
 *    status, plan, configuración tributaria (tax_regime, default_tax_rate,
 *    default_tax_label, tax_included_in_price), logo_path, cronología de mora.
 *    La PK es UUID interno; el NIT sigue siendo el identificador estable de
 *    cara a DIAN/JWT/S3 y se usa como FK lógica en las 31+ tablas hijas
 *    (`company_nit` → `companies.nit`). Esto se apoya en que PostgreSQL acepta
 *    FK referenciando una columna UNIQUE (no PK).
 *  - company_settings: K/V por empresa con tipo dinámico (string|integer|boolean|json).
 *  - user_active_tokens: registra el JWT activo por usuario para revocación de sesión
 *    (1 fila por usuario; UNIQUE en user_id).
 *
 * Modelo de estados:
 *  - pending_activation: default al crear empresa (en revisión por ops).
 *  - rejected: workflow de verificación marcó la empresa como inválida.
 *  - active: operando OK (paga al día o en período de prueba).
 *  - inactive: baja voluntaria/administrativa.
 *  - past_due: tiene ≥1 factura vencida y atraso ≤ BILLING_PAST_DUE_GRACE_MONTHS.
 *  - suspended: atraso > gracia, bloqueo comercial.
 *
 * Estados retirados en consolidación de migraciones (no se generan):
 *  - verified (colapsado en active).
 *  - mora / delinquent (colapsados en past_due).
 *
 * Cronología de mora:
 *  - past_due_started_at: timestamp en que entró en past_due (null al liquidar).
 *  - expected_block_at: fecha cache para countdown — past_due_started_at + gracia.
 *  - payment_blocked_at: timestamp en que se aplicó la suspensión.
 *  - last_paid_at: fecha del último invoice.payments.paid_at exitoso.
 *
 * Trial extendido:
 *  - paid_billing_starts_at: primer día facturable. NULL = usa el trial global
 *    `BILLING_TRIAL_DAYS`. Valor seteado = la app sólo genera invoices para
 *    periodos `period_from >= paid_billing_starts_at`. El comando
 *    `billing:extend-trial --nit=X --months=N` y el workflow ops
 *    `.github/workflows/company-trial.yml` setean esta fecha como
 *    `created_at + N meses`.
 *
 * NIT inmutable: una vez creada la empresa, su NIT NO se puede modificar
 * porque es el identificador estable hacia DIAN (facturas electrónicas),
 * comprobantes en S3, JWTs (`active_company_nit`), cache keys y cualquier
 * sistema externo. Si hubo error de digitación, dar de baja y crear nueva.
 * Triple defensa al final del método `up()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            // PK interna UUID. El default `gen_random_uuid()` (Postgres 13+,
            // disponible nativo sin extensión `uuid-ossp`) cubre inserts raw
            // (seeders SQL, workflows ops); Eloquent llena el id via el trait
            // HasUuids en App\Models\Company.
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // NIT: identificador estable y único de cara a DIAN, JWT,
            // comprobantes en S3 y FKs en 31+ tablas hijas (company_nit).
            // No es PK porque queremos id interno desacoplado, pero sigue
            // siendo UNIQUE + inmutable (trigger más abajo).
            $table->string('nit')->unique();
            $table->string('commercial_name');
            $table->string('legal_name');
            $table->string('qr_code_path')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('breb_key')->nullable();
            $table->string('tax_regime', 20)->default('simple');
            $table->decimal('default_tax_rate', 5, 2)->default(0);
            $table->string('default_tax_label', 60)->default('Sin IVA');
            $table->boolean('tax_included_in_price')->default(true);
            $table->uuid('bank_id');
            $table->string('account_number');
            $table->string('account_type');
            $table->enum('status', [
                'pending_activation',
                'rejected',
                'active',
                'inactive',
                'past_due',
                'suspended',
            ])->default('pending_activation');
            // Cronología de mora.
            $table->timestamp('past_due_started_at')->nullable();
            $table->date('expected_block_at')->nullable();
            $table->timestamp('payment_blocked_at')->nullable();
            $table->date('last_paid_at')->nullable();
            // Trial extendido: primer día facturable. NULL = trial global.
            $table->date('paid_billing_starts_at')->nullable();
            $table->string('plan')->default('free');
            $table->timestamps();

            $table->foreign('bank_id')->references('id')->on('banks');
        });

        Schema::create('company_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('key');
            $table->text('value');
            $table->string('type')->default('string');
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique(['company_nit', 'key']);
            $table->index('company_nit');
        });

        Schema::create('user_active_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('signature', 512);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Trigger BEFORE UPDATE OF nit (defensa #1 — BD).
        // Rechaza con mensaje claro cualquier intento de mutar el NIT, incluyendo
        // raw SQL, scripts ops y cualquier camino que bypaseé la app. Cualquier
        // FK existente hacia companies(nit) seguiría siendo restrictiva (`NO
        // ACTION` default), pero el trigger garantiza el bloqueo aun cuando la
        // empresa no tenga ninguna FK referenciándola.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION companies_prevent_nit_update()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.nit IS DISTINCT FROM OLD.nit THEN
                        RAISE EXCEPTION 'companies.nit es inmutable (intento: % -> %). Crear empresa nueva si el NIT es incorrecto.',
                            OLD.nit, NEW.nit
                            USING ERRCODE = 'P0001', HINT = 'No se permite cambiar el NIT despues de la creacion.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER companies_prevent_nit_update_trigger
                BEFORE UPDATE OF nit ON companies
                FOR EACH ROW
                EXECUTE FUNCTION companies_prevent_nit_update();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS companies_prevent_nit_update_trigger ON companies');
            DB::statement('DROP FUNCTION IF EXISTS companies_prevent_nit_update()');
        }

        Schema::dropIfExists('user_active_tokens');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('companies');
    }
};
