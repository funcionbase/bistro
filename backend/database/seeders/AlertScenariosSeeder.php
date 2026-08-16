<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\RestaurantMenu;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fuerza condiciones que disparan los 4 tipos de alerta para que QA
 * pueda probar el feed en /dashboard inmediatamente después de `db:seed`.
 *
 * Idempotente: cada bloque borra/upserta su propio scratch antes de re-crear.
 * No toca datos del RestauranteFlexySeeder más allá de los ajustes mínimos
 * para que cada regla evalúe verdadero.
 *
 * Escenarios:
 *  1. margin_below   → inyecta `cost` alto (≈85% del price) en items de una
 *                       orden reciente de la sede Pereira.
 *  2. cost_increase  → crea 4 purchase_orders (2 antiguas a precio bajo + 2
 *                       recientes a +50%) para "Papa francesa precortada".
 *  3. item_low_volume→ agrega un item "Aros de cebolla descontinuados" a la
 *                       estructura del menú activo de Pereira (sin orders).
 *  4. low_stock      → fuerza quantity=0 en el stock de cocina de "Carne
 *                       desmechada" (que tiene min_stock=4).
 *
 * Tras correr, ejecutar `php artisan alerts:evaluate` para materializar los
 * eventos en `alert_events`.
 */
class AlertScenariosSeeder extends Seeder
{
    private const COMPANY_NIT = '1';

    public function run(): void
    {
        $company = Company::query()->where('nit', self::COMPANY_NIT)->first();
        if ($company === null) {
            $this->command?->warn('[alerts-scenarios] empresa {self::COMPANY_NIT} no existe — corre RestauranteFlexySeeder primero.');

            return;
        }

        $this->seedMarginBelow();
        $this->seedCostIncrease();
        $this->seedItemLowVolume();
        $this->seedLowStock();

        $this->command?->info('[alerts-scenarios] ✓ escenarios listos. Corre `php artisan alerts:evaluate` para materializar eventos.');
    }

