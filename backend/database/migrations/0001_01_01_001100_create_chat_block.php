<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 11 — Chat + WhatsApp.
 *
 * Conversaciones bot/operador y plataforma WhatsApp:
 *  - contacts: agenda por empresa (uno por phone). branch_id = sede dueña.
 *  - chats: conversación con cliente. Optimizada para volumen alto (timestamptz).
 *    bot_paused, handoff_*, meta_synced_at, source. Status check ('open'|'closed').
 *  - chat_messages: append-only. SIN created_at/updated_at — sent_at es suficiente.
 *    media_*: stickers/imagenes/audio/video. Unique parcial chat+meta_message_id.
 *    Check constraints sobre sender/status.
 *  - company_whatsapp_accounts: cuenta Meta por empresa (waba_id, phone_number_id).
 *  - company_whatsapp_account_events: bitácora del lifecycle de la cuenta WhatsApp.
 *  - meta_platform_credentials: credenciales globales de la plataforma (no por
 *    empresa — son del SaaS).
 *  - whatsapp_verification_codes: códigos OTP para connect/swap/disconnect.
 *  - webhook_events: log durable de webhooks entrantes (idempotencia + replay).
 *
 * branch_id NOT NULL en chats y contacts desde el inicio.
 * NO en webhook_events / whatsapp_* (son globales o por cuenta, no por sede).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('phone', 30);
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'phone']);
            $table->index(['company_nit', 'name']);
            $table->index(['company_nit', 'branch_id'], 'contacts_company_branch_idx');
        });

        Schema::create('chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('client_phone', 30);
            $table->string('client_name')->nullable();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('source', 32)->default('whatsapp');
            $table->string('status', 20)->default('open');
            $table->boolean('bot_paused')->default(false);
            $table->timestampTz('handoff_requested_at')->nullable();
            $table->string('handoff_reason', 255)->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('meta_synced_at')->nullable();
            $table->string('meta_conversation_id', 128)->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'client_phone']);
            $table->index(['company_nit', 'status', 'last_message_at']);
            $table->index(['company_nit', 'source']);
            $table->index(['company_nit', 'branch_id'], 'chats_company_branch_idx');
        });
        DB::statement("ALTER TABLE chats ADD CONSTRAINT chats_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('CREATE INDEX chats_handoff_pending_idx
            ON chats (company_nit, handoff_requested_at)
            WHERE handoff_requested_at IS NOT NULL');
        DB::statement('CREATE INDEX chats_bot_paused_idx
            ON chats (company_nit, last_message_at)
            WHERE bot_paused = TRUE');

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->string('sender', 16);
            $table->string('status', 24)->nullable();
            $table->text('body');
            $table->string('media_type', 32)->nullable();
            $table->string('media_meta_id', 128)->nullable();
            $table->string('media_path', 255)->nullable();
            $table->string('media_mime', 96)->nullable();
            $table->string('meta_message_id', 128)->nullable();
            $table->timestampTz('sent_at');

            $table->index(['chat_id', 'sent_at']);
        });
        DB::statement('CREATE UNIQUE INDEX chat_messages_meta_id_unique
            ON chat_messages (chat_id, meta_message_id)
            WHERE meta_message_id IS NOT NULL');
        DB::statement("ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_sender_check CHECK (sender IN ('client', 'bot', 'operator'))");
        DB::statement("ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_status_check CHECK (status IS NULL OR status IN ('sent', 'delivered', 'read', 'failed'))");

        Schema::create('company_whatsapp_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('provisioning_mode', 30)->default('embedded_signup');
            $table->string('status', 30)->default('pending');
            $table->string('waba_id')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('business_id')->nullable();
            $table->string('phone_e164', 30)->nullable();
            $table->string('display_name')->nullable();
            $table->string('display_name_status', 30)->nullable();
            $table->string('quality_rating', 20)->nullable();
            $table->string('messaging_tier', 30)->nullable();
            $table->string('verified_name')->nullable();
            $table->boolean('is_business_verified')->default(false);
            $table->text('access_token_encrypted')->nullable();
            $table->text('webhook_verify_token_encrypted')->nullable();
            $table->timestamp('webhook_subscribed_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('naas_provider', 50)->nullable();
            $table->string('naas_provider_account_ref')->nullable();
            $table->unsignedBigInteger('naas_contract_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique('company_nit');
            $table->unique('phone_number_id');
            $table->index('status');
            $table->index('waba_id');
        });

        Schema::create('company_whatsapp_account_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_whatsapp_account_id')->constrained('company_whatsapp_accounts')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->json('payload')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_whatsapp_account_id', 'created_at'], 'cwae_account_created_idx');
            $table->index('event_type');
        });

        Schema::create('meta_platform_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app_id');
            $table->text('app_secret_encrypted');
            $table->string('business_id');
            $table->string('system_user_id');
            $table->text('system_user_token_encrypted');
            $table->string('config_id');
            $table->string('solution_id')->nullable();
            $table->text('webhook_verify_token_encrypted');
            $table->string('graph_api_version')->default('v25.0');
            $table->string('environment', 20)->default('qa');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['environment', 'is_active']);
        });

        Schema::create('whatsapp_verification_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('requester_user_id');
            $table->uuid('owner_user_id');
            $table->string('action', 20);
            $table->string('code_hash');
            $table->string('reject_token', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('requester_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['company_nit', 'action', 'consumed_at']);
            $table->index('expires_at');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->index();
            $table->string('event_id', 128)->nullable()->index();
            $table->jsonb('payload');
            $table->string('signature_header', 1024)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->index(['provider', 'processed_at']);
            $table->index(['provider', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('whatsapp_verification_codes');
        Schema::dropIfExists('meta_platform_credentials');
        Schema::dropIfExists('company_whatsapp_account_events');
        Schema::dropIfExists('company_whatsapp_accounts');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
        Schema::dropIfExists('contacts');
    }
};
