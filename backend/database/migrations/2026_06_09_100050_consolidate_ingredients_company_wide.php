<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Costeo multi-sede/multi-bodega — Fase 2, migración 6/7 (consolidación).
 *
 * El insumo deja de ser por-sede (`ingredients.branch_id`) y pasa a ser un
 * **catálogo de empresa**. Política A (plan §6): fusión automática de homónimos
 * por `(company_nit, nombre_normalizado, unit)`.
 *
 * Riesgo financiero: BAJO. El WAC vive (tras la migración siguiente) en
 * `ingredient_stocks.current_cost` por bodega, y las bodegas pre-migración son
 * disjuntas por sede → fusionar la identidad del insumo NO mezcla ni altera
 * costos: cada fila de stock conserva su bodega y su valor. El riesgo es
 * semántico (conflar dos productos distintos con el mismo nombre+unidad), que
 * sólo importa al compartir una bodega entre sedes a futuro. Mitigación:
 * artefacto de auditoría + veto pre-PDN en QA + escape hatch en UI.
 *
 * Pasos (todo dentro de una transacción):
 *  1. Agrupar insumos vivos por `(company_nit, nombre_norm, unit)`.
 *  2. Por grupo con 2+ insumos: elegir superviviente (mayor stock total,
 *     desempate por más antiguo) y re-apuntar las 6 FKs hacia él
 *     (recipes, ingredient_stocks, ingredient_movements, supplier_ingredients,
 *     purchase_order_items, warehouse_stock_snapshots), resolviendo colisiones
 *     de unique con merge/skip. Borrar el insumo duplicado (catálogo, no
 *     documento contable — las FKs ya se re-apuntaron).
 *  3. Emitir artefacto de auditoría (grupos, mapping viejo→superviviente,
 *     conteos, costo/stock) a `storage/app/inventory/` + Log.
 *  4. Homónimos con **unidad distinta** NO se fusionan: para no violar el nuevo
 *     unique `(company_nit, name)` se renombran con sufijo `" (rev)"`.
 *  5. Drop `branch_id` + unique/índices viejos; nuevo unique `(company_nit, name)`.
 *
 * `current_cost` NO se dropea aquí — la migración de WAC por bodega lo usa como
 * fallback de backfill antes de eliminarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $mergeLog = $this->consolidateDuplicates();
            $renameLog = $this->dedupeRemainingNames();

            $this->writeAuditArtifact($mergeLog, $renameLog);
        });

        // 5) Schema: quitar branch_id y reescalar el unique a company-wide.
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropUnique('ingredients_company_branch_name_unique');
            $table->dropIndex('ingredients_company_branch_idx');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('branch_id');
            $table->unique(['company_nit', 'name'], 'ingredients_company_name_unique');
        });
    }

    public function down(): void
    {
        // Reversible solo estructuralmente (la fusión de datos NO se deshace: el
        // mapping viejo→superviviente quedó en el artefacto de auditoría; para
        // revertir en QA se restaura el dump previo). Recrea branch_id nullable,
        // lo backfillea con la sede primaria de la bodega default del insumo, y
        // restaura el unique por sede.
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique('ingredients_company_name_unique');
            $table->uuid('branch_id')->nullable()->after('company_nit');
        });

        // Backfill branch_id: sede de la asignación default de cualquier bodega
        // donde el insumo tenga stock; si no, primera sede de la empresa.
        $ingredients = DB::table('ingredients')->get(['id', 'company_nit']);
        foreach ($ingredients as $ingredient) {
            $branchId = DB::table('ingredient_stocks as s')
                ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 's.warehouse_id')
                ->where('s.ingredient_id', $ingredient->id)
                ->orderByDesc('bw.is_default')
                ->value('bw.branch_id');

            if ($branchId === null) {
                $branchId = DB::table('branches')
                    ->where('company_nit', $ingredient->company_nit)
                    ->orderBy('created_at')
                    ->value('id');
            }

            if ($branchId !== null) {
                DB::table('ingredients')->where('id', $ingredient->id)->update(['branch_id' => $branchId]);
            }
        }

        DB::statement('ALTER TABLE ingredients ALTER COLUMN branch_id SET NOT NULL');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'name'], 'ingredients_company_branch_name_unique');
            $table->index(['company_nit', 'branch_id'], 'ingredients_company_branch_idx');
        });
    }

    /**
     * Fusiona grupos `(company_nit, nombre_norm, unit)` con 2+ insumos.
     *
     * @return list<array{company_nit:string, name:string, unit:string, survivor_id:string, merged:list<array{id:string,name:string,branch_id:string|null,stock:string,current_cost:string}>}>
     */
    private function consolidateDuplicates(): array
    {
        $ingredients = DB::table('ingredients')
            ->whereNull('archived_at')
            ->orderBy('created_at')
            ->get(['id', 'company_nit', 'branch_id', 'name', 'unit', 'current_cost', 'created_at']);

        // Stock total por insumo (para elegir superviviente).
        $stockByIngredient = DB::table('ingredient_stocks')
            ->select('ingredient_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('ingredient_id')
            ->pluck('total', 'ingredient_id');

        /** @var array<string, list<object>> $groups */
        $groups = [];
        foreach ($ingredients as $ingredient) {
            $key = $ingredient->company_nit.'|'.$this->normalizeName($ingredient->name).'|'.$ingredient->unit;
            $groups[$key][] = $ingredient;
        }

        $mergeLog = [];

        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            // Superviviente: mayor stock total; desempate por más antiguo
            // (el array ya viene ordenado por created_at asc).
            usort($members, function ($a, $b) use ($stockByIngredient) {
                $stockA = (string) ($stockByIngredient[$a->id] ?? '0');
                $stockB = (string) ($stockByIngredient[$b->id] ?? '0');
                $cmp = bccomp($stockB, $stockA, 3);

                return $cmp !== 0 ? $cmp : ($a->created_at <=> $b->created_at);
            });

            $survivor = array_shift($members);
            $mergedDetails = [];

            foreach ($members as $dup) {
                $this->repointForeignKeys($dup->id, $survivor->id, $survivor->company_nit);
                DB::table('ingredients')->where('id', $dup->id)->delete();

                $mergedDetails[] = [
                    'id' => $dup->id,
                    'name' => $dup->name,
                    'branch_id' => $dup->branch_id,
                    'stock' => (string) ($stockByIngredient[$dup->id] ?? '0'),
                    'current_cost' => (string) $dup->current_cost,
                ];
            }

            $mergeLog[] = [
                'company_nit' => $survivor->company_nit,
                'name' => $survivor->name,
                'unit' => $survivor->unit,
                'survivor_id' => $survivor->id,
                'merged' => $mergedDetails,
            ];
        }

        return $mergeLog;
    }

    /**
     * Re-apunta las 6 FKs de un insumo duplicado al superviviente, resolviendo
     * colisiones contra los uniques de cada tabla destino.
     */
    private function repointForeignKeys(string $dupId, string $survivorId, string $companyNit): void
    {
        // ingredient_stocks: unique (ingredient_id, warehouse_id). Si el
        // superviviente ya tiene stock en esa bodega, sumamos cantidades y
        // tomamos el min_stock mayor; si no, re-apuntamos la fila.
        $dupStocks = DB::table('ingredient_stocks')->where('ingredient_id', $dupId)->get();
        foreach ($dupStocks as $stock) {
            $survivorStock = DB::table('ingredient_stocks')
                ->where('ingredient_id', $survivorId)
                ->where('warehouse_id', $stock->warehouse_id)
                ->first();

            if ($survivorStock === null) {
                DB::table('ingredient_stocks')->where('id', $stock->id)->update(['ingredient_id' => $survivorId]);

                continue;
            }

            DB::table('ingredient_stocks')->where('id', $survivorStock->id)->update([
                'quantity' => bcadd((string) $survivorStock->quantity, (string) $stock->quantity, 3),
                'min_stock' => bccomp((string) $stock->min_stock, (string) $survivorStock->min_stock, 3) > 0
                    ? $stock->min_stock
                    : $survivorStock->min_stock,
                'updated_at' => now(),
            ]);
            DB::table('ingredient_stocks')->where('id', $stock->id)->delete();
        }

        // supplier_ingredients: unique (supplier_id, ingredient_id). Si el
        // superviviente ya tiene fila para ese proveedor, conservamos la de
        // compra más reciente.
        $dupSupplierLinks = DB::table('supplier_ingredients')->where('ingredient_id', $dupId)->get();
        foreach ($dupSupplierLinks as $link) {
            $survivorLink = DB::table('supplier_ingredients')
                ->where('ingredient_id', $survivorId)
                ->where('supplier_id', $link->supplier_id)
                ->first();

            if ($survivorLink === null) {
                DB::table('supplier_ingredients')->where('id', $link->id)->update(['ingredient_id' => $survivorId]);

                continue;
            }

            $linkNewer = $link->last_purchased_at !== null
                && ($survivorLink->last_purchased_at === null || $link->last_purchased_at > $survivorLink->last_purchased_at);

            if ($linkNewer) {
                DB::table('supplier_ingredients')->where('id', $survivorLink->id)->update([
                    'last_unit_cost' => $link->last_unit_cost,
                    'last_purchased_at' => $link->last_purchased_at,
                ]);
            }
            DB::table('supplier_ingredients')->where('id', $link->id)->delete();
        }

        // recipes: unique parcial (company_nit, branch_id, menu_item_id,
        // ingredient_id) sobre filas activas. Si re-apuntar crearía una activa
        // duplicada en la misma sede/item, archivamos la del duplicado.
        $dupRecipes = DB::table('recipes')->where('ingredient_id', $dupId)->get();
        foreach ($dupRecipes as $recipe) {
            $collision = $recipe->archived_at === null && DB::table('recipes')
                ->where('company_nit', $recipe->company_nit)
                ->where('branch_id', $recipe->branch_id)
                ->where('menu_item_id', $recipe->menu_item_id)
                ->where('ingredient_id', $survivorId)
                ->whereNull('archived_at')
                ->exists();

            if ($collision) {
                DB::table('recipes')->where('id', $recipe->id)->update([
                    'ingredient_id' => $survivorId,
                    'archived_at' => now(),
                ]);

                continue;
            }

            DB::table('recipes')->where('id', $recipe->id)->update(['ingredient_id' => $survivorId]);
        }

        // warehouse_stock_snapshots: unique (warehouse_id, ingredient_id,
        // snapshot_date). En colisión (histórico solamente) borramos el del
        // duplicado.
        $dupSnapshots = DB::table('warehouse_stock_snapshots')->where('ingredient_id', $dupId)->get();
        foreach ($dupSnapshots as $snapshot) {
            $collision = DB::table('warehouse_stock_snapshots')
                ->where('ingredient_id', $survivorId)
                ->where('warehouse_id', $snapshot->warehouse_id)
                ->where('snapshot_date', $snapshot->snapshot_date)
                ->exists();

            if ($collision) {
                DB::table('warehouse_stock_snapshots')->where('id', $snapshot->id)->delete();

                continue;
            }

            DB::table('warehouse_stock_snapshots')->where('id', $snapshot->id)->update(['ingredient_id' => $survivorId]);
        }

        // ingredient_movements y purchase_order_items: sin unique sobre
        // ingredient_id → bulk update directo (preservan el rastro contable).
        DB::table('ingredient_movements')->where('ingredient_id', $dupId)->update(['ingredient_id' => $survivorId]);
        DB::table('purchase_order_items')->where('ingredient_id', $dupId)->update(['ingredient_id' => $survivorId]);
    }

    /**
     * Tras la fusión por (nombre,unidad), pueden quedar nombres repetidos que
     * colisionarían en el nuevo unique `(company_nit, name)` — que es
     * **no-parcial** (aplica a filas activas Y archivadas):
     *  - homónimos activos con **unidad distinta** (no fusionados);
     *  - insumos **archivados** con el mismo nombre que un activo o entre sí
     *    (la fusión solo opera sobre activos, así que los archivados llegan acá
     *    intactos y deben deduplicarse o el índice unique falla).
     *
     * Canónico = activo primero, luego mayor stock, luego más antiguo. El resto
     * se renombra con sufijo de revisión (renombrar un archivado es inocuo: solo
     * cambia su etiqueta histórica).
     *
     * @return list<array{id:string, old_name:string, new_name:string, company_nit:string, archived:bool}>
     */
    private function dedupeRemainingNames(): array
    {
        // Incluye archivados: el unique destino no los excluye.
        $collisions = DB::table('ingredients')
            ->select('company_nit', 'name', DB::raw('count(*) as cnt'))
            ->groupBy('company_nit', 'name')
            ->havingRaw('count(*) > 1')
            ->get();

        $stockByIngredient = DB::table('ingredient_stocks')
            ->select('ingredient_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('ingredient_id')
            ->pluck('total', 'ingredient_id');

        $renameLog = [];

        foreach ($collisions as $collision) {
            $members = DB::table('ingredients')
                ->where('company_nit', $collision->company_nit)
                ->where('name', $collision->name)
                ->orderBy('created_at')
                ->get(['id', 'name', 'created_at', 'archived_at']);

            $sorted = $members->sort(function ($a, $b) use ($stockByIngredient) {
                // Activo (archived_at null) gana al archivado como canónico.
                $archA = $a->archived_at === null ? 0 : 1;
                $archB = $b->archived_at === null ? 0 : 1;
                if ($archA !== $archB) {
                    return $archA <=> $archB;
                }

                $stockA = (string) ($stockByIngredient[$a->id] ?? '0');
                $stockB = (string) ($stockByIngredient[$b->id] ?? '0');
                $cmp = bccomp($stockB, $stockA, 3);

                return $cmp !== 0 ? $cmp : ($a->created_at <=> $b->created_at);
            })->values();

            // El primero conserva el nombre; el resto se renombra.
            foreach ($sorted->slice(1) as $idx => $member) {
                $newName = $this->uniqueIngredientName($collision->company_nit, $member->name.' (rev)');
                DB::table('ingredients')->where('id', $member->id)->update(['name' => $newName]);

                $renameLog[] = [
                    'id' => $member->id,
                    'old_name' => $member->name,
                    'new_name' => $newName,
                    'company_nit' => $collision->company_nit,
                    'archived' => $member->archived_at !== null,
                ];
            }
        }

        return $renameLog;
    }

    /**
     * Garantiza un nombre único por empresa (sufijo numérico si hace falta).
     * Considera TODAS las filas (activas y archivadas) porque el unique destino
     * `(company_nit, name)` es no-parcial.
     */
    private function uniqueIngredientName(string $companyNit, string $base): string
    {
        $base = mb_substr($base, 0, 150);
        $candidate = $base;
        $n = 1;

        while (
            DB::table('ingredients')
                ->where('company_nit', $companyNit)
                ->where('name', $candidate)
                ->exists()
        ) {
            $n++;
            $suffix = ' '.$n;
            $candidate = mb_substr($base, 0, 150 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /** Normaliza para agrupar: NFC + trim + colapsar espacios + minúsculas. */
    private function normalizeName(string $name): string
    {
        if (class_exists(Normalizer::class)) {
            $name = Normalizer::normalize($name, Normalizer::FORM_C) ?: $name;
        }

        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;

        return mb_strtolower($name);
    }

    /**
     * Escribe el artefacto de auditoría (revisable en QA antes de PDN).
     *
     * @param  list<array<string, mixed>>  $mergeLog
     * @param  list<array<string, mixed>>  $renameLog
     */
    private function writeAuditArtifact(array $mergeLog, array $renameLog): void
    {
        $summary = [
            'migration' => 'consolidate_ingredients_company_wide',
            'generated_at' => now()->toIso8601String(),
            'groups_merged' => count($mergeLog),
            'ingredients_removed' => array_sum(array_map(fn ($g) => count($g['merged']), $mergeLog)),
            'names_renamed' => count($renameLog),
            'merges' => $mergeLog,
            'renames' => $renameLog,
        ];

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = 'inventory/consolidation-'.now()->format('Ymd_His').'-'.Str::random(6).'.json';

        try {
            Storage::disk('local')->put($path, $json);
        } catch (Throwable $e) {
            // El artefacto en disco es deseable pero no debe abortar la
            // migración; el Log queda como respaldo.
            Log::warning('No se pudo persistir el artefacto de consolidación de insumos.', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Consolidación de insumos company-wide ejecutada.', [
            'groups_merged' => $summary['groups_merged'],
            'ingredients_removed' => $summary['ingredients_removed'],
            'names_renamed' => $summary['names_renamed'],
            'artifact' => $path,
        ]);
    }
};
