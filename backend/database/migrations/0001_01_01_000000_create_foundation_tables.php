<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 00 — Foundation.
 *
 * Tablas base de Laravel + tablas globales que no dependen de empresa:
 *  - users (con campos de enrollment ya integrados: google_id, first_name, last_name, cedula, status)
 *  - password_reset_tokens, sessions (auth Laravel)
 *  - cache, cache_locks (driver cache=database)
 *  - jobs, job_batches, failed_jobs (queue=database)
 *  - banks (catálogo global de bancos colombianos para enrollment)
 *  - audit_logs (bitácora cross-company; FK a users)
 *  - user_acceptances (aceptaciones de TOS/privacy/contract por usuario)
 *  - legal_documents (versiones publicadas de TOS/privacy/contract)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('google_id')->nullable()->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            // `name` es una columna GENERADA (stored): se deriva siempre de
            // first_name + last_name y nunca se escribe directo. La expresión es
            // null-safe (TRIM + COALESCE) y portable entre PostgreSQL (pdn) y
            // SQLite (tests). Para BDs existentes ver la migración
            // 2026_05_31_000000_make_users_name_generated.
            $table->string('name')->storedAs("TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))");
            $table->string('cedula')->nullable()->unique();
            $table->string('email')->unique();
            $table->enum('status', ['pending_enrollment', 'active', 'inactive'])
                ->default('pending_enrollment');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('code', 10)->nullable();
            $table->string('swift', 20)->nullable();
            $table->enum('type', ['banco', 'neobanco', 'cooperativa', 'fintech'])->default('banco');
            $table->string('website')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('logo_url')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
            $table->index('created_at');
        });

        Schema::create('user_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['tos', 'privacy', 'contract']);
            $table->string('document_version');
            $table->text('document_content');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document_type']);
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['terms', 'privacy', 'contract']);
            $table->string('version');
            $table->text('content');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'version']);
        });

        // Marca sessions y cache como UNLOGGED en Postgres. Ambas son
        // regenerables (session_lost = relogin, cache_lost = recálculo). Sin WAL
        // bajamos I/O contra Supabase porque session/cache se tocan en casi todos
        // los requests. NO aplica a cache_locks, jobs ni tablas de negocio.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sessions SET UNLOGGED');
            DB::statement('ALTER TABLE cache SET UNLOGGED');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('user_acceptances');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
