<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costeo multi-sede/multi-bodega — Fase 3, migración 7/7 (WAC por bodega).
 *
 * El WAC (costo promedio ponderado) deja de ser global del insumo
 * (`ingredients.current_cost`) y pasa a vivir **por bodega** en
 * `ingredient_stocks.current_cost`. El stock ya era por bodega; ahora su valor
 * también. Esto habilita el costeo por sede: una receta costea desde la bodega
 * de su línea, no desde un promedio mezclado entre bodegas.
 *
 * Backfill (plan §4.4), por cada par `(insumo, bodega)`:
 *  1. Si la bodega tiene entradas (`type='entry'`) del insumo → WAC = promedio
 *     ponderado de esas entradas: `SUM(qty·unit_cost) / SUM(qty)`.
 *  2. Si no tiene entradas pero recibió stock por transferencia → hereda el WAC
 *     (basado en entradas) de la bodega origen de la transferencia más reciente.
 *  3. Fallback final → el WAC global viejo (`ingredients.current_cost`), leído
 *     ANTES de dropear la columna.
 *
 * Los movimientos son inmutables: sólo se LEEN para recomputar. `current_cost`
 * de `ingredients` se dropea aquí (no en la consolidación) porque este backfill
 * lo necesita como fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_stocks', function (Blueprint $table) {
            $table->decimal('current_cost', 12, 2)->default(0)->after('min_stock');
        });
        DB::statement('ALTER TABLE ingredient_stocks ADD CONSTRAINT ingredient_stocks_current_cost_non_negative CHECK (current_cost >= 0)');

        $this->backfillPerWarehouseWac();

        // Drop del WAC global: ya migrado a por-bodega.
        DB::statement('ALTER TABLE ingredients DROP CONSTRAINT IF EXISTS ingredients_current_cost_non_negative');
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('current_cost');
        });
    }

    public function down(): void
    {
        // Restaura el WAC global como el promedio (ponderado por stock) de los
        // WAC por bodega del insumo — aproximación inversa al split.
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('current_cost', 12, 2)->default(0)->after('unit');
        });
        DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_current_cost_non_negative CHECK (current_cost >= 0)');

        $rows = DB::table('ingredient_stocks')
            ->select(
                'ingredient_id',
                DB::raw('SUM(quantity * current_cost) as value'),
                DB::raw('SUM(quantity) as qty'),
            )
            ->groupBy('ingredient_id')
            ->get();

        foreach ($rows as $row) {
            $qty = (string) $row->qty;
            $cost = bccomp($qty, '0', 3) > 0
                ? bcdiv((string) $row->value, $qty, 2)
                : '0.00';

            DB::table('ingredients')->where('id', $row->ingredient_id)->update(['current_cost' => $cost]);
        }

        DB::statement('ALTER TABLE ingredient_stocks DROP CONSTRAINT IF EXISTS ingredient_stocks_current_cost_non_negative');
        Schema::table('ingredient_stocks', function (Blueprint $table) {
            $table->dropColumn('current_cost');
        });
    }

    private function backfillPerWarehouseWac(): void
    {
        // WAC por (insumo, bodega) basado en entradas. Clave: "ingredient|warehouse".
        $entryWac = [];
        $entryRows = DB::table('ingredient_movements')
            ->where('type', 'entry')
            ->select(
                'ingredient_id',
                'warehouse_id',
                DB::raw('SUM(quantity * unit_cost) as value'),
                DB::raw('SUM(quantity) as qty'),
            )
            ->groupBy('ingredient_id', 'warehouse_id')
            ->get();

        foreach ($entryRows as $row) {
            $qty = (string) $row->qty;
            if (bccomp($qty, '0', 3) <= 0) {
                continue;
            }
            $entryWac[$row->ingredient_id.'|'.$row->warehouse_id] = bcdiv((string) $row->value, $qty, 2);
        }

        // WAC global viejo como fallback final (se lee antes de dropear).
        $globalWac = DB::table('ingredients')->pluck('current_cost', 'id');

        $stocks = DB::table('ingredient_stocks')->get(['id', 'ingredient_id', 'warehouse_id']);

        foreach ($stocks as $stock) {
            $key = $stock->ingredient_id.'|'.$stock->warehouse_id;

            $wac = $entryWac[$key]
                ?? $this->inheritFromTransfer($stock->ingredient_id, $stock->warehouse_id, $entryWac)
                ?? (string) ($globalWac[$stock->ingredient_id] ?? '0');

            DB::table('ingredient_stocks')->where('id', $stock->id)->update([
                'current_cost' => bcadd($wac, '0', 2),
            ]);
        }
    }

    /**
     * Hereda el WAC de la bodega origen de la transferencia entrante más
     * reciente hacia esta bodega. La pata de entrada del transfer guarda
     * `quantity > 0` y `dest_warehouse_id = bodega_origen`.
     *
     * @param  array<string, string>  $entryWac
     */
    private function inheritFromTransfer(string $ingredientId, string $warehouseId, array $entryWac): ?string
    {
        $sourceWarehouseId = DB::table('ingredient_movements')
            ->where('type', 'transfer')
            ->where('ingredient_id', $ingredientId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->orderByDesc('created_at')
            ->value('dest_warehouse_id');

        if ($sourceWarehouseId === null) {
            return null;
        }

        return $entryWac[$ingredientId.'|'.$sourceWarehouseId] ?? null;
    }
};
