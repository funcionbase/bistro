<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 10 — Inventory + Recipes + Purchases.
 *
 * Cada sede tiene su propio inventario y proveedores. branch_id NOT NULL
 * desde el inicio (BranchScope global).
 *
 * Convenciones contables:
 *  - Costos siempre en decimal(12,2) — COP, 2 decimales.
 *  - Tasas tributarias en decimal(5,2).
 *  - Cantidades de inventario en decimal(12,3) — kg/L/g/ml/un (NO monetario;
 *    requieren precisión adicional para gramos en recetas).
 *
 * ingredients:
 *  - current_stock/min_stock decimal(12,3): unidades físicas, no contables.
 *  - current_cost decimal(12,2): WAC (promedio ponderado) — denormalizado.
 *  - Fuente de verdad: ingredient_movements (append-only).
 *
 * ingredient_movements (append-only):
 *  - type ∈ {entry, adjustment, sale_consumption, waste, transfer}.
 *  - quantity firmada según tipo. unit_cost solo en entry (alimenta WAC).
 *
 * suppliers / supplier_ingredients:
 *  - Cachea last_unit_cost (NETO sin impuestos) por (supplier, ingredient).
 *
 * purchase_orders / items / credit_notes / attachments:
 *  - PO inmutable después de received|paid|voided (boot guard del modelo).
 *  - line_total = qty * unit_cost + tax_amount.
 *  - PurchaseCreditNote registra reverso con snapshot — append-only.
 *  - Attachments con soft-delete (DIAN: 5/10 años conservación).
 *
 * recipes (BOM por menu_item):
 *  - quantity decimal(12,3): permite gramos/ml en preparaciones.
 *  - Único parcial: una línea activa por (company_nit, menu_item_id, ingredient_id).
 *
 * menu_item_cost_history (snapshot diario):
 *  - computed_cost decimal(12,2). Append-only. Auto-contenida (snapshot de nombre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('name', 150);
            $table->string('category', 64)->nullable();
            $table->string('unit', 8);
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(0);
            $table->decimal('current_cost', 12, 2)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'archived_at']);
            $table->unique(['company_nit', 'branch_id', 'name'], 'ingredients_company_branch_name_unique');
            $table->index(['company_nit', 'branch_id'], 'ingredients_company_branch_idx');
        });
        DB::statement("ALTER TABLE ingredients ADD CONSTRAINT ingredients_unit_valid CHECK (unit IN ('kg','g','l','ml','un'))");
        DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_min_stock_non_negative CHECK (min_stock >= 0)');
        DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_current_cost_non_negative CHECK (current_cost >= 0)');

        Schema::create('ingredient_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('type', 24);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('reference', 255)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'ingredient_id', 'created_at'], 'ingredient_movements_lookup_idx');
            $table->index(['company_nit', 'branch_id'], 'ingredient_movements_company_branch_idx');
        });
        DB::statement("ALTER TABLE ingredient_movements ADD CONSTRAINT ingredient_movements_type_valid CHECK (type IN ('entry','adjustment','sale_consumption','waste','transfer'))");
        DB::statement('ALTER TABLE ingredient_movements ADD CONSTRAINT ingredient_movements_unit_cost_non_negative CHECK (unit_cost IS NULL OR unit_cost >= 0)');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('name', 150);
            $table->string('document_type', 16)->nullable();
            $table->string('document_number', 32)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'archived_at']);
            $table->index(['company_nit', 'name']);
            $table->index(['company_nit', 'branch_id'], 'suppliers_company_branch_idx');
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_company_doc_unique ON suppliers (company_nit, document_number) WHERE document_number IS NOT NULL');
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_document_type_valid CHECK (document_type IS NULL OR document_type IN ('NIT','CC','CE','PAS','OTRO'))");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_payment_terms_non_negative CHECK (payment_terms_days >= 0)');

        Schema::create('supplier_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('last_unit_cost', 12, 2)->default(0);
            $table->timestamp('last_purchased_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['supplier_id', 'ingredient_id']);
            $table->index('ingredient_id');
            $table->index('branch_id', 'supplier_ingredients_branch_id_idx');
        });
        DB::statement('ALTER TABLE supplier_ingredients ADD CONSTRAINT supplier_ingredients_cost_non_negative CHECK (last_unit_cost >= 0)');

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('status', 16)->default('draft');
            $table->date('expected_date')->nullable();
            $table->timestamp('received_date')->nullable();
            $table->timestamp('paid_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_method', 16)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->boolean('pending_supplier_refund')->default(false);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'status']);
            $table->index(['company_nit', 'supplier_id']);
            $table->index(['company_nit', 'created_at']);
            $table->unique(['company_nit', 'code']);
            $table->index(['company_nit', 'branch_id'], 'purchase_orders_company_branch_idx');
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_valid CHECK (status IN ('draft','pending','received','paid','cancelled','voided'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_payment_method_valid CHECK (payment_method IS NULL OR payment_method IN ('cash','card','transfer'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_subtotal_non_negative CHECK (subtotal >= 0)');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_tax_non_negative CHECK (tax_amount >= 0)');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_total_non_negative CHECK (total >= 0)');

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('purchase_order_id');
            $table->index('ingredient_id');
            $table->index('branch_id', 'purchase_order_items_branch_id_idx');
        });
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_unit_cost_non_negative CHECK (unit_cost >= 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_tax_rate_non_negative CHECK (tax_rate >= 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_tax_amount_non_negative CHECK (tax_amount >= 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_line_total_non_negative CHECK (line_total >= 0)');

        Schema::create('purchase_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('code', 32);
            $table->text('reason');
            $table->json('items_snapshot');
            $table->decimal('total_reversed', 12, 2)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'purchase_order_id']);
            $table->unique(['company_nit', 'code']);
            $table->index(['company_nit', 'branch_id'], 'purchase_credit_notes_company_branch_idx');
        });
        DB::statement('ALTER TABLE purchase_credit_notes ADD CONSTRAINT purchase_credit_notes_total_non_negative CHECK (total_reversed >= 0)');

        Schema::create('purchase_order_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('purchase_order_id');
            $table->index('branch_id', 'purchase_order_attachments_branch_id_idx');
        });
        DB::statement("ALTER TABLE purchase_order_attachments ADD CONSTRAINT purchase_order_attachments_type_valid CHECK (type IN ('invoice','delivery_note','payment_proof','other'))");

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('menu_id');
            $table->string('menu_item_id', 64);
            $table->uuid('ingredient_id');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 8);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('menu_id')->references('id')->on('restaurant_menus')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
            $table->index(['company_nit', 'menu_item_id']);
            $table->index(['company_nit', 'ingredient_id']);
            $table->index(['company_nit', 'branch_id'], 'recipes_company_branch_idx');
        });
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_unit_valid CHECK (unit IN ('kg','g','l','ml','un'))");
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_quantity_positive CHECK (quantity > 0)');
        DB::statement('CREATE UNIQUE INDEX recipes_company_item_ingredient_unique ON recipes (company_nit, menu_item_id, ingredient_id) WHERE archived_at IS NULL');

        Schema::create('menu_item_cost_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('menu_id');
            $table->string('menu_item_id', 64);
            $table->string('menu_item_name');
            $table->string('menu_item_category')->nullable();
            $table->date('snapshot_date');
            $table->decimal('computed_cost', 12, 2);
            $table->string('source', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('menu_id')->references('id')->on('restaurant_menus')->cascadeOnDelete();
            $table->unique(['company_nit', 'menu_item_id', 'snapshot_date'], 'mich_company_item_date_unique');
            $table->index(['company_nit', 'snapshot_date'], 'mich_company_date_idx');
            $table->index(['company_nit', 'menu_item_id'], 'mich_company_item_idx');
            $table->index(['company_nit', 'branch_id'], 'menu_item_cost_history_company_branch_idx');
        });
        DB::statement("ALTER TABLE menu_item_cost_history ADD CONSTRAINT mich_source_valid CHECK (source IN ('recipe','manual'))");
        DB::statement('ALTER TABLE menu_item_cost_history ADD CONSTRAINT mich_cost_non_negative CHECK (computed_cost >= 0)');
        DB::statement('ALTER TABLE menu_item_cost_history ADD CONSTRAINT mich_menu_item_id_present CHECK (char_length(menu_item_id) BETWEEN 1 AND 64)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_cost_history');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('purchase_order_attachments');
        Schema::dropIfExists('purchase_credit_notes');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_ingredients');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('ingredient_movements');
        Schema::dropIfExists('ingredients');
    }
};
