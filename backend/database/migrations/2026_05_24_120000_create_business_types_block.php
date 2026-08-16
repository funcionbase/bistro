<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Generalización del modelo de negocio.
 *
 * Crea el bloque de catálogos de vertical:
 *   - business_types: catálogo cerrado de verticales (restaurant, bakery, cafe,
 *     fast_food, food_truck, ghost_kitchen, bar, catering, dark_store) con sus
 *     capacidades por defecto en JSON.
 *   - prep_areas: áreas de preparación dinámicas por sede (ej. cocina, barra,
 *     panadería, repostería). Las pantallas KDS se filtran por estas áreas.
 *
 * Añade a branches:
 *   - business_type_id: vertical de la sede (nullable inicialmente para no romper
 *     instalaciones existentes). El frontend onboarding lo pedirá obligatorio.
 *   - capabilities_override: JSON {flag: bool} que sobreescribe puntualmente las
 *     default_capabilities del vertical. Ej. un food_truck que sí quiere tomar
 *     mesas porque opera fijo en un parque → {"tables": true}.
 *
 * Backfill: toda branch existente queda como 'restaurant'. Es el único vertical
 * de PDN actual y mantiene compatibilidad total. Para cada branch backfilled se
 * siembran las prep_areas default del vertical 'restaurant' (kitchen + bar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->string('slug', 32)->primary();
            $table->string('label_es', 64);
            $table->string('label_en', 64);
            $table->string('icon_key', 32)->nullable();
            $table->json('default_capabilities');
            $table->json('prep_area_defaults');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('display_order');
        });

        Schema::create('prep_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('slug', 48);
            $table->string('label', 64);
            $table->string('color', 16)->default('#64748b');
            $table->string('icon_key', 32)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'slug']);
            $table->index(['branch_id', 'archived_at']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('business_type_id', 32)->nullable()->after('city');
            $table->json('capabilities_override')->nullable()->after('business_type_id');

            $table->foreign('business_type_id')
                ->references('slug')->on('business_types')
                ->nullOnDelete();
            $table->index('business_type_id');
        });

        // Semilla del catálogo (idempotente para que las migraciones sean re-runnable
        // en entornos limpios sin colisión con el seeder).
        $now = now();
        $rows = $this->businessTypeSeed($now);
        foreach ($rows as $row) {
            DB::table('business_types')->updateOrInsert(['slug' => $row['slug']], $row);
        }

        // Backfill: branches sin vertical pasan a 'restaurant' (vertical histórico).
        DB::table('branches')->whereNull('business_type_id')->update(['business_type_id' => 'restaurant']);

        // Crear prep_areas default por sede para cada branch existente.
        $restaurantDefaults = collect($rows)->firstWhere('slug', 'restaurant');
        $defaults = json_decode($restaurantDefaults['prep_area_defaults'], true);
        $branches = DB::table('branches')->pluck('id');
        foreach ($branches as $branchId) {
            foreach ($defaults as $i => $area) {
                $exists = DB::table('prep_areas')
                    ->where('branch_id', $branchId)
                    ->where('slug', $area['slug'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('prep_areas')->insert([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'slug' => $area['slug'],
                    'label' => $area['label'],
                    'color' => $area['color'] ?? '#64748b',
                    'icon_key' => $area['icon_key'] ?? null,
                    'display_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['business_type_id']);
            $table->dropIndex(['business_type_id']);
            $table->dropColumn(['business_type_id', 'capabilities_override']);
        });

        Schema::dropIfExists('prep_areas');
        Schema::dropIfExists('business_types');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function businessTypeSeed(Carbon $now): array
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
};
