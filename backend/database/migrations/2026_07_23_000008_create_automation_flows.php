<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6 — flujos de automatización (n8n), opcionales y por (NIT, sede) (§5.6).
 *
 * n8n NO es obligatorio: la ausencia de flujo es el estado por defecto y válido
 * (la bandeja de operadores resuelve todo). La configuración va por (NIT, sede),
 * un eje distinto del canal de WhatsApp — por eso tabla propia y no columnas en
 * `company_whatsapp_accounts`.
 *
 * Autenticación del bot: token POR flujo (§7.5.1), no el `BOT_JWT_SECRET` global.
 * Se guarda el SHA-256 del componente aleatorio (`token_hash`, hash no cifrado:
 * un dump de la BD no entrega tokens usables) + `token_last4`/`token_created_at`
 * para mostrar en la UI. El secreto de firma del webhook saliente sí es cifrado
 * reversible (`secret_encrypted`): hay que reproducirlo para firmar el HMAC.
 *
 * Aditiva: tabla nueva, no toca nada existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_flows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            // NULL = flujo de empresa (fallback para toda sede sin flujo propio).
            $table->uuid('branch_id')->nullable();
            $table->string('label', 60)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('url');                        // webhook del flujo en n8n
            $table->text('secret_encrypted');             // firma HMAC del push saliente
            $table->json('events')->nullable();           // eventos suscritos
            $table->string('token_hash', 64)->nullable(); // SHA-256 del token (§7.5.1)
            $table->string('token_last4', 4)->nullable();
            $table->timestamp('token_created_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            // restrictOnDelete, igual que chats/contacts: borrar una sede no puede
            // llevarse en silencio su automatización. Las sedes se archivan
            // (archived_at), no se borran — si alguna vez se borra, que falle ruidoso.
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['company_nit', 'branch_id']);
        });

        // Invariante: máximo un flujo de empresa + máximo uno por sede.
        DB::statement('CREATE UNIQUE INDEX automation_flows_company_scope_unique ON automation_flows (company_nit)
                       WHERE branch_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX automation_flows_branch_scope_unique ON automation_flows (branch_id)
                       WHERE branch_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_flows');
    }
};
