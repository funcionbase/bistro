<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración del proveedor DIAN por empresa.
 *
 * Tabla diseñada para soportar **cambio de proveedor** transparente:
 *  - `provider_slug` es la única columna que decide qué impl de
 *    `DianProviderContract` se instancia (`mock` hoy; `factura1`/`siigo`/etc.
 *    en el futuro).
 *  - Credenciales sensibles van `cast 'encrypted'` en el modelo. JAMÁS se
 *    exponen en GET (la API las enmascara con `***` + endpoint dedicado para
 *    rotar).
 *  - `webhook_secret_encrypted` se usa para validar HMAC del webhook DIAN.
 *  - `environment` (`habilitacion`/`produccion`) se snapshot-ea en cada
 *    `electronic_documents.dian_environment_code` para que un cambio post-
 *    emisión no contamine documentos previos.
 *
 * UNIQUE parcial: una sola config activa por empresa. La rotación es
 * "crear nueva con is_active=true → marcar la anterior is_active=false en
 * la misma transacción" (paper trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_provider_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('provider_slug', 30);
            $table->string('api_base_url', 500)->nullable();
            $table->text('api_token_encrypted')->nullable();
            $table->string('software_id', 100)->nullable();
            $table->text('software_pin_encrypted')->nullable();
            $table->string('test_set_id', 100)->nullable();
            $table->string('environment', 20);
            $table->text('webhook_secret_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->index('company_nit');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dian_provider_configs_active_unique
                ON dian_provider_configs (company_nit)
                WHERE is_active = true
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dian_provider_configs_active_unique');
        }
        Schema::dropIfExists('dian_provider_configs');
    }
};
