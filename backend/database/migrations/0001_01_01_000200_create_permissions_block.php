<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 02 — Permissions + Members.
 *
 * Sistema RBAC + membresías de usuario en empresa. Orden FK importante:
 * features → company_roles → company_role_permissions → permission_templates →
 * company_users → company_invitations.
 *
 *  - features: catálogo global de features (slug, name, group, is_owner_only).
 *    is_owner_only=true bloquea toda asignación a roles no-system.
 *  - company_roles: roles por empresa (incluye is_system para owner-bypass + color para badge).
 *  - company_role_permissions: matriz CRUD por feature/role.
 *  - permission_templates: plantillas globales (owner/admin/employee) usadas al crear empresa.
 *  - company_users: pivot user↔company con company_role_id + status (active/revoked).
 *  - company_invitations: invitaciones por email con token + role_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->string('group', 32)->nullable();
            $table->boolean('is_owner_only')->default(false);
            $table->timestamps();
        });

        Schema::create('company_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 32);
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('color', 7)->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->index('company_nit');
            $table->unique(['company_nit', 'name']);
        });

        Schema::create('company_role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_role_id');
            $table->uuid('feature_id');
            $table->boolean('can_create')->default(false);
            $table->boolean('can_read')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            $table->foreign('company_role_id')->references('id')->on('company_roles')->cascadeOnDelete();
            $table->foreign('feature_id')->references('id')->on('features')->cascadeOnDelete();
            $table->unique(['company_role_id', 'feature_id']);
        });

        Schema::create('permission_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role_type', 16);
            $table->uuid('feature_id');
            $table->boolean('can_create')->default(false);
            $table->boolean('can_read')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            $table->foreign('feature_id')->references('id')->on('features')->cascadeOnDelete();
            $table->unique(['role_type', 'feature_id']);
        });

        Schema::create('company_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 32);
            $table->uuid('user_id');
            $table->uuid('company_role_id');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_role_id')->references('id')->on('company_roles')->cascadeOnDelete();
            $table->unique(['company_nit', 'user_id']);
            $table->index('company_role_id');
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('email');
            $table->enum('role', ['owner', 'admin', 'member'])->default('member');
            $table->uuid('company_role_id')->nullable();
            $table->string('token')->unique();
            $table->enum('status', ['pending', 'accepted', 'expired'])->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('company_role_id')->references('id')->on('company_roles')->nullOnDelete();
            $table->index(['email', 'status']);
            $table->index('company_nit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('company_users');
        Schema::dropIfExists('permission_templates');
        Schema::dropIfExists('company_role_permissions');
        Schema::dropIfExists('company_roles');
        Schema::dropIfExists('features');
    }
};
