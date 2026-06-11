<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo cerrado de verticales del producto (#237).
 *
 * El catálogo nace en la migración `2026_05_24_120000_create_business_types_block.php`.
 * Este seeder es la **fuente operativa** que permite refrescar / agregar entradas
 * SIN migración nueva (ej. cuando se introduce un vertical y sólo cambia el
 * default_capabilities o prep_area_defaults de uno existente).
 *
 * Idempotente vía `updateOrInsert(['slug' => ...], ...)`: no toca filas que
 * ya existen con el mismo slug salvo que cambies algún valor del seed.
 *
 * Después de modificar este seeder, actualizar:
 *   - `constants/BUSINESS_TYPES.md` — tabla canónica y descripciones.
 *   - `bistro/frontend/src/hooks/use-business-types.ts` (sólo si cambia
 *     la shape del payload, no las labels — las labels las consume el frontend).
 *   - `bistro/frontend/src/components/business-type-selector.tsx` si
 *     introducís un `icon_key` nuevo (mapearlo en `ICON_MAP`).
 */
class BusinessTypesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ($this->rows($now) as $row) {
            DB::table('business_types')->updateOrInsert(
                ['slug' => $row['slug']],
                $row,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(Carbon $now): array
    {
        $make = fn (string $slug, string $es, string $en, ?string $icon, array $caps, array $areas, int $order) => [
            'slug' => $slug,
            'label_es' => $es,
            'label_en' => $en,
            'icon_key' => $icon,
            'default_capabilities' => json_encode($caps),
            'prep_area_defaults' => json_encode($areas),
            'display_order' => $order,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Defaults canónicos — alineados con
        // App\Services\BusinessCapabilityService::DEFAULT_FLAGS.
        $allCaps = [
            'pos_orders' => true,
            'counter_orders' => true,
            'tables' => false,
            'kds' => false,
            'prep_areas' => false,
            'delivery' => false,
            'recipes' => false,
            'inventory' => true,
            'reservations' => false,
            'catering_scheduling' => false,
            'multi_menu' => false,
        ];

        return [
            $make(
                'restaurant', 'Restaurante', 'Restaurant', 'utensils',
                array_merge($allCaps, [
                    'tables' => true, 'kds' => true, 'prep_areas' => true,
                    'delivery' => true, 'recipes' => true, 'reservations' => true,
                ]),
                [
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                    ['slug' => 'bar', 'label' => 'Barra', 'color' => '#0ea5e9', 'icon_key' => 'glass-water'],
                ],
                1,
            ),
            $make(
                'bakery', 'Panadería', 'Bakery', 'croissant',
                array_merge($allCaps, [
                    'kds' => true, 'prep_areas' => true, 'recipes' => true, 'delivery' => true,
                ]),
                [
                    ['slug' => 'bakery', 'label' => 'Horno', 'color' => '#f59e0b', 'icon_key' => 'flame'],
                    ['slug' => 'pastry', 'label' => 'Repostería', 'color' => '#ec4899', 'icon_key' => 'cake'],
                ],
                2,
            ),
            $make(
                'cafe', 'Café', 'Coffee Shop', 'coffee',
                array_merge($allCaps, [
                    'tables' => true, 'kds' => true, 'prep_areas' => true, 'recipes' => true,
                ]),
                [
                    ['slug' => 'bar', 'label' => 'Barra', 'color' => '#92400e', 'icon_key' => 'coffee'],
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                ],
                3,
            ),
            $make(
                'fast_food', 'Comidas rápidas', 'Fast Food', 'burger',
                array_merge($allCaps, [
                    'kds' => true, 'prep_areas' => true, 'delivery' => true, 'recipes' => true,
                ]),
                [
                    ['slug' => 'grill', 'label' => 'Plancha', 'color' => '#dc2626', 'icon_key' => 'flame'],
                    ['slug' => 'fryer', 'label' => 'Freidora', 'color' => '#f59e0b', 'icon_key' => 'flame'],
                ],
                4,
            ),
            $make(
                'food_truck', 'Food truck', 'Food Truck', 'truck',
                array_merge($allCaps, [
                    'kds' => true, 'prep_areas' => true, 'delivery' => false, 'recipes' => true,
                ]),
                [
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                ],
                5,
            ),
            $make(
                'ghost_kitchen', 'Dark kitchen', 'Ghost Kitchen', 'chef-hat',
                array_merge($allCaps, [
                    'counter_orders' => false, 'kds' => true, 'prep_areas' => true,
                    'delivery' => true, 'recipes' => true, 'multi_menu' => true,
                ]),
                [
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                ],
                6,
            ),
            $make(
                'bar', 'Bar', 'Bar', 'martini',
                array_merge($allCaps, [
                    'tables' => true, 'kds' => true, 'prep_areas' => true,
                    'recipes' => true, 'reservations' => true,
                ]),
                [
                    ['slug' => 'bar', 'label' => 'Barra', 'color' => '#0ea5e9', 'icon_key' => 'martini'],
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                ],
                7,
            ),
            $make(
                'catering', 'Catering', 'Catering', 'utensils-crossed',
                array_merge($allCaps, [
                    'kds' => false, 'prep_areas' => true, 'delivery' => true,
                    'recipes' => true, 'catering_scheduling' => true,
                ]),
                [
                    ['slug' => 'kitchen', 'label' => 'Cocina', 'color' => '#ef4444', 'icon_key' => 'flame'],
                ],
                8,
            ),
            $make(
                'dark_store', 'Tienda dark', 'Dark Store', 'store',
                array_merge($allCaps, [
                    'kds' => false, 'prep_areas' => false, 'delivery' => true,
                    'recipes' => false,
                ]),
                [],
                9,
            ),
        ];
    }
}
