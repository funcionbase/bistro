<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 13 — Multibodega.
 *
 * Introduce el concepto de bodega como subdivisión de inventario dentro de
 * una sede. Una sede puede tener N bodegas (cocina caliente, cocina fría,
 * barra, bodega seca, congelador). Cada insumo lleva stock independiente
 * por bodega; los reportes pueden agregar por bodega o por sede.
 *
 * Cambios estructurales:
 *  - warehouses: subdivisión de inventario por sede. PK uuid (como branches).
 *  - ingredient_stocks: stock por (ingredient, warehouse). Reemplaza la
 *    denormalización en ingredients.current_stock/min_stock.
 *  - ingredient_movements: agrega warehouse_id (origen) y dest_warehouse_id
 *    (solo en type='transfer'). Cada movimiento opera sobre UN ingredient_stock.
 *  - recipes: agrega warehouse_id por línea — cada ingrediente declara de
 *    qué bodega se descuenta al confirmar la venta.
 *  - purchase_order_items: agrega warehouse_id — cada item entra a la
 *    bodega correspondiente al recibir la compra.
 *  - ingredients: elimina current_stock y min_stock (single source en
 *    ingredient_stocks). current_cost se conserva (WAC global del insumo).
 *  - warehouse_stock_snapshots: snapshots diarios para series temporales de
 *    valor del inventario (alineado con menu_item_cost_history).
 *
 * El stock total de un insumo en una sede se obtiene como:
 *   SELECT SUM(quantity) FROM ingredient_stocks
 *   JOIN warehouses ON warehouses.id = ingredient_stocks.warehouse_id
 *   WHERE warehouses.branch_id = ? AND ingredient_stocks.ingredient_id = ?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('name', 120);
            $table->string('slug', 64);
            $table->string('type', 24)->default('main');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'slug'], 'warehouses_company_branch_slug_unique');
            $table->index(['company_nit', 'branch_id', 'archived_at'], 'warehouses_company_branch_archived_idx');
        });
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_type_valid CHECK (type IN ('main','kitchen','bar','cold_storage','dry_storage'))");
        DB::statement('CREATE UNIQUE INDEX warehouses_one_default_per_branch
            ON warehouses (branch_id)
            WHERE is_default = TRUE AND archived_at IS NULL');

        Schema::create('ingredient_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->uuid('warehouse_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['ingredient_id', 'warehouse_id'], 'ingredient_stocks_ingredient_warehouse_unique');
            $table->index(['warehouse_id', 'quantity'], 'ingredient_stocks_warehouse_quantity_idx');
        });
        DB::statement('ALTER TABLE ingredient_stocks ADD CONSTRAINT ingredient_stocks_min_non_negative CHECK (min_stock >= 0)');

        // ingredient_movements: warehouse_id origen (obligatorio) + dest_warehouse_id
        // solo para type='transfer'. Sin valor por defecto: el InventoryService
        // exige warehouse_id al crear cualquier movimiento.
        Schema::table('ingredient_movements', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->after('branch_id');
            $table->uuid('dest_warehouse_id')->nullable()->after('warehouse_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('dest_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index(['warehouse_id', 'created_at'], 'ingredient_movements_warehouse_created_idx');
        });
        // Constraint: dest_warehouse_id sólo válido en transfer. Tras el backfill
        // (vía seeders / data migration) y antes de cargar producción, se debe
        // hacer NOT NULL warehouse_id.
        DB::statement("ALTER TABLE ingredient_movements ADD CONSTRAINT ingredient_movements_transfer_dest_check CHECK (
            (type = 'transfer' AND dest_warehouse_id IS NOT NULL)
            OR (type <> 'transfer' AND dest_warehouse_id IS NULL)
        )");

        Schema::table('recipes', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->after('ingredient_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index('warehouse_id', 'recipes_warehouse_id_idx');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->after('ingredient_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index('warehouse_id', 'purchase_order_items_warehouse_id_idx');
        });

        // Eliminamos current_stock y min_stock de ingredients — fuente única
        // en ingredient_stocks. current_cost se conserva como WAC global del
        // insumo (independiente de bodega; el WAC se calcula sobre todas las
        // entries del insumo, no por bodega).
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['current_stock', 'min_stock']);
        });

        // Snapshots diarios del valor del inventario por (warehouse, ingredient).
        // Permite series temporales sin recalcular movements (que pueden ser
        // millones tras 1 año). Alineado con menu_item_cost_history.
        Schema::create('warehouse_stock_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_value', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['warehouse_id', 'ingredient_id', 'snapshot_date'], 'wss_warehouse_ingredient_date_unique');
            $table->index(['company_nit', 'branch_id', 'snapshot_date'], 'wss_company_branch_date_idx');
            $table->index(['company_nit', 'snapshot_date'], 'wss_company_date_idx');
        });
        DB::statement('ALTER TABLE warehouse_stock_snapshots ADD CONSTRAINT wss_quantity_check CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE warehouse_stock_snapshots ADD CONSTRAINT wss_unit_cost_check CHECK (unit_cost >= 0)');
        DB::statement('ALTER TABLE warehouse_stock_snapshots ADD CONSTRAINT wss_line_value_check CHECK (line_value >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_snapshots');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(0);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex('purchase_order_items_warehouse_id_idx');
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex('recipes_warehouse_id_idx');
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });

        DB::statement('ALTER TABLE ingredient_movements DROP CONSTRAINT IF EXISTS ingredient_movements_transfer_dest_check');
        Schema::table('ingredient_movements', function (Blueprint $table) {
            $table->dropIndex('ingredient_movements_warehouse_created_idx');
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['dest_warehouse_id']);
            $table->dropColumn(['warehouse_id', 'dest_warehouse_id']);
        });

        Schema::dropIfExists('ingredient_stocks');

        DB::statement('DROP INDEX IF EXISTS warehouses_one_default_per_branch');
        Schema::dropIfExists('warehouses');
    }
};
