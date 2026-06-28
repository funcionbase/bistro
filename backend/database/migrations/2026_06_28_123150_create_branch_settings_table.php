<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración visual K/V por sede (personalización del menú público).
 *
 * Estructura idéntica a company_settings pero keyed por branch_id + company_nit.
 * company_nit se persiste directo para que EnsureCompanyAccess / BranchScope
 * puedan filtrar por NIT sin JOIN a branches.
 *
 * Claves iniciales: menu_header_image_url, menu_footer_image_url,
 * menu_tagline, menu_card_style, menu_show_branding.
 * Ver BranchSettingsService::ALLOWED_KEYS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('key', 64);
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'key']);
            $table->index('company_nit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
