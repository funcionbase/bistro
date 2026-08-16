<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PrepArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill multi-sede para datos existentes en dev/QA.
 *
 * Por cada empresa:
 *  1) Crea (o reutiliza) una sede `principal` con is_default=true.
 *  2) Asigna esa sede a TODOS los registros existentes (orders, payment_receipts, etc.)
 *     en chunks dentro de DB::transaction.
 *  3) Asigna a todos los miembros de la empresa (CompanyUser) acceso a la sede default.
 *
 * Verificación post-seed: SELECT COUNT(*) WHERE branch_id IS NULL = 0 en cada tabla.
 * El make_branch_id_required (migración posterior) abortará si encuentra NULLs.
 */
class BranchBackfillSeeder extends Seeder
{
    /** Tablas con company_nit que deben heredar branch_id de la sede default de la empresa. */
    private const TABLES_BY_COMPANY = [
        'orders',
        'payment_receipts',
        'cart_sessions',
        'cash_register_sessions',
        'cash_register_expenses',
        'deliveries',
        'business_hours',
        'business_hour_exceptions',
        'printers',
        'restaurant_menus',
        'coupons',
        'coupon_redemptions',
        'ingredients',
        'ingredient_movements',
        'recipes',
        'menu_item_cost_history',
        'suppliers',
        'purchase_orders',
        'purchase_credit_notes',
        'menu_scan_events',
        'menu_scan_daily_rollup',
        'offline_sync_events',
        'chats',
        'contacts',
    ];

    public function run(): void
    {
        $companies = Company::query()->get(['nit']);

        if ($companies->isEmpty()) {
            $this->command?->info('BranchBackfillSeeder: no hay empresas; nada que backfillear.');

            return;
        }

        DB::transaction(function () use ($companies) {
            foreach ($companies as $company) {
                // Sede creada por backfill arranca como `restaurant`
                // (vertical histórico). El owner puede cambiarlo desde UI.
                $branch = Branch::query()->firstOrCreate(
                    ['company_nit' => $company->nit, 'slug' => 'principal'],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => 'Sede principal',
                        'is_default' => true,
                        'business_type_id' => 'restaurant',
                    ],
                );

                // Si la sede ya existía sin business_type_id (fila legada),
                // backfilleala a 'restaurant' para no romper UI/middleware.
                if ($branch->business_type_id === null) {
                    $branch->forceFill(['business_type_id' => 'restaurant'])->save();
                }

                $this->ensurePrepAreas($branch);

                foreach (self::TABLES_BY_COMPANY as $table) {
                    DB::table($table)
                        ->where('company_nit', $company->nit)
                        ->whereNull('branch_id')
                        ->update(['branch_id' => $branch->id]);
                }

                // Tablas child sin company_nit (heredan vía parent).
                DB::table('cart_items')
                    ->whereNull('branch_id')
                    ->whereIn('cart_session_id', DB::table('cart_sessions')->where('company_nit', $company->nit)->pluck('id'))
                    ->update(['branch_id' => $branch->id]);

                DB::table('supplier_ingredients')
                    ->whereNull('branch_id')
                    ->whereIn('supplier_id', DB::table('suppliers')->where('company_nit', $company->nit)->pluck('id'))
                    ->update(['branch_id' => $branch->id]);

                DB::table('purchase_order_items')
                    ->whereNull('branch_id')
                    ->whereIn('purchase_order_id', DB::table('purchase_orders')->where('company_nit', $company->nit)->pluck('id'))
                    ->update(['branch_id' => $branch->id]);

                DB::table('purchase_order_attachments')
                    ->whereNull('branch_id')
                    ->whereIn('purchase_order_id', DB::table('purchase_orders')->where('company_nit', $company->nit)->pluck('id'))
                    ->update(['branch_id' => $branch->id]);

                $userIds = CompanyUser::query()
                    ->where('company_nit', $company->nit)
                    ->pluck('user_id')
                    ->unique();

                foreach ($userIds as $userId) {
                    $exists = DB::table('branch_users')
                        ->where('branch_id', $branch->id)
                        ->where('user_id', $userId)
                        ->exists();

                    if ($exists) {
                        DB::table('branch_users')
                            ->where('branch_id', $branch->id)
                            ->where('user_id', $userId)
                            ->update(['granted_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('branch_users')->insert([
                            'id' => (string) Str::uuid7(),
                            'branch_id' => $branch->id,
                            'user_id' => $userId,
                            'granted_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $this->command?->info("Backfill empresa {$company->nit}: sede {$branch->slug} ({$branch->id})");
            }
        });

        $this->verify();
    }

    private function verify(): void
    {
        $tables = array_merge(self::TABLES_BY_COMPANY, [
            'cart_items',
            'supplier_ingredients',
            'purchase_order_items',
            'purchase_order_attachments',
        ]);

        $errors = [];

        foreach ($tables as $table) {
            $count = DB::table($table)->whereNull('branch_id')->count();

            if ($count > 0) {
                $errors[] = "{$table}: {$count} filas con branch_id NULL";
            }
        }

        if ($errors !== []) {
            Log::error('BranchBackfillSeeder verification failed', ['errors' => $errors]);
            throw new \RuntimeException('BranchBackfillSeeder: '.implode('; ', $errors));
        }

        $this->command?->info('BranchBackfillSeeder verificación OK: 0 filas con branch_id NULL.');
    }

    /**
     * Siembra las prep_areas default del vertical de la sede. Idempotente por
     * (branch_id, slug). Si la sede ya tiene áreas, se respetan y solo se
     * agregan las que falten del vertical canónico.
     */
    private function ensurePrepAreas(Branch $branch): void
    {
        $type = $branch->business_type_id ? BusinessType::find($branch->business_type_id) : null;
        if ($type === null) {
            return;
        }

        $maxOrder = (int) (PrepArea::query()
            ->where('branch_id', $branch->id)
            ->max('display_order') ?? -1);

        foreach ($type->prep_area_defaults ?? [] as $i => $area) {
            PrepArea::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'slug' => $area['slug']],
                [
                    'label' => $area['label'],
                    'color' => $area['color'] ?? '#64748b',
                    'icon_key' => $area['icon_key'] ?? null,
                    'display_order' => $i + max(0, $maxOrder + 1),
                ],
            );
        }
    }
}
