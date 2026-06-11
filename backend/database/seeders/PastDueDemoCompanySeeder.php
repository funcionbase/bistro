<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BillingPlan;
use App\Models\Branch;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PermissionTemplate;
use App\Models\PrepArea;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Empresa demo bloqueada por mora de 6 meses para QA.
 *
 * Crea (idempotente vía updateOrCreate) una segunda empresa "PastDueDemo SAS"
 * con NIT 9009009001 que entró en mora hace 6 meses. Con la gracia default
 * de `BILLING_PAST_DUE_GRACE_MONTHS=3` meses, el escenario está fuera de
 * gracia hace ~3 meses → la empresa quedó `suspended` y el middleware
 * `EnsureCompanyNotBlocked` debe bloquear API y web (banner rojo, sidebar
 * reducido a Dashboard + Mi empresa, redirect 302 a `/dashboard`).
 *
 * Datos:
 *  - status = 'suspended'
 *  - past_due_started_at = today - 180 días (6 meses)
 *  - expected_block_at = past_due_started_at + 3 meses (today - 90 días)
 *  - payment_blocked_at = expected_block_at + 1 día (cuando el cron lo
 *    detectó tras vencer la gracia)
 *  - 6 invoices overdue (mes -6 a mes -1) sin pago. Total adeudado ≈
 *    6 × `plan.pro.price` — visible en SuspendedBanner.
 *  - subscription Pro activa con starts_at ~7 meses atrás (un mes antes
 *    de la primera factura vencida, modelando suscripción legacy).
 *  - cristianmarint@gmail.com asociado como Propietario (rol del template
 *    `owner` con todos los permisos del sistema). Se asume que el user ya
 *    existe (lo crea RestauranteFlexySeeder antes en el orden de QaSeeder).
 *
 * NO se mete a `RestauranteFlexySeeder` para mantener separadas las dos
 * historias (SuperPapas operando OK vs PastDueDemo suspendida). QA puede
 * conmutar entre ambas vía switch-company.
 *
 * Si se quiere mover el escenario a `past_due` (con gracia activa) en lugar
 * de `suspended`, ajustar `$monthsOverdue` a un valor menor que el grace
 * configurado (default 3 meses) — habría que cambiar `status`,
 * `payment_blocked_at` y la cantidad de invoices generadas en consecuencia.
 */
class PastDueDemoCompanySeeder extends Seeder
{
    private const COMPANY_NIT = '9009009001';

    /**
     * Meses de mora a simular. Se generan invoices overdue para cada uno de
     * los últimos N meses, y past_due_started_at = today - N×30 días.
     * Si N > BILLING_PAST_DUE_GRACE_MONTHS la empresa termina `suspended`;
     * si es menor o igual, se queda `past_due` con gracia activa.
     */
    private const MONTHS_OVERDUE = 6;