    /**
     * margin_below: la "Salchipapa especial" tiene `cost = 6500` y
     * `price = 16000` en el catálogo (margen 59%). Tomamos órdenes recientes
     * de Pereira que la contienen y reescribimos su items[].cost a ≈85% del
     * price para que el margen quede en ~15% (muy por debajo de 30%).
     *
     * Sólo afectamos órdenes de los últimos 5 días para que caigan dentro de
     * la ventana default de margin_below (7 días).
     */
    private function seedMarginBelow(): void
    {
        $orders = DB::table('orders')
            ->where('company_nit', self::COMPANY_NIT)
            ->whereIn('status', config('orders.revenue'))
            ->where('ordered_at', '>=', now()->subDays(5))
            ->whereRaw("items::text LIKE '%salchipapa-especial%'")
            ->limit(20)
            ->get(['id', 'items']);

        $updated = 0;
        foreach ($orders as $order) {
            $items = json_decode($order->items, true) ?? [];
            $changed = false;
            foreach ($items as &$item) {
                if (($item['id'] ?? null) === 'salchipapa-especial') {
                    $price = (float) ($item['price'] ?? 0);
                    $item['cost'] = round($price * 0.85, 2);
                    $changed = true;
                }
            }
            unset($item);

            if ($changed) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['items' => json_encode($items)]);
                $updated++;
            }
        }

        $this->command?->info("[alerts-scenarios] margin_below: {$updated} órdenes reescritas con cost alto.");
    }

    /**
     * cost_increase: crea 4 purchase_orders sintéticas para "Papa francesa
     * precortada" — 2 en la ventana antigua (entre 14 y 7 días atrás) a
     * $3.000/kg, y 2 en la ventana reciente (últimos 7 días) a $4.500/kg.
     * Eso da un incremento del 50%, muy por encima del umbral 10% default.
     */
    private function seedCostIncrease(): void
    {
        $branchPereira = DB::table('branches')
            ->where('company_nit', self::COMPANY_NIT)
            ->where('slug', 'pereira')
            ->value('id');

        $ingredient = Ingredient::query()
            ->where('company_nit', self::COMPANY_NIT)
            ->where('name', 'Papa francesa precortada')
            ->first();

        $supplier = Supplier::query()
            ->where('company_nit', self::COMPANY_NIT)
            ->where('document_number', '900456001-3') // Frutiverduras
            ->first();

        if ($ingredient === null || $supplier === null || $branchPereira === null) {
            $this->command?->warn('[alerts-scenarios] cost_increase: skipped (ingrediente/proveedor/sede no encontrado).');

            return;
        }

        // Limpia scratch previo para idempotencia.
        DB::table('purchase_orders')
            ->where('company_nit', self::COMPANY_NIT)
            ->where('code', 'like', 'ALERT-COST-%')
            ->delete();

        $scenarios = [
            ['code' => 'ALERT-COST-OLD-1', 'days_ago' => 12, 'unit_cost' => 3000.00, 'qty' => 20],
            ['code' => 'ALERT-COST-OLD-2', 'days_ago' => 9, 'unit_cost' => 3000.00, 'qty' => 25],
            ['code' => 'ALERT-COST-NEW-1', 'days_ago' => 4, 'unit_cost' => 4500.00, 'qty' => 20],
            ['code' => 'ALERT-COST-NEW-2', 'days_ago' => 1, 'unit_cost' => 4500.00, 'qty' => 22],
        ];

        foreach ($scenarios as $s) {
            $receivedAt = now()->subDays($s['days_ago']);
            $lineTotal = $s['unit_cost'] * $s['qty'];

            $poId = (string) Str::uuid7();

            DB::table('purchase_orders')->insert([
                'id' => $poId,
                'company_nit' => self::COMPANY_NIT,
                'branch_id' => $branchPereira,
                'supplier_id' => $supplier->id,
                'code' => $s['code'],
                'status' => 'paid',
                'expected_date' => $receivedAt->toDateString(),
                'received_date' => $receivedAt,
                'paid_date' => $receivedAt,
                'subtotal' => $lineTotal,
                'tax_amount' => 0,
                'total' => $lineTotal,
                'payment_method' => 'transfer',
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);

            DB::table('purchase_order_items')->insert([
                'id' => (string) Str::uuid7(),
                'purchase_order_id' => $poId,
                'branch_id' => $branchPereira,
                'ingredient_id' => $ingredient->id,
                'description' => $ingredient->name,
                'quantity' => $s['qty'],
                'unit_cost' => $s['unit_cost'],
                'tax_rate' => 0,
                'tax_amount' => 0,
                'line_total' => $lineTotal,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
        }

        $this->command?->info('[alerts-scenarios] cost_increase: 4 POs (2 antiguas $3000 + 2 recientes $4500) para papa francesa.');
    }

    /**
     * item_low_volume: agrega un ítem "Aros de cebolla descontinuados" en la
     * estructura del menú activo de Pereira. Como no aparece en
     * orderCatalogForBranch, jamás se ordena → cae bajo el evaluador.
     */
    private function seedItemLowVolume(): void
    {
        $menu = RestaurantMenu::query()
            ->where('company_nit', self::COMPANY_NIT)
            ->where('status', 'active')
            ->first();

        if ($menu === null) {
            $this->command?->warn('[alerts-scenarios] item_low_volume: no hay menú activo.');

            return;
        }

        $structure = is_string($menu->structure) ? json_decode($menu->structure, true) : $menu->structure;
        if (! is_array($structure) || ! isset($structure['categories']) || ! is_array($structure['categories'])) {
            $this->command?->warn('[alerts-scenarios] item_low_volume: estructura del menú no reconocida.');

            return;
        }

        $injected = false;
        foreach ($structure['categories'] as &$cat) {
            if (($cat['id'] ?? null) === 'acompanamientos') {
                $items = $cat['items'] ?? [];
                $exists = false;
                foreach ($items as $item) {
                    if (($item['id'] ?? null) === 'aros-descontinuados') {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $items[] = [
                        'id' => 'aros-descontinuados',
                        'name' => 'Aros de cebolla descontinuados',
                        'description' => 'Item activo sin ventas — escenario de alerta item_low_volume.',
                        'price' => 8000,
                        'image_path' => null,
                        'available' => true,
                        'order' => 99,
                    ];
                    $cat['items'] = $items;
                    $injected = true;
                }
                break;
            }
        }
        unset($cat);

        if ($injected) {
            $menu->structure = $structure;
            $menu->save();
            $this->command?->info('[alerts-scenarios] item_low_volume: inyectado "aros-descontinuados" en el menú activo.');
        } else {
            $this->command?->info('[alerts-scenarios] item_low_volume: ya estaba inyectado (idempotente).');
        }
    }

    /**
     * low_stock: fuerza quantity=0 en el stock de cocina de Pereira para
     * "Carne desmechada" (que tiene min_stock=4). También para "Tocineta
     * ahumada" (min_stock=1.5) para tener 2 alertas y probar el feed.
     */
    private function seedLowStock(): void
    {
        $targets = ['Carne desmechada', 'Tocineta ahumada'];

        $touched = 0;
        foreach ($targets as $name) {
            $ingredients = Ingredient::query()
                ->where('company_nit', self::COMPANY_NIT)
                ->where('name', $name)
                ->get();

            foreach ($ingredients as $ingredient) {
                IngredientStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('min_stock', '>', 0)
                    ->update(['quantity' => 0, 'updated_at' => now()]);
                $touched++;
            }
        }

        $this->command?->info("[alerts-scenarios] low_stock: {$touched} filas de stock con quantity=0 (Carne desmechada + Tocineta).");
    }
}