    public function run(): void
    {
        $bankId = Bank::query()->where('code', '007')->value('id')
            ?? Bank::query()->value('id');

        $today = Carbon::now()->startOfDay();
        $graceMonths = (int) config('billing.past_due_grace_months', 3);
        $pastDueStartedAt = $today->copy()->subMonthsNoOverflow(self::MONTHS_OVERDUE);
        $expectedBlockAt = $pastDueStartedAt->copy()->addMonthsNoOverflow($graceMonths);
        $isSuspended = $graceMonths < self::MONTHS_OVERDUE;

        // payment_blocked_at simula el día en que el cron disparó el bloqueo
        // tras vencer la gracia. En past_due puro (sin haber pasado gracia)
        // queda null.
        $paymentBlockedAt = $isSuspended
            ? $expectedBlockAt->copy()->addDay()
            : null;

        $company = Company::updateOrCreate(
            ['nit' => self::COMPANY_NIT],
            [
                'commercial_name' => 'PastDueDemo',
                'legal_name' => 'PastDueDemo SAS',
                'bank_id' => $bankId,
                'account_number' => '20098765432',
                'account_type' => 'corriente',
                'breb_key' => 'pastduedemo-breb-demo',
                'qr_code_path' => null,
                'logo_path' => null,
                'status' => $isSuspended ? 'suspended' : 'past_due',
                'tax_regime' => 'simple',
                'default_tax_rate' => 0,
                'default_tax_label' => 'Sin IVA',
                'tax_included_in_price' => true,
                'past_due_started_at' => $pastDueStartedAt,
                'expected_block_at' => $expectedBlockAt->toDateString(),
                'payment_blocked_at' => $paymentBlockedAt,
                'last_paid_at' => null,
            ]
        );

        $this->call(BillingPlanSeeder::class);
        // #246 consolidó el catálogo a un único plan activo; ya no existe 'pro'.
        $plan = BillingPlan::where('slug', config('billing.default_plan_slug', 'default'))->firstOrFail();

        // Suscripción anterior al primer mes vencido para que el rango de
        // periodos facturables incluya todas las invoices del escenario.
        $subStartsAt = $today->copy()->subMonthsNoOverflow(self::MONTHS_OVERDUE + 1)->startOfMonth();

        $subscription = Subscription::updateOrCreate(
            ['company_nit' => $company->nit, 'status' => 'active'],
            [
                'billing_plan_id' => $plan->id,
                'starts_at' => $subStartsAt->toDateString(),
                'ends_at' => null,
            ]
        );

        // N invoices vencidas (mes -N hasta mes -1), todas en status 'overdue'.
        // El cron `billing:mark-overdue-invoices` deja este mismo estado tras
        // marcar y derivar el status de la empresa.
        $invoiceMonths = range(self::MONTHS_OVERDUE, 1);

        foreach ($invoiceMonths as $offset) {
            $month = $today->copy()->subMonths($offset);
            $periodFrom = $month->copy()->startOfMonth()->toDateString();
            $periodTo = $month->copy()->endOfMonth()->toDateString();
            $dueDate = $month->copy()->day(15)->toDateString();
            $daysInMonth = $month->daysInMonth;

            $existing = Invoice::where('subscription_id', $subscription->id)
                ->where('period_from', $periodFrom)
                ->where('period_to', $periodTo)
                ->where('status', '!=', 'voided')
                ->first();

            if ($existing) {
                continue;
            }

            $invoice = Invoice::create([
                'company_nit' => $company->nit,
                'subscription_id' => $subscription->id,
                'type' => 'monthly',
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'days_billed' => $daysInMonth,
                'base_amount' => $plan->price,
                'discount_percent' => null,
                'discount_amount' => null,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'due_date' => $dueDate,
                'status' => 'overdue',
                'generated_at' => $month->copy()->day(20)->setTime(3, 0),
            ]);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'description' => "Suscripción Plan {$plan->name} — {$month->translatedFormat('F Y')}",
                'quantity' => 1,
                'unit_price' => $plan->price,
                'subtotal' => $plan->price,
            ]);
        }

        // Multi-sede (#192): toda empresa operativa debe tener al menos una
        // sede. Aunque PastDueDemo está bloqueada y no opera, sin sede el
        // owner que se loguea ve `MissingBranchBanner` y la UI no puede
        // siquiera renderizar el dashboard híbrido del SuspendedBanner.
        // Creamos una sede default vacía (sin menú, inventario, órdenes)
        // — la empresa está suspended de todos modos.
        // #237 — la empresa demo es de pastry/desserts → vertical `bakery`.
        // Aunque está suspended y no opera, el vertical define las áreas
        // (horno + repostería) y módulos que mostraría la UI.
        $branch = Branch::firstOrCreate(
            ['company_nit' => $company->nit, 'slug' => 'cali'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'PastDueDemo Cali',
                'address' => 'Cl 5 #4-21, San Antonio',
                'city' => 'Cali',
                'is_default' => true,
                'business_type_id' => 'bakery',
            ],
        );

        // El seeder es la fuente de verdad: si el backfill puso 'restaurant'
        // pero el demo dice 'bakery', forzamos al valor del seeder.
        if ($branch->business_type_id !== 'bakery') {
            $branch->forceFill(['business_type_id' => 'bakery'])->save();
        }

        $this->ensurePrepAreas($branch);
        $this->attachOwner($company->nit, $branch->id);
    }

    /**
     * Asocia cristianmarint@gmail.com como Propietario de la empresa demo.
     * Crea el rol del template `owner` (todos los permisos del sistema) e
     * inserta la membership como `active`. También garantiza el pivot
     * `branch_users` para la sede default — owners hacen bypass del pivot,
     * pero la fila se crea para que `branchesAvailable()` lo devuelva
     * correcto y el switcher tenga al menos una sede que mostrar.
     * Idempotente vía updateOrCreate.
     */
    private function attachOwner(string $companyNit, string $branchId): void
    {
        $user = User::query()->where('email', 'cristianmarint@gmail.com')->first();

        if ($user === null) {
            $this->command?->warn(
                'PastDueDemoCompanySeeder: no se encontró cristianmarint@gmail.com. '.
                'Saltando asociación de owner. (¿Se ejecutó RestauranteFlexySeeder antes?)'
            );

            return;
        }

        $roleName = config('roles.role_names.owner', 'Propietario');

        $role = CompanyRole::updateOrCreate(
            ['company_nit' => $companyNit, 'name' => $roleName],
            [
                'description' => "Rol {$roleName} para PastDueDemo",
                'is_system' => true,
                'color' => '#C0FD79',
            ]
        );

        PermissionTemplate::query()
            ->where('role_type', 'owner')
            ->each(function (PermissionTemplate $template) use ($role): void {
                CompanyRolePermission::updateOrCreate(
                    ['company_role_id' => $role->id, 'feature_id' => $template->feature_id],
                    [
                        'can_create' => $template->can_create,
                        'can_read' => $template->can_read,
                        'can_update' => $template->can_update,
                        'can_delete' => $template->can_delete,
                    ]
                );
            });

        CompanyUser::updateOrCreate(
            ['company_nit' => $companyNit, 'user_id' => $user->id],
            ['company_role_id' => $role->id, 'status' => 'active']
        );

        $existsBranchUser = DB::table('branch_users')
            ->where('branch_id', $branchId)
            ->where('user_id', $user->id)
            ->exists();

        if ($existsBranchUser) {
            DB::table('branch_users')
                ->where('branch_id', $branchId)
                ->where('user_id', $user->id)
                ->update([
                    'granted_by_user_id' => $user->id,
                    'granted_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('branch_users')->insert([
                'id' => (string) Str::uuid7(),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'granted_by_user_id' => $user->id,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Siembra las prep_areas del vertical de la sede demo. Idempotente por
     * (branch_id, slug). #237.
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
