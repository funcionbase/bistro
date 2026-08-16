<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BillingPlan;
use App\Models\Branch;
use App\Models\BusinessHour;
use App\Models\BusinessHourException;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\CompanyUser;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Delivery;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\KdsStation;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PermissionTemplate;
use App\Models\PrepArea;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Recipe;
use App\Models\RestaurantMenu;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\SupplierIngredient;
use App\Models\Table;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo dataset operativo para SuperPapas — comidas rápidas y salchipapas.
 *
 * Modelo multi-sede (#117): la empresa opera 2 sedes con menús diferentes:
 *  - Pereira: foco en salchipapas tradicionales (10 platos).
 *  - Cartago: foco en hamburguesas, perros y combos (10 platos).
 *
 * Equipo (4 personas, conservados):
 *  - Owner: cristianmarint@gmail.com (Cristian Marín) → ambas sedes
 *  - Admin: funcionbaseco@gmail.com (Carolina Mejía) → ambas sedes
 *  - Cocina: flexyconsultora@gmail.com (Sebastián Ramírez) → solo Pereira
 *  - Domiciliario: cristianmarintt@gmail.com → solo Cartago
 *
 * Régimen tributario: Simple (sin IVA) — coherente con CLAUDE.md.
 *
 * Compromisos contables (CLAUDE.md):
 *  - payment_receipts.payment_method ∈ {cash, card, transfer, refund}.
 *  - amount con signo: cobros positivos, devoluciones negativas. decimal:2.
 *  - Receipts inmutables. Cupones no se reasignan al canjear (append-only).
 *  - Estados de orden: fuente de verdad config/orders.php.
 *
 * Volumen histórico: 60 días por sede, distribución realista por día de semana.
 */
class RestauranteFlexySeeder extends Seeder
{
    private const COMPANY_NIT = '1';

    private const CUSTOMER_POOL_SIZE = 60;

    private const CUSTOMER_PHONE_BASE = 3001000000;

    private const HISTORY_DAYS = 60;

    /** @var array<string, array<string, Warehouse>> branchKey → [type => Warehouse] */
    private array $warehousesByBranch = [];

    public function run(): void
    {
        $this->call([
            BankSeeder::class,
            FeatureSeeder::class,
            PermissionTemplateSeeder::class,
        ]);

        DB::transaction(function (): void {
            $company = $this->seedCompany();
            $branches = $this->ensureBranches($company->nit);
            $users = $this->seedUsers();
            $roles = $this->seedRoles($company->nit);

            $this->seedMemberships($company->nit, $users, $roles);
            $this->seedBranchMemberships($branches, $users);
            $this->seedInvitations($company->nit, $roles);
            $this->clearOperationalData($company->nit);

            $coupons = $this->seedCoupons($company->nit, $branches['pereira'], $users['admin']);

            $menus = [];
            foreach ($branches as $key => $branch) {
                // Sede 'armenia' queda intencionalmente vacía (#192): valida
                // empty states y aislamiento por sede en QA. No se siembran
                // menú, bodegas, inventario, recetas, ni órdenes históricas.
                if ($key === 'armenia') {
                    continue;
                }

                BelongsToBranch::setSeederBranch($branch->id);
                try {
                    $this->seedBusinessHours($company->nit);
                    $this->seedBusinessHourExceptions($company->nit);
                    $this->warehousesByBranch[$key] = $this->ensureWarehouses($company->nit, $branch);
                    $menus[$key] = $this->seedMenuForBranch($company->nit, $key);
                    $inventory = $this->seedInventoryAndPurchases($company->nit, $branch, $key, $users['admin']);
                    $this->seedRecipes($company->nit, $branch, $key, $menus[$key], $inventory['ingredients']);
                    $this->seedHistoricalOrdersForBranch($company->nit, $branch, $key, $users, $coupons);
                    $this->seedSaleConsumption($company->nit, $branch, $key, $inventory['ingredients']);
                    $this->recalculateIngredientStocks($inventory['ingredients'], $key);
                } finally {
                    BelongsToBranch::setSeederBranch(null);
                }
            }

            // Genera snapshots históricos del valor del inventario por bodega
            // para los últimos 60 días, reconstruyendo desde movements.
            $this->generateHistoricalWarehouseSnapshots($company->nit);

            BelongsToBranch::setSeederBranch($branches['pereira']->id);
            try {
                $this->seedLiveOrdersAndChats($company->nit, $users);
            } finally {
                BelongsToBranch::setSeederBranch(null);
            }

            $this->syncCouponUsageStatistics($coupons);
            $this->seedBilling($company->nit, $users['owner']);
        });
    }

    private function seedCompany(): Company
    {
        $bankId = Bank::query()->where('code', '007')->value('id')
            ?? Bank::query()->value('id');

        return Company::updateOrCreate(
            ['nit' => self::COMPANY_NIT],
            [
                'commercial_name' => 'SuperPapas',
                'legal_name' => 'SuperPapas SAS',
                'bank_id' => $bankId,
                'account_number' => '20012345678',
                'account_type' => 'corriente',
                'breb_key' => 'superpapas-breb-demo',
                'qr_code_path' => null,
                'logo_path' => null,
                'status' => 'active',
                'tax_regime' => 'simple',
                'default_tax_rate' => 0,
                'default_tax_label' => 'Sin IVA',
                'tax_included_in_price' => true,
            ]
        );
    }

    /**
     * Sedes de SuperPapas. `armenia` queda intencionalmente vacía (sin menú,
     * inventario ni órdenes históricas) para exponer empty states en la UI
     * y servir de smoke-test del aislamiento por sede (#192). El admin tiene
     * acceso a las 3 → recibe `metrics.view_all_branches` automáticamente.
     *
     * @return array{pereira: Branch, cartago: Branch, armenia: Branch}
     */
    private function ensureBranches(string $companyNit): array
    {
        // #237 — Verticales mixtos en el dataset demo: la misma empresa opera
        // sedes de distinto tipo para validar end-to-end que una compañía puede
        // tener sucursales con configuración independiente.
        //   - Pereira: `fast_food` (sede emblema SuperPapas, plancha + freidora)
        //   - Cartago: `restaurant` (sede sit-down con mesas + cocina + barra)
        //   - Armenia: `food_truck` (sede vacía operada como food truck móvil)
        $pereira = $this->ensureBranch($companyNit, 'pereira', [
            'name' => 'SuperPapas Pereira',
            'address' => 'Cra 7 #20-45, Centro',
            'city' => 'Pereira',
            'is_default' => true,
            'business_type_id' => 'fast_food',
        ]);

        $cartago = $this->ensureBranch($companyNit, 'cartago', [
            'name' => 'SuperPapas Cartago',
            'address' => 'Cl 11 #4-30, Centro',
            'city' => 'Cartago',
            'is_default' => false,
            'business_type_id' => 'restaurant',
        ]);

        $armenia = $this->ensureBranch($companyNit, 'armenia', [
            'name' => 'SuperPapas Armenia',
            'address' => 'Cl 19 #14-50, Centro',
            'city' => 'Armenia',
            'is_default' => false,
            'business_type_id' => 'food_truck',
        ]);

        // KDS (#115): cada sede del dataset demo arranca con sus 4 estaciones
        // canónicas (caliente/fría/barra/fritos). Idempotente: firstOrCreate
        // por (company_nit, branch_id, slug).
        foreach ([$pereira, $cartago, $armenia] as $branch) {
            KdsStation::seedDefaultsForBranch($companyNit, $branch->id);
        }

        // Mesas de Cartago (restaurant sit-down). Capacidades: 1-4 → 2 pax,
        // 5-8 → 4 pax, 9-10 → 6 pax. Números coinciden con los order seeds
        // que usan table_number 1-10 vía ($sequence % 10 + 1).
        $cartagoTableCapacities = [
            '1' => 2, '2' => 2, '3' => 2, '4' => 2,
            '5' => 4, '6' => 4, '7' => 4, '8' => 4,
            '9' => 6, '10' => 6,
        ];
        foreach ($cartagoTableCapacities as $number => $capacity) {
            Table::firstOrCreate(
                ['company_nit' => $companyNit, 'branch_id' => $cartago->id, 'number' => $number],
                ['capacity' => $capacity, 'status' => 'available'],
            );
        }

        return ['pereira' => $pereira, 'cartago' => $cartago, 'armenia' => $armenia];
    }

    /**
     * Garantiza la sede + vertical + prep_areas del vertical. Idempotente: si
     * la sede ya existe pero quedó con otro vertical (ej. backfill histórico
     * a 'restaurant'), se actualiza al vertical canónico del seeder demo y se
     * agregan las prep_areas faltantes.
     *
     * @param  array{name:string,address?:?string,city?:?string,is_default:bool,business_type_id:string}  $attrs
     */
    private function ensureBranch(string $companyNit, string $slug, array $attrs): Branch
    {
        $branch = Branch::query()->firstOrCreate(
            ['company_nit' => $companyNit, 'slug' => $slug],
            [
                'id' => (string) Str::uuid7(),
                'name' => $attrs['name'],
                'address' => $attrs['address'] ?? null,
                'city' => $attrs['city'] ?? null,
                'is_default' => (bool) $attrs['is_default'],
                'business_type_id' => $attrs['business_type_id'],
            ],
        );

        if ($branch->business_type_id !== $attrs['business_type_id']) {
            $branch->forceFill(['business_type_id' => $attrs['business_type_id']])->save();
        }

        $this->ensurePrepAreas($branch, $attrs['business_type_id']);

        return $branch;
    }

    /**
     * Siembra las prep_areas del vertical para la sede. Idempotente por
     * (branch_id, slug). Se invoca tras `ensureBranch` y tras un eventual
     * cambio de vertical.
     */
    private function ensurePrepAreas(Branch $branch, string $businessTypeSlug): void
    {
        $type = BusinessType::find($businessTypeSlug);
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

    /**
     * @return array{owner: User, admin: User, kitchen: User, courier: User}
     */
    private function seedUsers(): array
    {
        return [
            'owner' => $this->upsertUser(
                email: 'cristianmarint@gmail.com',
                firstName: 'Cristian',
                lastName: 'Marín',
                googleId: '108550001100000000001',
                cedula: '1010100001'
            ),
            'admin' => $this->upsertUser(
                email: 'funcionbaseco@gmail.com',
                firstName: 'Carolina',
                lastName: 'Mejía',
                googleId: '108550001100000000002',
                cedula: '1010100002'
            ),
            'kitchen' => $this->upsertUser(
                email: 'flexyconsultora@gmail.com',
                firstName: 'Sebastián',
                lastName: 'Ramírez',
                googleId: '108550001100000000003',
                cedula: '1010100003'
            ),
            'courier' => $this->upsertUser(
                email: 'cristianmarintt@gmail.com',
                firstName: 'Cristian',
                lastName: 'Marín',
                googleId: '108550001100000000004',
                cedula: '1010100004'
            ),
        ];
    }

    /**
     * @return array{
     *     owner: CompanyRole,
     *     admin: CompanyRole,
     *     employee: CompanyRole,
     *     courier: CompanyRole,
     *     kitchen: CompanyRole
     * }
     */
    private function seedRoles(string $companyNit): array
    {
        // Colores de los roles del sistema leídos desde config/roles.php.
        // Se evita hardcodear para no introducir drift con `config('roles.role_colors')`,
        // que es la fuente de verdad declarada en bistro/backend/constants/ROLES_SYSTEM.md.
        $roles = [
            'owner' => $this->seedSystemRoleFromTemplate($companyNit, 'owner', config('roles.role_colors.owner')),
            'admin' => $this->seedSystemRoleFromTemplate($companyNit, 'admin', config('roles.role_colors.admin')),
            'employee' => $this->seedSystemRoleFromTemplate($companyNit, 'employee', config('roles.role_colors.employee')),
        ];

        $roles['courier'] = CompanyRole::updateOrCreate(
            ['company_nit' => $companyNit, 'name' => 'Domiciliario'],
            [
                'description' => 'Gestiona entregas y consulta órdenes asignadas.',
                'is_system' => false,
                'color' => '#0EA5E9',
            ]
        );

        $roles['kitchen'] = CompanyRole::updateOrCreate(
            ['company_nit' => $companyNit, 'name' => 'Cocina'],
            [
                'description' => 'Acceso al tablero de órdenes (cambia estados y consulta detalle); '
                    .'consulta de menú y horarios en solo lectura.',
                'is_system' => false,
                'color' => '#EF4444',
            ]
        );

        // El rol Domiciliario es "courier-only" — solo necesita
        // ver/gestionar sus propias entregas. NO recibe `orders.read`,
        // `menu.read`, `hours.read`, `chats.read` ni `deliveries.create`
        // porque no opera el menú, mesas, tablero ni reportes. Los datos
        // de la orden (cliente, dirección, total) llegan eagerly desde
        // el endpoint `/deliveries/mine` sin necesidad de `orders.read`.
        //
        // Esta selección activa además el "courier mode" del sidebar y
        // el redirect post-login a `/my-deliveries` (ver
        // `App\Support\PostLoginRedirect`).
        $this->syncRolePermissions(
            $roles['courier'],
            readableSlugs: ['deliveries.read', 'deliveries.self_assign'],
            updatableSlugs: ['deliveries.update']
        );

        $this->syncRolePermissions(
            $roles['kitchen'],
            readableSlugs: ['orders.read', 'menu.read', 'hours.read'],
            updatableSlugs: ['orders.update']
        );

        return $roles;
    }

    /**
     * @param  array{owner: User, admin: User, kitchen: User, courier: User}  $users
     * @param  array{owner: CompanyRole, admin: CompanyRole, employee: CompanyRole, courier: CompanyRole, kitchen: CompanyRole}  $roles
     */
    private function seedMemberships(string $companyNit, array $users, array $roles): void
    {
        $map = [
            'owner' => $roles['owner']->id,
            'admin' => $roles['admin']->id,
            'kitchen' => $roles['kitchen']->id,
            'courier' => $roles['courier']->id,
        ];

        foreach ($map as $userKey => $roleId) {
            CompanyUser::updateOrCreate(
                [
                    'company_nit' => $companyNit,
                    'user_id' => $users[$userKey]->id,
                ],
                [
                    'company_role_id' => $roleId,
                    'status' => 'active',
                ]
            );
        }
    }

    /**
     * Asigna acceso por sede vía pivot branch_users:
     *  - owner + admin: las 3 sedes (admin con cobertura total → recibe
     *    metrics.view_all_branches automáticamente vía
     *    FeaturePermissionService::userCanViewConsolidated #192).
     *  - kitchen: solo Pereira.
     *  - courier: solo Cartago.
     *  - armenia: sin asignaciones operativas — sede vacía para validar
     *    empty states y aislamiento por sede (#192).
     *
     * Owners hacen bypass del pivot, pero las filas se crean igual para
     * consistencia y para que branchesAvailable() devuelva la lista correcta.
     *
     * @param  array{pereira: Branch, cartago: Branch, armenia: Branch}  $branches
     * @param  array{owner: User, admin: User, kitchen: User, courier: User}  $users
     */
    private function seedBranchMemberships(array $branches, array $users): void
    {
        $assignments = [
            'owner' => ['pereira', 'cartago', 'armenia'],
            'admin' => ['pereira', 'cartago', 'armenia'],
            'kitchen' => ['pereira'],
            'courier' => ['cartago'],
        ];

        foreach ($assignments as $userKey => $branchKeys) {
            foreach ($branchKeys as $branchKey) {
                $branchId = $branches[$branchKey]->id;
                $userId = $users[$userKey]->id;

                $exists = DB::table('branch_users')
                    ->where('branch_id', $branchId)
                    ->where('user_id', $userId)
                    ->exists();

                if ($exists) {
                    DB::table('branch_users')
                        ->where('branch_id', $branchId)
                        ->where('user_id', $userId)
                        ->update(['granted_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('branch_users')->insert([
                        'id' => (string) Str::uuid7(),
                        'branch_id' => $branchId,
                        'user_id' => $userId,
                        'granted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array{owner: CompanyRole, admin: CompanyRole, employee: CompanyRole, courier: CompanyRole, kitchen: CompanyRole}  $roles
     */
    private function seedInvitations(string $companyNit, array $roles): void
    {
        $samples = [
            ['email' => 'mesero1@superpapas.demo', 'role_id' => $roles['employee']->id, 'status' => 'pending', 'days_offset' => -2],
            ['email' => 'cocina-extra@superpapas.demo', 'role_id' => $roles['kitchen']->id, 'status' => 'expired', 'days_offset' => -10],
        ];

        foreach ($samples as $invite) {
            CompanyInvitation::updateOrCreate(
                ['company_nit' => $companyNit, 'email' => $invite['email']],
                [
                    'company_role_id' => $invite['role_id'],
                    'token' => bin2hex(random_bytes(16)),
                    'expires_at' => Carbon::now()->addDays(7),
                    'status' => $invite['status'],
                    'created_at' => Carbon::now()->addDays($invite['days_offset']),
                ]
            );
        }
    }

    /**
     * Salchipapería: cierra los lunes (descanso típico), abre martes-jueves
     * desde las 4 PM, viernes hasta más tarde, sábado y domingo abren temprano.
     */
    private function seedBusinessHours(string $companyNit): void
    {
        $schedule = [
            0 => ['14:00:00', '22:00:00', true],
            1 => ['00:00:00', '00:00:00', false],
            2 => ['16:00:00', '22:30:00', true],
            3 => ['16:00:00', '22:30:00', true],
            4 => ['16:00:00', '22:30:00', true],
            5 => ['16:00:00', '23:30:00', true],
            6 => ['14:00:00', '23:30:00', true],
        ];

        foreach ($schedule as $dayOfWeek => [$openTime, $closeTime, $isEnabled]) {
            BusinessHour::query()->firstOrCreate(
                [
                    'company_nit' => $companyNit,
                    'branch_id' => $this->currentBranchId(),
                    'day_of_week' => $dayOfWeek,
                ],
                [
                    'open_time' => $openTime,
                    'close_time' => $closeTime,
                    'is_enabled' => $isEnabled,
                ]
            );
        }
    }

    private function seedBusinessHourExceptions(string $companyNit): void
    {
        $maintenanceDate = now()->startOfDay()->addDays(10)->toDateString();

        BusinessHourException::query()->firstOrCreate(
            [
                'company_nit' => $companyNit,
                'branch_id' => $this->currentBranchId(),
                'exception_date' => $maintenanceDate,
            ],
            [
                'reason' => 'Mantenimiento programado',
                'is_open' => false,
                'open_time' => null,
                'close_time' => null,
            ]
        );
    }

    /**
     * Menú especializado por sede. Cada sede tiene su carta corta (10 platos)
     * para diferenciar la oferta:
     *  - pereira: foco en salchipapas tradicionales y bebidas.
     *  - cartago: foco en hamburguesas, perros y combos.
     */
    private function seedMenuForBranch(string $companyNit, string $branchKey): RestaurantMenu
    {
        $structure = [
            'version' => 3,
            'categories' => $branchKey === 'pereira'
                ? $this->pereiraCategories()
                : $this->cartagoCategories(),
        ];

        $menuName = $branchKey === 'pereira'
            ? 'Carta SuperPapas Pereira'
            : 'Carta SuperPapas Cartago';

        return RestaurantMenu::updateOrCreate(
            [
                'company_nit' => $companyNit,
                'branch_id' => $this->currentBranchId(),
                'name' => $menuName,
            ],
            [
                'description' => $branchKey === 'pereira'
                    ? 'Salchipapas y combos clásicos del Eje Cafetero.'
                    : 'Hamburguesas, perros y picadas para compartir en Cartago.',
                'status' => 'active',
                'active_days' => null,
                'structure' => $structure,
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pereiraCategories(): array
    {
        return [
            [
                'id' => 'salchipapas',
                'name' => 'Salchipapas',
                'description' => 'La especialidad de la casa.',
                'order' => 1,
                'items' => [
                    ['id' => 'salchipapa-clasica', 'name' => 'Salchipapa clásica', 'description' => 'Papa francesa, salchicha, ripio y nuestras 3 salsas.', 'price' => 12000, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'salchipapa-especial', 'name' => 'Salchipapa especial', 'description' => 'Papa, salchicha, carne desmechada y queso fundido.', 'price' => 16000, 'image_path' => null, 'available' => true, 'order' => 2],
                    ['id' => 'salchipapa-superpapa', 'name' => 'Salchipapa SuperPapa', 'description' => 'Mix completo de proteínas, queso, maíz y salsas.', 'price' => 24900, 'image_path' => null, 'available' => true, 'order' => 3],
                    ['id' => 'mega-salchipapa', 'name' => 'Mega salchipapa para 2', 'description' => 'Bandeja XL para compartir.', 'price' => 32000, 'image_path' => null, 'available' => true, 'order' => 4],
                ],
            ],
            [
                'id' => 'perros',
                'name' => 'Perros calientes',
                'description' => 'El perro caliente colombiano.',
                'order' => 2,
                'items' => [
                    ['id' => 'perro-sencillo', 'name' => 'Perro sencillo', 'description' => 'Salchicha, papitas, queso y salsas.', 'price' => 10500, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'perro-especial', 'name' => 'Perro especial', 'description' => 'Salchicha, queso, tocineta y papitas.', 'price' => 14500, 'image_path' => null, 'available' => true, 'order' => 2],
                ],
            ],
            [
                'id' => 'acompanamientos',
                'name' => 'Acompañamientos',
                'description' => 'Para complementar.',
                'order' => 3,
                'items' => [
                    ['id' => 'papas-francesas', 'name' => 'Papas a la francesa', 'description' => 'Porción generosa con sal y salsas.', 'price' => 8500, 'image_path' => null, 'available' => true, 'order' => 1],
                ],
            ],
            [
                'id' => 'bebidas',
                'name' => 'Bebidas',
                'description' => 'Para acompañar.',
                'order' => 4,
                'items' => [
                    ['id' => 'gaseosa-personal', 'name' => 'Gaseosa personal', 'description' => 'Coca-Cola, Sprite o Manzana 400 ml.', 'price' => 4500, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'limonada-natural', 'name' => 'Limonada natural', 'description' => 'En agua o leche, bien fría.', 'price' => 6500, 'image_path' => null, 'available' => true, 'order' => 2],
                    ['id' => 'cerveza-nacional', 'name' => 'Cerveza nacional', 'description' => 'Aguila, Club o Poker.', 'price' => 7000, 'image_path' => null, 'available' => true, 'order' => 3],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cartagoCategories(): array
    {
        return [
            [
                'id' => 'hamburguesas',
                'name' => 'Hamburguesas',
                'description' => 'En pan brioche con papas a la francesa.',
                'order' => 1,
                'items' => [
                    ['id' => 'hamburguesa-clasica', 'name' => 'Hamburguesa clásica', 'description' => 'Carne 150g, queso, lechuga, tomate. Con papas.', 'price' => 14900, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'hamburguesa-bbq', 'name' => 'Hamburguesa BBQ', 'description' => 'Carne, tocineta, cebolla caramelizada y BBQ.', 'price' => 18500, 'image_path' => null, 'available' => true, 'order' => 2],
                    ['id' => 'hamburguesa-pollo', 'name' => 'Hamburguesa de pollo', 'description' => 'Pechuga apanada con queso y papas.', 'price' => 16900, 'image_path' => null, 'available' => true, 'order' => 3],
                ],
            ],
            [
                'id' => 'perros',
                'name' => 'Perros',
                'description' => 'Para compartir.',
                'order' => 2,
                'items' => [
                    ['id' => 'perro-ranchero', 'name' => 'Perro ranchero', 'description' => 'Salchicha, carne desmechada, chili y guacamole.', 'price' => 15900, 'image_path' => null, 'available' => true, 'order' => 1],
                ],
            ],
            [
                'id' => 'picadas',
                'name' => 'Picadas',
                'description' => 'Para 2 o más personas.',
                'order' => 3,
                'items' => [
                    ['id' => 'picada-personal', 'name' => 'Picada personal', 'description' => 'Chicharrón, chorizo, papa criolla y arepa.', 'price' => 22000, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'picada-para-2', 'name' => 'Picada para 2', 'description' => 'Mix de proteínas con yuca y arepas.', 'price' => 39000, 'image_path' => null, 'available' => true, 'order' => 2],
                ],
            ],
            [
                'id' => 'acompanamientos',
                'name' => 'Acompañamientos',
                'description' => 'Para complementar.',
                'order' => 4,
                'items' => [
                    ['id' => 'aros-cebolla', 'name' => 'Aros de cebolla', 'description' => 'Crocantes con salsa ranch.', 'price' => 9500, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'yuca-frita', 'name' => 'Yuca frita con hogao', 'description' => 'Yuca dorada con hogao casero.', 'price' => 9900, 'image_path' => null, 'available' => true, 'order' => 2],
                ],
            ],
            [
                'id' => 'bebidas',
                'name' => 'Bebidas',
                'description' => 'Para acompañar.',
                'order' => 5,
                'items' => [
                    ['id' => 'jugo-natural', 'name' => 'Jugo natural', 'description' => 'Mango, mora o lulo, en agua o leche.', 'price' => 7500, 'image_path' => null, 'available' => true, 'order' => 1],
                    ['id' => 'brownie-helado', 'name' => 'Brownie con helado', 'description' => 'Tibio con vainilla y chocolate.', 'price' => 13900, 'image_path' => null, 'available' => true, 'order' => 2],
                ],
            ],
        ];
    }

    private function clearOperationalData(string $companyNit): void
    {
        CouponRedemption::query()->where('company_nit', $companyNit)->delete();
        PaymentReceipt::query()->where('company_nit', $companyNit)->delete();
        Delivery::query()->withTrashed()->where('company_nit', $companyNit)->forceDelete();
        Order::query()->where('company_nit', $companyNit)->delete();
        DB::table('chats')->where('company_nit', $companyNit)->delete();
        DB::table('cart_sessions')->where('company_nit', $companyNit)->delete();
        DB::table('contacts')->where('company_nit', $companyNit)->delete();
        Coupon::query()->withTrashed()->where('company_nit', $companyNit)->forceDelete();
        RestaurantMenu::query()->where('company_nit', $companyNit)->delete();
        // Snapshots históricos: se regeneran al final del seed. En reseed, los
        // borramos para no acumular días viejos que ya no aplican al periodo demo.
        DB::table('warehouse_stock_snapshots')->where('company_nit', $companyNit)->delete();
    }

    /**
     * Asegura que la sede tenga al menos 2 bodegas para demo:
     *  - Cocina (kitchen, default): recibe cárnicos/lácteos y de allí descuentan ventas.
     *  - Bodega seca (dry_storage): abarrotes, salsas, bebidas envasadas.
     *
     * @return array<string, Warehouse> ['kitchen' => Warehouse, 'dry_storage' => Warehouse]
     */
    private function ensureWarehouses(string $companyNit, Branch $branch): array
    {
        // (#costeo-multibodega) Las bodegas son company-scoped (slug único por
        // empresa) y se asignan a la sede vía el pivot branch_warehouses. El
        // slug se namespacea por sede para que demos multi-sede no colisionen.
        $kitchen = Warehouse::query()->firstOrCreate(
            ['company_nit' => $companyNit, 'slug' => 'cocina-'.$branch->slug],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'Cocina',
                'type' => Warehouse::TYPE_KITCHEN,
                'is_default' => false,
            ],
        );

        $dry = Warehouse::query()->firstOrCreate(
            ['company_nit' => $companyNit, 'slug' => 'bodega-seca-'.$branch->slug],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'Bodega seca',
                'type' => Warehouse::TYPE_DRY_STORAGE,
                'is_default' => false,
            ],
        );

        Warehouse::assignToBranch($companyNit, $branch->id, $kitchen->id, isDefault: true);
        Warehouse::assignToBranch($companyNit, $branch->id, $dry->id, isDefault: false);

        return ['kitchen' => $kitchen, 'dry_storage' => $dry];
    }

    /**
     * Cupones a nivel empresa (scope='company') válidos en ambas sedes:
     *  - BIENVENIDA: 15% primer pedido ≥ 30k.
     *  - DOMICILIO5: 5k off en domicilios ≥ 40k.
     *  - VIERNES10: 10k off los viernes ≥ 60k.
     *
     * @return array{welcome: Coupon, delivery: Coupon, friday: Coupon, happyhour: Coupon, noche: Coupon}
     */
    private function seedCoupons(string $companyNit, Branch $defaultBranch, User $admin): array
    {
        $validFrom = now()->subMonths(2)->startOfDay();

        BelongsToBranch::setSeederBranch($defaultBranch->id);
        try {
            return [
                'welcome' => Coupon::updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => 'BIENVENIDA'],
                    [
                        'scope' => 'company',
                        'valid_in_branches' => null,
                        'type' => 'percentage',
                        'value' => 15,
                        'valid_from' => $validFrom,
                        'valid_until' => now()->addMonths(3),
                        'max_uses' => null,
                        'uses_count' => 0,
                        'min_order_amount' => 25000,
                        'first_order_only' => true,
                        'is_active' => true,
                        'status' => 'active',
                        'created_by' => $admin->email,
                    ]
                ),
                'delivery' => Coupon::updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => 'DOMICILIO5'],
                    [
                        'scope' => 'company',
                        'valid_in_branches' => null,
                        'type' => 'fixed_amount',
                        'value' => 5000,
                        'valid_from' => $validFrom,
                        'valid_until' => now()->addMonth(),
                        'max_uses' => 200,
                        'uses_count' => 0,
                        'min_order_amount' => 40000,
                        'first_order_only' => false,
                        'is_active' => true,
                        'status' => 'active',
                        'created_by' => $admin->email,
                    ]
                ),
                'friday' => Coupon::updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => 'VIERNES10'],
                    [
                        'scope' => 'company',
                        'valid_in_branches' => null,
                        'type' => 'fixed_amount',
                        'value' => 10000,
                        'valid_from' => $validFrom,
                        'valid_until' => now()->addMonths(2),
                        'max_uses' => null,
                        'uses_count' => 0,
                        'min_order_amount' => 60000,
                        'first_order_only' => false,
                        'is_active' => true,
                        'status' => 'active',
                        'created_by' => $admin->email,
                    ]
                ),
                // Happy hour clásico (#125): -20% Mar/Mié/Jue 16:00–19:00, auto-aplicado.
                // valid_until siempre incluye HOY (+3 meses desde now) para que la demo lo
                // muestre como activo sin reseed manual.
                'happyhour' => Coupon::updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => 'HAPPYHOUR'],
                    [
                        'scope' => 'company',
                        'valid_in_branches' => null,
                        'type' => 'percentage',
                        'value' => 20,
                        'valid_from' => $validFrom,
                        'valid_until' => now()->addMonths(3),
                        'valid_days' => [2, 3, 4],
                        'valid_hours_from' => '16:00:00',
                        'valid_hours_to' => '19:00:00',
                        'auto_apply' => true,
                        'max_uses' => null,
                        'uses_count' => 0,
                        'min_order_amount' => 20000,
                        'first_order_only' => false,
                        'is_active' => true,
                        'status' => 'active',
                        'created_by' => $admin->email,
                    ]
                ),
                // Promo nocturna cross-midnight (#125): −$5.000 desde 22:00 hasta 02:00,
                // auto-aplicado, todos los días. Sirve para validar la lógica de ventana
                // que cruza medianoche en `Coupon::isScheduledNow()`.
                'noche' => Coupon::updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => 'NOCHE5K'],
                    [
                        'scope' => 'company',
                        'valid_in_branches' => null,
                        'type' => 'fixed_amount',
                        'value' => 5000,
                        'valid_from' => $validFrom,
                        'valid_until' => now()->addMonths(3),
                        'valid_days' => null,
                        'valid_hours_from' => '22:00:00',
                        'valid_hours_to' => '02:00:00',
                        'auto_apply' => true,
                        'max_uses' => null,
                        'uses_count' => 0,
                        'min_order_amount' => 30000,
                        'first_order_only' => false,
                        'is_active' => true,
                        'status' => 'active',
                        'created_by' => $admin->email,
                    ]
                ),
            ];
        } finally {
            BelongsToBranch::setSeederBranch(null);
        }
    }

    /**
     * Genera 60 días de órdenes históricas para la sede dada.
     *
     * Volumen base por día (mar-dom; lunes cerrado):
     *  - Pereira (más activa): 28-42 órdenes/día.
     *  - Cartago (más pequeña): 18-30 órdenes/día.
     *
     * Distribución de estados: 92% completed, 3% cancelled, 2% failed (delivery), 3% abandoned.
     * Mix order_type:
     *  - Pereira: 60% delivery, 25% pickup, 15% mesa.
     *  - Cartago: 45% delivery, 35% mesa, 20% pickup (más mesa por menú compartido).
     *
     * @param  array{owner: User, admin: User, kitchen: User, courier: User}  $users
     * @param  array{welcome: Coupon, delivery: Coupon, friday: Coupon, happyhour: Coupon, noche: Coupon}  $coupons
     */
    private function seedHistoricalOrdersForBranch(
        string $companyNit,
        Branch $branch,
        string $branchKey,
        array $users,
        array $coupons,
    ): void {
        $catalog = $this->orderCatalogForBranch($branchKey);
        $startDate = now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);
        $courier = $users['courier'];
        $admin = $users['admin'];

        $baseVolume = $branchKey === 'pereira'
            ? ['weekday' => 30, 'friday' => 42, 'weekend' => 38, 'sunday' => 32]
            : ['weekday' => 20, 'friday' => 28, 'weekend' => 25, 'sunday' => 22];

        $isPereira = $branchKey === 'pereira';

        for ($dayIndex = 0; $dayIndex < self::HISTORY_DAYS; $dayIndex++) {
            $date = $startDate->copy()->addDays($dayIndex);

            if ($date->dayOfWeek === Carbon::MONDAY) {
                continue;
            }

            $ordersToGenerate = match (true) {
                $date->dayOfWeek === Carbon::FRIDAY => $baseVolume['friday'],
                $date->dayOfWeek === Carbon::SATURDAY => $baseVolume['weekend'],
                $date->dayOfWeek === Carbon::SUNDAY => $baseVolume['sunday'],
                default => $baseVolume['weekday'],
            };

            for ($orderIndex = 0; $orderIndex < $ordersToGenerate; $orderIndex++) {
                $sequence = ($dayIndex * 60) + $orderIndex + 1;
                $orderedAt = $this->historicalOrderedAt($date, $dayIndex, $orderIndex);

                if ($orderedAt === null) {
                    break;
                }

                $orderType = $this->resolveOrderType($isPereira, $dayIndex, $orderIndex);
                $isDelivery = $orderType === 'delivery';

                $status = $this->historicalOrderStatus($sequence, $isDelivery);

                $clientPhone = $this->historicalClientPhone($branchKey, $dayIndex, $orderIndex);
                $sessionId = sprintf('sp-%s-%s-%02d', $branchKey, $date->format('Ymd'), $orderIndex + 1);

                $session = $this->upsertCartSession(
                    $companyNit,
                    $sessionId,
                    $clientPhone,
                    $status === 'abandoned' ? 'abandoned' : 'converted',
                    $orderedAt->copy()->subMinutes(12 + ($sequence % 35))
                );

                $basket = $this->buildBasket($catalog, $sequence, $isDelivery);
                $coupon = $this->resolveHistoricalCoupon($coupons, $orderedAt, $basket['subtotal'], $sequence, $status, $isDelivery);
                $discountAmount = $coupon?->calculateDiscount($basket['subtotal']) ?? 0.0;
                $total = max($basket['subtotal'] - $discountAmount, 0.0);

                $tableNumber = $orderType === 'table' ? (string) (1 + ($sequence % 10)) : null;
                $deliveryAddress = $orderType === 'delivery'
                    ? $this->fakeAddressFor($branchKey, $sequence)
                    : null;

                $order = Order::updateOrCreate(
                    ['company_nit' => $companyNit, 'session_id' => $session->jwt_jti],
                    $this->orderValues(
                        clientPhone: $clientPhone,
                        items: $basket['items'],
                        status: $status,
                        orderType: $orderType,
                        cost: $basket['cost'],
                        orderedAt: $orderedAt,
                        tableNumber: $tableNumber,
                        deliveryAddress: $deliveryAddress,
                        couponCode: $coupon?->code,
                        discountAmount: round($discountAmount, 2),
                        totalOverride: round($total, 2),
                    )
                );

                if ($coupon !== null) {
                    CouponRedemption::query()->updateOrCreate(
                        ['coupon_id' => $coupon->id, 'order_id' => $order->id],
                        [
                            'company_nit' => $companyNit,
                            'client_phone' => $clientPhone,
                            'discount_amount' => round($discountAmount, 2),
                            'order_total_before' => round($basket['subtotal'], 2),
                            'order_total_after' => round($total, 2),
                            'created_at' => $orderedAt,
                        ]
                    );
                }

                if (in_array($status, config('orders.revenue'), true)) {
                    $paymentMethod = $this->paymentMethodFor($orderType, $sequence);
                    $reference = sprintf('SP-%s-%s-%05d', strtoupper(substr($branchKey, 0, 3)), strtoupper(substr($paymentMethod, 0, 3)), $sequence);

                    $this->upsertPaymentReceipt(
                        $companyNit,
                        $order,
                        paymentMethod: $paymentMethod,
                        amount: round($total, 2),
                        reference: $reference,
                        paidAt: $orderedAt->copy()->addMinutes(5 + ($sequence % 25)),
                        filePath: $paymentMethod === 'transfer'
                            ? sprintf('receipts/history/%s.jpg', $sessionId)
                            : null,
                    );
                }

                if ($isDelivery && $status !== 'abandoned') {
                    $this->upsertHistoricalDelivery($companyNit, $order, $courier, $admin, $sequence);
                }
            }
        }
    }

    private function resolveOrderType(bool $isPereira, int $dayIndex, int $orderIndex): string
    {
        $roll = ($dayIndex * 7 + $orderIndex * 3) % 100;

        // Pereira no tiene domiciliario asignado en el pivot (#117). Por
        // coherencia operativa, no se generan órdenes delivery: solo pickup
        // y mesa. Cartago concentra el servicio a domicilio.
        if ($isPereira) {
            return match (true) {
                $roll < 65 => 'pickup',
                default => 'table',
            };
        }

        return match (true) {
            $roll < 50 => 'delivery',
            $roll < 80 => 'table',
            default => 'pickup',
        };
    }

    /**
     * @return list<array{id: string, name: string, price: int, cost: int}>
     */
    private function orderCatalogForBranch(string $branchKey): array
    {
        if ($branchKey === 'pereira') {
            return [
                ['id' => 'salchipapa-clasica', 'name' => 'Salchipapa clásica', 'price' => 12000, 'cost' => 4500],
                ['id' => 'salchipapa-especial', 'name' => 'Salchipapa especial', 'price' => 16000, 'cost' => 6500],
                ['id' => 'salchipapa-superpapa', 'name' => 'Salchipapa SuperPapa', 'price' => 24900, 'cost' => 10500],
                ['id' => 'mega-salchipapa', 'name' => 'Mega salchipapa para 2', 'price' => 32000, 'cost' => 13800],
                ['id' => 'perro-sencillo', 'name' => 'Perro sencillo', 'price' => 10500, 'cost' => 4200],
                ['id' => 'perro-especial', 'name' => 'Perro especial', 'price' => 14500, 'cost' => 6000],
                ['id' => 'papas-francesas', 'name' => 'Papas a la francesa', 'price' => 8500, 'cost' => 2800],
                ['id' => 'gaseosa-personal', 'name' => 'Gaseosa personal', 'price' => 4500, 'cost' => 1500],
                ['id' => 'limonada-natural', 'name' => 'Limonada natural', 'price' => 6500, 'cost' => 1800],
                ['id' => 'cerveza-nacional', 'name' => 'Cerveza nacional', 'price' => 7000, 'cost' => 3500],
            ];
        }

        return [
            ['id' => 'hamburguesa-clasica', 'name' => 'Hamburguesa clásica', 'price' => 14900, 'cost' => 6200],
            ['id' => 'hamburguesa-bbq', 'name' => 'Hamburguesa BBQ', 'price' => 18500, 'cost' => 7800],
            ['id' => 'hamburguesa-pollo', 'name' => 'Hamburguesa de pollo', 'price' => 16900, 'cost' => 6900],
            ['id' => 'perro-ranchero', 'name' => 'Perro ranchero', 'price' => 15900, 'cost' => 6700],
            ['id' => 'picada-personal', 'name' => 'Picada personal', 'price' => 22000, 'cost' => 9500],
            ['id' => 'picada-para-2', 'name' => 'Picada para 2', 'price' => 39000, 'cost' => 16800],
            ['id' => 'aros-cebolla', 'name' => 'Aros de cebolla', 'price' => 9500, 'cost' => 3200],
            ['id' => 'yuca-frita', 'name' => 'Yuca frita con hogao', 'price' => 9900, 'cost' => 3500],
            ['id' => 'jugo-natural', 'name' => 'Jugo natural', 'price' => 7500, 'cost' => 2200],
            ['id' => 'brownie-helado', 'name' => 'Brownie con helado', 'price' => 13900, 'cost' => 5500],
        ];
    }

    private function fakeAddressFor(string $branchKey, int $sequence): string
    {
        if ($branchKey === 'pereira') {
            return sprintf('Cra %d #%d-%d, Pereira', 5 + ($sequence % 25), 10 + ($sequence % 50), 1 + ($sequence % 99));
        }

        return sprintf('Cl %d #%d-%d, Cartago', 8 + ($sequence % 22), 4 + ($sequence % 30), 1 + ($sequence % 99));
    }

    /**
     * Distribución realista por sede:
     *  - 92% completed, 3% cancelled, 2% failed (solo delivery), 3% abandoned.
     */
    private function historicalOrderStatus(int $sequence, bool $isDelivery): string
    {
        $bucket = $sequence % 100;

        if ($bucket < 3) {
            return 'cancelled';
        }

        if ($bucket < 6 && $isDelivery) {
            return 'failed';
        }

        if ($bucket < 9) {
            return 'abandoned';
        }

        return 'completed';
    }

    private function historicalOrderedAt(Carbon $date, int $dayIndex, int $orderIndex): ?Carbon
    {
        $weekdaySlots = [16, 17, 17, 18, 18, 19, 19, 19, 20, 20, 20, 21, 21, 22];
        $fridaySlots = [16, 17, 18, 18, 19, 19, 19, 20, 20, 20, 21, 21, 22, 22, 23];
        $saturdaySlots = [14, 15, 16, 17, 18, 18, 19, 19, 20, 20, 20, 21, 21, 22, 22, 23];
        $sundaySlots = [14, 15, 16, 17, 18, 18, 19, 19, 20, 20, 21, 21, 22];

        $slots = match ($date->dayOfWeek) {
            Carbon::SUNDAY => $sundaySlots,
            Carbon::FRIDAY => $fridaySlots,
            Carbon::SATURDAY => $saturdaySlots,
            default => $weekdaySlots,
        };

        $openHour = $slots[0];
        $hour = $slots[($dayIndex + $orderIndex) % count($slots)];
        $minute = (($orderIndex * 7) + ($dayIndex * 3)) % 60;
        $orderedAt = $date->copy()->setTime($hour, $minute);

        if ($date->isToday()) {
            if (now()->hour < $openHour) {
                return null;
            }

            $latestAllowed = now()->subMinutes(10);
            if ($orderedAt->greaterThan($latestAllowed)) {
                $orderedAt = $latestAllowed->copy()->subMinutes(($orderIndex * 5) % 180);
            }
        }

        return $orderedAt;
    }

    private function historicalClientPhone(string $branchKey, int $dayIndex, int $orderIndex): string
    {
        $offset = $branchKey === 'pereira' ? 0 : self::CUSTOMER_POOL_SIZE;
        $customerIndex = ($dayIndex * 7 + $orderIndex * 13) % self::CUSTOMER_POOL_SIZE;

        // Convención del proyecto: phones de cliente se guardan en 10 dígitos
        // (sin prefijo país). El normalizador del CRM tolera ambos formatos
        // en lectura, pero la convención canónica para datos nuevos es 10 dig.
        return str_pad((string) (self::CUSTOMER_PHONE_BASE + $offset + $customerIndex), 10, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array{id: string, name: string, price: int, cost: int}>  $catalog
     * @return array{
     *     items: list<array{id: string, name: string, price: int, quantity: int}>,
     *     subtotal: float,
     *     cost: float
     * }
     */
    private function buildBasket(array $catalog, int $sequence, bool $isDelivery): array
    {
        $items = [];
        $subtotal = 0.0;
        $cost = 0.0;
        $itemCount = $isDelivery ? 2 + ($sequence % 3) : 1 + ($sequence % 2);

        for ($offset = 0; $offset < $itemCount; $offset++) {
            $catalogItem = $catalog[($sequence + ($offset * 3)) % count($catalog)];
            $quantity = $offset === 0 && $sequence % 6 === 0 ? 2 : 1;

            $items[] = [
                'id' => $catalogItem['id'],
                'name' => $catalogItem['name'],
                'price' => $catalogItem['price'],
                'cost' => $catalogItem['cost'],
                'quantity' => $quantity,
            ];

            $subtotal += $catalogItem['price'] * $quantity;
            $cost += $catalogItem['cost'] * $quantity;
        }

        return [
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'cost' => round($cost, 2),
        ];
    }

    /**
     * @param  array{welcome: Coupon, delivery: Coupon, friday: Coupon, happyhour: Coupon, noche: Coupon}  $coupons
     */
    private function resolveHistoricalCoupon(
        array $coupons,
        Carbon $orderedAt,
        float $subtotal,
        int $sequence,
        string $status,
        bool $isDelivery
    ): ?Coupon {
        if (! in_array($status, config('orders.revenue'), true)) {
            return null;
        }

        if (
            $orderedAt->dayOfWeek === Carbon::FRIDAY
            && $subtotal >= 60000
            && $sequence % 12 === 0
            && $this->couponWasActiveAt($coupons['friday'], $orderedAt)
        ) {
            return $coupons['friday'];
        }

        if (
            $isDelivery
            && $subtotal >= 40000
            && $sequence % 14 === 0
            && $this->couponWasActiveAt($coupons['delivery'], $orderedAt)
        ) {
            return $coupons['delivery'];
        }

        if (
            $subtotal >= 25000
            && $sequence % 18 === 0
            && $this->couponWasActiveAt($coupons['welcome'], $orderedAt)
        ) {
            return $coupons['welcome'];
        }

        return null;
    }

    private function couponWasActiveAt(Coupon $coupon, Carbon $orderedAt): bool
    {
        if ($coupon->valid_from !== null && $orderedAt->lt($coupon->valid_from)) {
            return false;
        }

        if ($coupon->valid_until !== null && $orderedAt->gt($coupon->valid_until)) {
            return false;
        }

        return $coupon->is_active;
    }

    private function upsertHistoricalDelivery(string $companyNit, Order $order, User $courier, User $admin, int $sequence): void
    {
        $assignedAt = ($order->ordered_at ?? now())->copy()->addMinutes(8 + ($sequence % 12));

        if ($order->status === 'cancelled') {
            Delivery::query()->updateOrCreate(
                ['company_nit' => $companyNit, 'order_id' => $order->id],
                [
                    'user_id' => $courier->id,
                    'assigned_at' => $assignedAt,
                    'delivered_at' => null,
                    'duration_minutes' => null,
                    'status' => 'cancelled',
                    'previous_delivery_id' => null,
                    'cancellation_reason' => 'Cliente canceló antes de despacho',
                    'created_by' => $admin->id,
                ]
            );

            return;
        }

        if ($order->status === 'failed') {
            Delivery::query()->updateOrCreate(
                ['company_nit' => $companyNit, 'order_id' => $order->id],
                [
                    'user_id' => $courier->id,
                    'assigned_at' => $assignedAt,
                    'delivered_at' => null,
                    'duration_minutes' => null,
                    'status' => 'failed',
                    'previous_delivery_id' => null,
                    'cancellation_reason' => 'Dirección incorrecta / cliente no responde',
                    'created_by' => $admin->id,
                ]
            );

            return;
        }

        $durationMinutes = 28 + ($sequence % 25);

        Delivery::query()->updateOrCreate(
            ['company_nit' => $companyNit, 'order_id' => $order->id],
            [
                'user_id' => $courier->id,
                'assigned_at' => $assignedAt,
                'delivered_at' => $assignedAt->copy()->addMinutes($durationMinutes),
                'duration_minutes' => $durationMinutes,
                'status' => 'completed',
                'previous_delivery_id' => null,
                'reason' => 'Entrega programada',
                'created_by' => $admin->id,
            ]
        );
    }

    /**
     * Genera órdenes "live" de hoy (kanban de cocina) y conversaciones simples
     * sólo en la sede principal (Pereira).
     *
     * @param  array{owner: User, admin: User, kitchen: User, courier: User}  $users
     */
    private function seedLiveOrdersAndChats(string $companyNit, array $users): void
    {
        unset($users); // No se usan por ahora; el detalle de live orders se genera en seedHistoricalOrdersForBranch.

        $now = Carbon::now();

        // 3 chats simples para que el panel tenga conversaciones poblando.
        $samples = [
            ['client_phone' => '3001112201', 'client_name' => 'Juan Pérez', 'minutes_ago' => 12],
            ['client_phone' => '3001112202', 'client_name' => 'Andrea Cardona', 'minutes_ago' => 35],
            ['client_phone' => '3001112203', 'client_name' => 'Daniel Ríos', 'minutes_ago' => 90],
        ];

        foreach ($samples as $sample) {
            $existsChat = DB::table('chats')
                ->where('company_nit', $companyNit)
                ->where('client_phone', $sample['client_phone'])
                ->exists();

            if ($existsChat) {
                DB::table('chats')
                    ->where('company_nit', $companyNit)
                    ->where('client_phone', $sample['client_phone'])
                    ->update([
                        'branch_id' => $this->currentBranchId(),
                        'client_name' => $sample['client_name'],
                        'source' => 'whatsapp',
                        'status' => 'open',
                        'bot_paused' => false,
                        'last_message_at' => $now->copy()->subMinutes($sample['minutes_ago']),
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('chats')->insert([
                    'id' => (string) Str::uuid7(),
                    'company_nit' => $companyNit,
                    'client_phone' => $sample['client_phone'],
                    'branch_id' => $this->currentBranchId(),
                    'client_name' => $sample['client_name'],
                    'source' => 'whatsapp',
                    'status' => 'open',
                    'bot_paused' => false,
                    'last_message_at' => $now->copy()->subMinutes($sample['minutes_ago']),
                    'created_at' => $now->copy()->subDays(1),
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedBilling(string $companyNit, User $owner): void
    {
        $this->call(BillingPlanSeeder::class);

        // #246 consolidó el catálogo a un único plan activo; ya no existe 'pro'.
        $plan = BillingPlan::where('slug', config('billing.default_plan_slug', 'default'))->firstOrFail();

        $startsAt = now()->subMonths(3)->startOfMonth();

        $subscription = Subscription::updateOrCreate(
            ['company_nit' => $companyNit, 'status' => 'active'],
            [
                'billing_plan_id' => $plan->id,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => null,
            ]
        );

        $invoiceMonths = [
            ['offset' => 3, 'status' => 'paid'],
            ['offset' => 2, 'status' => 'paid'],
            ['offset' => 1, 'status' => 'pending'],
        ];

        foreach ($invoiceMonths as ['offset' => $offset, 'status' => $status]) {
            $month = now()->subMonths($offset);
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
                'company_nit' => $companyNit,
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
                'status' => $status,
                'generated_at' => $month->copy()->day(20)->setTime(3, 0),
            ]);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'description' => "Suscripción Plan {$plan->name} — {$month->translatedFormat('F Y')}",
                'quantity' => 1,
                'unit_price' => $plan->price,
                'subtotal' => $plan->price,
            ]);

            if ($status === 'paid') {
                InvoicePayment::create([
                    'invoice_id' => $invoice->id,
                    'company_nit' => $companyNit,
                    'registered_by' => $owner->id,
                    'amount' => $plan->price,
                    'currency' => $plan->currency,
                    'payment_date' => $month->copy()->day(rand(1, 14))->toDateString(),
                    'payment_reference' => 'SP-PAY-'.strtoupper($month->format('MY')).'-'.rand(1000, 9999),
                    'payment_method' => 'transferencia',
                    'notes' => 'Pago registrado por administrador de plataforma.',
                ]);
            }
        }
    }

    /** @param array{welcome: Coupon, delivery: Coupon, friday: Coupon, happyhour: Coupon, noche: Coupon} $coupons */
    private function syncCouponUsageStatistics(array $coupons): void
    {
        foreach ($coupons as $coupon) {
            $coupon->refresh();

            $usesCount = $coupon->redemptions()->count();
            $status = $coupon->is_active ? 'active' : 'inactive';

            if ($coupon->valid_until !== null && $coupon->valid_until->isPast()) {
                $status = 'inactive';
            }

            if ($coupon->max_uses !== null && $usesCount >= $coupon->max_uses) {
                $status = 'exhausted';
            }

            $coupon->update([
                'uses_count' => $usesCount,
                'status' => $status,
            ]);
        }
    }

    /**
     * @param  list<array{id: string, name: string, price: int, quantity: int}>  $items
     * @return array<string, mixed>
     */
    private function orderValues(
        string $clientPhone,
        array $items,
        string $status,
        string $orderType,
        float $cost,
        Carbon $orderedAt,
        ?string $tableNumber = null,
        ?string $deliveryAddress = null,
        ?string $couponCode = null,
        float $discountAmount = 0.0,
        ?float $totalOverride = null,
    ): array {
        $itemsTotal = 0.0;
        foreach ($items as $item) {
            $itemsTotal += $item['price'] * $item['quantity'];
        }

        $total = $totalOverride ?? max($itemsTotal - $discountAmount, 0.0);

        return [
            'branch_id' => $this->currentBranchId(),
            'client_phone' => $clientPhone,
            'items' => $items,
            'status' => $status,
            'order_type' => $orderType,
            'table_number' => $tableNumber,
            'delivery_address' => $deliveryAddress,
            'total' => round($total, 2),
            'subtotal' => round($total, 2),
            'tax_amount' => 0,
            'tax_rate' => 0,
            'tax_regime' => 'simple',
            'tax_included_in_price' => true,
            'tip_amount' => 0,
            'cost' => round($cost, 2),
            'coupon_code' => $couponCode,
            'discount_amount' => round($discountAmount, 2),
            'ordered_at' => $orderedAt,
        ];
    }

    private function upsertCartSession(
        string $companyNit,
        string $jwtJti,
        string $clientPhone,
        string $status,
        Carbon $baseTime
    ): object {
        $expiredAt = match ($status) {
            'active' => $baseTime->copy()->addHours(2),
            'abandoned' => $baseTime->copy()->subHour(),
            default => $baseTime->copy()->addHour(),
        };

        $existsCart = DB::table('cart_sessions')->where('jwt_jti', $jwtJti)->exists();

        if ($existsCart) {
            DB::table('cart_sessions')
                ->where('jwt_jti', $jwtJti)
                ->update([
                    'company_nit' => $companyNit,
                    'branch_id' => $this->currentBranchId(),
                    'client_phone' => $clientPhone,
                    'status' => $status,
                    'expired_at' => $expiredAt,
                ]);
        } else {
            DB::table('cart_sessions')->insert([
                'id' => (string) Str::uuid7(),
                'jwt_jti' => $jwtJti,
                'company_nit' => $companyNit,
                'branch_id' => $this->currentBranchId(),
                'client_phone' => $clientPhone,
                'status' => $status,
                'expired_at' => $expiredAt,
                'created_at' => $baseTime,
            ]);
        }

        return DB::table('cart_sessions')->where('jwt_jti', $jwtJti)->first();
    }

    /**
     * Helper que devuelve el branch_id actualmente configurado para el seeder.
     */
    private function currentBranchId(): string
    {
        return app('belongs_to_branch.seeder_branch_id');
    }

    /**
     * @param  array<string, mixed>  $paymentData
     */
    private function upsertPaymentReceipt(
        string $companyNit,
        Order $order,
        string $paymentMethod,
        float $amount,
        string $reference,
        Carbon $paidAt,
        ?string $filePath = null,
        array $paymentData = [],
    ): PaymentReceipt {
        $receipt = PaymentReceipt::query()->updateOrCreate(
            ['order_id' => $order->id, 'reference' => $reference],
            [
                'company_nit' => $companyNit,
                'payment_method' => $paymentMethod,
                'amount' => round($amount, 2),
                'paid_at' => $paidAt,
                'file_path' => $filePath,
                'payment_data' => $paymentData,
            ]
        );

        $receipt->created_at = $paidAt;
        $receipt->save();

        return $receipt;
    }

    private function paymentMethodFor(string $orderType, int $sequence): string
    {
        return match ($orderType) {
            'table' => $sequence % 5 < 3 ? 'cash' : 'card',
            'pickup' => match ($sequence % 10) {
                0, 1, 2, 3, 4 => 'cash',
                5, 6, 7 => 'card',
                default => 'transfer',
            },
            default => match ($sequence % 10) {
                0, 1, 2, 3, 4 => 'transfer',
                5, 6, 7 => 'cash',
                default => 'card',
            },
        };
    }

    private function upsertUser(
        string $email,
        string $firstName,
        string $lastName,
        string $googleId,
        string $cedula
    ): User {
        // `name` es columna generada (first_name + last_name): no se escribe.
        return User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'google_id' => $googleId,
                'cedula' => $cedula,
                'status' => 'active',
                'password' => null,
            ]
        );
    }

    private function seedSystemRoleFromTemplate(string $companyNit, string $roleType, ?string $color = null): CompanyRole
    {
        $roleName = config("roles.role_names.{$roleType}", ucfirst($roleType));

        $role = CompanyRole::updateOrCreate(
            ['company_nit' => $companyNit, 'name' => $roleName],
            [
                'description' => "Rol {$roleName} para SuperPapas",
                'is_system' => true,
                'color' => $color,
            ]
        );

        PermissionTemplate::query()
            ->where('role_type', $roleType)
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

        return $role;
    }

    /**
     * Inventario coherente con menús y proveedores. Cada sede tiene su propio
     * conjunto de ingredients alineados con los platos de su carta. Los
     * proveedores son por empresa y abastecen las dos sedes.
     *
     * Devuelve `['suppliers' => map, 'ingredients' => map]` para que los
     * pasos siguientes (recipes, purchases, consumos) puedan referenciarlos.
     *
     * @return array{suppliers: array<string, Supplier>, ingredients: array<string, Ingredient>}
     */
    private function seedInventoryAndPurchases(string $companyNit, Branch $branch, string $branchKey, User $admin): array
    {
        $suppliers = $this->ensureSuppliers($companyNit);
        $ingredients = $this->seedIngredients($companyNit, $branchKey);
        $this->seedSupplierIngredients($branchKey, $suppliers, $ingredients);
        $this->seedPurchaseOrders($companyNit, $branch, $branchKey, $suppliers, $ingredients, $admin);

        return [
            'suppliers' => $suppliers,
            'ingredients' => $ingredients,
        ];
    }

    /**
     * Proveedores reales del Eje Cafetero, compartidos por las dos sedes
     * (la empresa los gestiona globalmente). branch_id queda en la sede
     * donde se crea cada PO; el supplier en sí cambia con el branch_id
     * del seeder activo, pero la empresa los reutiliza.
     *
     * @return array<string, Supplier>
     */
    private function ensureSuppliers(string $companyNit): array
    {
        $defs = [
            'frutiverduras' => [
                'name' => 'Frutiverduras del Eje',
                'document_type' => 'NIT',
                'document_number' => '900456001-3',
                'contact_name' => 'María González',
                'phone' => '3104567001',
                'email' => 'pedidos@frutiverduras.co',
                'payment_terms_days' => 7,
            ],
            'frigorifico' => [
                'name' => 'Frigorífico La Cuchilla',
                'document_type' => 'NIT',
                'document_number' => '900456002-1',
                'contact_name' => 'Juan Restrepo',
                'phone' => '3104567002',
                'email' => 'ventas@frigocuchilla.co',
                'payment_terms_days' => 15,
            ],
            'lacteos' => [
                'name' => 'Lácteos del Otún',
                'document_type' => 'NIT',
                'document_number' => '900456003-9',
                'contact_name' => 'Diana López',
                'phone' => '3104567003',
                'email' => 'lacteos@otun.co',
                'payment_terms_days' => 8,
            ],
            'panaderia' => [
                'name' => 'Panadería Pan de Casa',
                'document_type' => 'NIT',
                'document_number' => '900456004-7',
                'contact_name' => 'Carlos Bedoya',
                'phone' => '3104567004',
                'email' => 'pedidos@pandecasa.co',
                'payment_terms_days' => 0,
            ],
            'bebidas' => [
                'name' => 'Distribuidora Bebidas Pereira',
                'document_type' => 'NIT',
                'document_number' => '900456005-5',
                'contact_name' => 'Andrés Vélez',
                'phone' => '3104567005',
                'email' => 'pedidos@bebidaspereira.co',
                'payment_terms_days' => 30,
            ],
        ];

        $suppliers = [];
        foreach ($defs as $key => $def) {
            $suppliers[$key] = Supplier::query()->updateOrCreate(
                ['company_nit' => $companyNit, 'document_number' => $def['document_number']],
                array_merge($def, [
                    'address' => 'Pereira, Risaralda',
                    'notes' => 'Proveedor habitual SuperPapas.',
                ]),
            );
        }

        return $suppliers;
    }

    /**
     * Ingredientes por sede, alineados con la carta. Cantidades iniciales
     * realistas para una salchipapería pequeña. Los costos son neto sin IVA
     * (los proveedores aplican IVA al facturar; el restaurante registra el
     * monto neto + tax aparte en purchase_order_items).
     *
     * @return array<string, Ingredient>
     */
    private function seedIngredients(string $companyNit, string $branchKey): array
    {
        $defs = $branchKey === 'pereira'
            ? $this->pereiraIngredientsDef()
            : $this->cartagoIngredientsDef();

        $warehouses = $this->warehousesByBranch[$branchKey];
        $kitchen = $warehouses['kitchen'];
        $dry = $warehouses['dry_storage'];

        $ingredients = [];
        foreach ($defs as $slug => $def) {
            // (#costeo-multibodega) El insumo es catálogo de empresa: sin
            // branch_id ni costo global. El WAC vive por bodega en
            // ingredient_stocks.current_cost. Si dos sedes comparten nombre,
            // comparten la identidad del insumo (stock/WAC siguen por bodega).
            $ingredient = Ingredient::query()->updateOrCreate(
                [
                    'company_nit' => $companyNit,
                    'name' => $def['name'],
                ],
                [
                    'category' => $def['category'],
                    'unit' => $def['unit'],
                ],
            );

            // Stock inicial distribuido: perecederos (cárnicos, lácteos,
            // vegetales, frutas, panadería, postres) van a cocina; abarrotes,
            // bebidas y salsas envasadas van a bodega seca. Min_stock por
            // bodega permite alertas granulares.
            $kitchenCategories = ['Cárnicos', 'Lácteos', 'Vegetales', 'Frutas', 'Panadería', 'Postres'];
            $goesToKitchen = in_array($def['category'], $kitchenCategories, true);
            $target = $goesToKitchen ? $kitchen : $dry;

            IngredientStock::query()->updateOrCreate(
                ['ingredient_id' => $ingredient->id, 'warehouse_id' => $target->id],
                [
                    'quantity' => $def['stock'],
                    'min_stock' => $def['min_stock'],
                    // WAC inicial de la bodega (#costeo-multibodega).
                    'current_cost' => $def['cost'],
                    'updated_at' => now(),
                ],
            );

            // Fila vacía en la otra bodega para que el frontend pueda mostrar
            // el insumo bajo cualquier filtro de bodega (con quantity=0).
            $other = $goesToKitchen ? $dry : $kitchen;
            IngredientStock::query()->firstOrCreate(
                ['ingredient_id' => $ingredient->id, 'warehouse_id' => $other->id],
                ['quantity' => 0, 'min_stock' => 0, 'updated_at' => now()],
            );

            $ingredients[$slug] = $ingredient;
        }

        return $ingredients;
    }

    /**
     * Devuelve la bodega de la sede activa apropiada para un ingrediente
     * según su categoría: perecederos → cocina; otros → bodega seca.
     */
    private function warehouseForIngredient(string $branchKey, Ingredient $ingredient): Warehouse
    {
        $kitchenCategories = ['Cárnicos', 'Lácteos', 'Vegetales', 'Frutas', 'Panadería', 'Postres'];
        $goesToKitchen = in_array($ingredient->category, $kitchenCategories, true);

        return $this->warehousesByBranch[$branchKey][$goesToKitchen ? 'kitchen' : 'dry_storage'];
    }

    /**
     * WAC (current_cost) del insumo en la bodega de la sede que le corresponde
     * por categoría (#costeo-multibodega). El insumo ya no tiene costo global;
     * el costo vive por bodega en ingredient_stocks.
     */
    private function ingredientWac(Ingredient $ingredient, string $branchKey): float
    {
        $warehouse = $this->warehouseForIngredient($branchKey, $ingredient);

        $cost = IngredientStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('current_cost');

        return (float) ($cost ?? 0);
    }

    /**
     * @return array<string, array{name: string, category: string, unit: string, stock: float, min_stock: float, cost: float}>
     */
    private function pereiraIngredientsDef(): array
    {
        return [
            'papa-francesa' => ['name' => 'Papa francesa precortada', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 45.0, 'min_stock' => 15.0, 'cost' => 3200.0],
            'salchicha-americana' => ['name' => 'Salchicha americana', 'category' => 'Cárnicos', 'unit' => 'un', 'stock' => 240.0, 'min_stock' => 80.0, 'cost' => 850.0],
            'carne-desmechada' => ['name' => 'Carne desmechada', 'category' => 'Cárnicos', 'unit' => 'kg', 'stock' => 12.0, 'min_stock' => 4.0, 'cost' => 22000.0],
            'pollo-desmechado' => ['name' => 'Pollo desmechado', 'category' => 'Cárnicos', 'unit' => 'kg', 'stock' => 9.0, 'min_stock' => 3.0, 'cost' => 18000.0],
            'chorizo' => ['name' => 'Chorizo', 'category' => 'Cárnicos', 'unit' => 'un', 'stock' => 80.0, 'min_stock' => 30.0, 'cost' => 1800.0],
            'queso-fundido' => ['name' => 'Queso fundido', 'category' => 'Lácteos', 'unit' => 'kg', 'stock' => 8.0, 'min_stock' => 3.0, 'cost' => 19500.0],
            'maiz-tierno' => ['name' => 'Maíz tierno', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 6.0, 'min_stock' => 2.0, 'cost' => 5200.0],
            'pan-perro' => ['name' => 'Pan de perro caliente', 'category' => 'Panadería', 'unit' => 'un', 'stock' => 60.0, 'min_stock' => 24.0, 'cost' => 950.0],
            'tocineta' => ['name' => 'Tocineta ahumada', 'category' => 'Cárnicos', 'unit' => 'kg', 'stock' => 4.0, 'min_stock' => 1.5, 'cost' => 28000.0],
            'salsa-casa' => ['name' => 'Salsa de la casa', 'category' => 'Salsas', 'unit' => 'l', 'stock' => 8.0, 'min_stock' => 3.0, 'cost' => 8500.0],
            'limon' => ['name' => 'Limón Tahití', 'category' => 'Frutas', 'unit' => 'kg', 'stock' => 6.0, 'min_stock' => 2.0, 'cost' => 4500.0],
            'gaseosa-personal' => ['name' => 'Gaseosa personal 400 ml', 'category' => 'Bebidas', 'unit' => 'un', 'stock' => 120.0, 'min_stock' => 40.0, 'cost' => 1900.0],
            'cerveza-aguila' => ['name' => 'Cerveza Águila lata', 'category' => 'Bebidas', 'unit' => 'un', 'stock' => 96.0, 'min_stock' => 36.0, 'cost' => 3200.0],
        ];
    }

    /**
     * @return array<string, array{name: string, category: string, unit: string, stock: float, min_stock: float, cost: float}>
     */
    private function cartagoIngredientsDef(): array
    {
        return [
            'pan-brioche' => ['name' => 'Pan brioche', 'category' => 'Panadería', 'unit' => 'un', 'stock' => 80.0, 'min_stock' => 30.0, 'cost' => 1800.0],
            'carne-150g' => ['name' => 'Carne hamburguesa 150g', 'category' => 'Cárnicos', 'unit' => 'un', 'stock' => 100.0, 'min_stock' => 35.0, 'cost' => 4500.0],
            'pechuga-apanada' => ['name' => 'Pechuga de pollo apanada', 'category' => 'Cárnicos', 'unit' => 'un', 'stock' => 60.0, 'min_stock' => 24.0, 'cost' => 5200.0],
            'queso-tajado' => ['name' => 'Queso tajado', 'category' => 'Lácteos', 'unit' => 'kg', 'stock' => 6.0, 'min_stock' => 2.0, 'cost' => 17500.0],
            'lechuga' => ['name' => 'Lechuga crespa', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 3.0, 'min_stock' => 1.0, 'cost' => 6500.0],
            'tomate' => ['name' => 'Tomate chonto', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 5.0, 'min_stock' => 2.0, 'cost' => 4200.0],
            'cebolla' => ['name' => 'Cebolla cabezona', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 8.0, 'min_stock' => 3.0, 'cost' => 3800.0],
            'tocineta' => ['name' => 'Tocineta ahumada', 'category' => 'Cárnicos', 'unit' => 'kg', 'stock' => 3.0, 'min_stock' => 1.0, 'cost' => 28000.0],
            'salsa-bbq' => ['name' => 'Salsa BBQ', 'category' => 'Salsas', 'unit' => 'l', 'stock' => 4.0, 'min_stock' => 1.5, 'cost' => 12500.0],
            'chicharron' => ['name' => 'Chicharrón', 'category' => 'Cárnicos', 'unit' => 'kg', 'stock' => 5.0, 'min_stock' => 2.0, 'cost' => 26000.0],
            'chorizo' => ['name' => 'Chorizo', 'category' => 'Cárnicos', 'unit' => 'un', 'stock' => 70.0, 'min_stock' => 25.0, 'cost' => 1800.0],
            'papa-criolla' => ['name' => 'Papa criolla', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 6.0, 'min_stock' => 2.0, 'cost' => 4800.0],
            'arepa' => ['name' => 'Arepa', 'category' => 'Panadería', 'unit' => 'un', 'stock' => 80.0, 'min_stock' => 30.0, 'cost' => 850.0],
            'yuca' => ['name' => 'Yuca pelada', 'category' => 'Vegetales', 'unit' => 'kg', 'stock' => 8.0, 'min_stock' => 3.0, 'cost' => 3500.0],
            'jugo-fruta' => ['name' => 'Pulpa de fruta', 'category' => 'Frutas', 'unit' => 'kg', 'stock' => 6.0, 'min_stock' => 2.0, 'cost' => 8500.0],
            'brownie' => ['name' => 'Brownie de chocolate', 'category' => 'Postres', 'unit' => 'un', 'stock' => 30.0, 'min_stock' => 12.0, 'cost' => 4200.0],
            'helado-vainilla' => ['name' => 'Helado de vainilla', 'category' => 'Postres', 'unit' => 'l', 'stock' => 4.0, 'min_stock' => 1.5, 'cost' => 14500.0],
        ];
    }

    /**
     * Mapea cada ingrediente a su proveedor habitual.
     *
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedSupplierIngredients(string $branchKey, array $suppliers, array $ingredients): void
    {
        $map = $branchKey === 'pereira' ? [
            'papa-francesa' => 'frutiverduras',
            'salchicha-americana' => 'frigorifico',
            'carne-desmechada' => 'frigorifico',
            'pollo-desmechado' => 'frigorifico',
            'chorizo' => 'frigorifico',
            'queso-fundido' => 'lacteos',
            'maiz-tierno' => 'frutiverduras',
            'pan-perro' => 'panaderia',
            'tocineta' => 'frigorifico',
            'salsa-casa' => 'frutiverduras',
            'limon' => 'frutiverduras',
            'gaseosa-personal' => 'bebidas',
            'cerveza-aguila' => 'bebidas',
        ] : [
            'pan-brioche' => 'panaderia',
            'carne-150g' => 'frigorifico',
            'pechuga-apanada' => 'frigorifico',
            'queso-tajado' => 'lacteos',
            'lechuga' => 'frutiverduras',
            'tomate' => 'frutiverduras',
            'cebolla' => 'frutiverduras',
            'tocineta' => 'frigorifico',
            'salsa-bbq' => 'frutiverduras',
            'chicharron' => 'frigorifico',
            'chorizo' => 'frigorifico',
            'papa-criolla' => 'frutiverduras',
            'arepa' => 'panaderia',
            'yuca' => 'frutiverduras',
            'jugo-fruta' => 'frutiverduras',
            'brownie' => 'panaderia',
            'helado-vainilla' => 'lacteos',
        ];

        foreach ($map as $ingredientSlug => $supplierKey) {
            if (! isset($ingredients[$ingredientSlug], $suppliers[$supplierKey])) {
                continue;
            }

            SupplierIngredient::query()->updateOrCreate(
                [
                    'supplier_id' => $suppliers[$supplierKey]->id,
                    'ingredient_id' => $ingredients[$ingredientSlug]->id,
                ],
                [
                    'last_unit_cost' => $this->ingredientWac($ingredients[$ingredientSlug], $branchKey),
                    'last_purchased_at' => now()->subDays(rand(2, 14)),
                ],
            );
        }
    }

    /**
     * Recetas (BOM) por menu_item, con cantidades realistas. Permite a la app
     * calcular food_cost real y descontar stock al confirmar la orden.
     *
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedRecipes(string $companyNit, Branch $branch, string $branchKey, RestaurantMenu $menu, array $ingredients): void
    {
        $recipes = $branchKey === 'pereira' ? [
            'salchipapa-clasica' => [
                ['ing' => 'papa-francesa', 'qty' => 0.250, 'unit' => 'kg'],
                ['ing' => 'salchicha-americana', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'salsa-casa', 'qty' => 0.050, 'unit' => 'l'],
            ],
            'salchipapa-especial' => [
                ['ing' => 'papa-francesa', 'qty' => 0.300, 'unit' => 'kg'],
                ['ing' => 'salchicha-americana', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'carne-desmechada', 'qty' => 0.080, 'unit' => 'kg'],
                ['ing' => 'queso-fundido', 'qty' => 0.060, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.060, 'unit' => 'l'],
            ],
            'salchipapa-superpapa' => [
                ['ing' => 'papa-francesa', 'qty' => 0.350, 'unit' => 'kg'],
                ['ing' => 'salchicha-americana', 'qty' => 3, 'unit' => 'un'],
                ['ing' => 'carne-desmechada', 'qty' => 0.080, 'unit' => 'kg'],
                ['ing' => 'pollo-desmechado', 'qty' => 0.080, 'unit' => 'kg'],
                ['ing' => 'chorizo', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-fundido', 'qty' => 0.080, 'unit' => 'kg'],
                ['ing' => 'maiz-tierno', 'qty' => 0.060, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.080, 'unit' => 'l'],
            ],
            'mega-salchipapa' => [
                ['ing' => 'papa-francesa', 'qty' => 0.600, 'unit' => 'kg'],
                ['ing' => 'salchicha-americana', 'qty' => 4, 'unit' => 'un'],
                ['ing' => 'carne-desmechada', 'qty' => 0.150, 'unit' => 'kg'],
                ['ing' => 'pollo-desmechado', 'qty' => 0.150, 'unit' => 'kg'],
                ['ing' => 'queso-fundido', 'qty' => 0.150, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.150, 'unit' => 'l'],
            ],
            'perro-sencillo' => [
                ['ing' => 'pan-perro', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'salchicha-americana', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-fundido', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.040, 'unit' => 'l'],
            ],
            'perro-especial' => [
                ['ing' => 'pan-perro', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'salchicha-americana', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-fundido', 'qty' => 0.060, 'unit' => 'kg'],
                ['ing' => 'tocineta', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.050, 'unit' => 'l'],
            ],
            'papas-francesas' => [
                ['ing' => 'papa-francesa', 'qty' => 0.300, 'unit' => 'kg'],
                ['ing' => 'salsa-casa', 'qty' => 0.040, 'unit' => 'l'],
            ],
            'gaseosa-personal' => [
                ['ing' => 'gaseosa-personal', 'qty' => 1, 'unit' => 'un'],
            ],
            'limonada-natural' => [
                ['ing' => 'limon', 'qty' => 0.150, 'unit' => 'kg'],
            ],
            'cerveza-nacional' => [
                ['ing' => 'cerveza-aguila', 'qty' => 1, 'unit' => 'un'],
            ],
        ] : [
            'hamburguesa-clasica' => [
                ['ing' => 'pan-brioche', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'carne-150g', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-tajado', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'lechuga', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'tomate', 'qty' => 0.040, 'unit' => 'kg'],
            ],
            'hamburguesa-bbq' => [
                ['ing' => 'pan-brioche', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'carne-150g', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-tajado', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'tocineta', 'qty' => 0.040, 'unit' => 'kg'],
                ['ing' => 'cebolla', 'qty' => 0.040, 'unit' => 'kg'],
                ['ing' => 'salsa-bbq', 'qty' => 0.050, 'unit' => 'l'],
            ],
            'hamburguesa-pollo' => [
                ['ing' => 'pan-brioche', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'pechuga-apanada', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-tajado', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'lechuga', 'qty' => 0.030, 'unit' => 'kg'],
            ],
            'perro-ranchero' => [
                ['ing' => 'pan-brioche', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'chorizo', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'queso-tajado', 'qty' => 0.030, 'unit' => 'kg'],
                ['ing' => 'salsa-bbq', 'qty' => 0.040, 'unit' => 'l'],
            ],
            'picada-personal' => [
                ['ing' => 'chicharron', 'qty' => 0.150, 'unit' => 'kg'],
                ['ing' => 'chorizo', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'papa-criolla', 'qty' => 0.150, 'unit' => 'kg'],
                ['ing' => 'arepa', 'qty' => 1, 'unit' => 'un'],
            ],
            'picada-para-2' => [
                ['ing' => 'chicharron', 'qty' => 0.300, 'unit' => 'kg'],
                ['ing' => 'chorizo', 'qty' => 2, 'unit' => 'un'],
                ['ing' => 'papa-criolla', 'qty' => 0.300, 'unit' => 'kg'],
                ['ing' => 'yuca', 'qty' => 0.250, 'unit' => 'kg'],
                ['ing' => 'arepa', 'qty' => 2, 'unit' => 'un'],
            ],
            'aros-cebolla' => [
                ['ing' => 'cebolla', 'qty' => 0.150, 'unit' => 'kg'],
            ],
            'yuca-frita' => [
                ['ing' => 'yuca', 'qty' => 0.250, 'unit' => 'kg'],
            ],
            'jugo-natural' => [
                ['ing' => 'jugo-fruta', 'qty' => 0.150, 'unit' => 'kg'],
            ],
            'brownie-helado' => [
                ['ing' => 'brownie', 'qty' => 1, 'unit' => 'un'],
                ['ing' => 'helado-vainilla', 'qty' => 0.080, 'unit' => 'l'],
            ],
        ];

        foreach ($recipes as $menuItemId => $lines) {
            foreach ($lines as $line) {
                if (! isset($ingredients[$line['ing']])) {
                    continue;
                }

                $ingredient = $ingredients[$line['ing']];
                $warehouse = $this->warehouseForIngredient($branchKey, $ingredient);

                Recipe::query()->updateOrCreate(
                    [
                        'company_nit' => $companyNit,
                        'menu_item_id' => $menuItemId,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'menu_id' => $menu->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => $line['qty'],
                        'unit' => $line['unit'],
                    ],
                );
            }
        }
    }

    /**
     * Crea órdenes de compra históricas (8 semanas, ~1 PO por semana por
     * proveedor con ítems de su rubro). Las POs en estado received generan
     * ingredient_movements tipo 'entry' que actualizan stock + WAC.
     *
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedPurchaseOrders(
        string $companyNit,
        Branch $branch,
        string $branchKey,
        array $suppliers,
        array $ingredients,
        User $admin,
    ): void {
        $supplierToIngredients = $this->supplierToIngredientsMap($branchKey);
        $weeks = 8;

        foreach ($supplierToIngredients as $supplierKey => $ingredientSlugs) {
            if (! isset($suppliers[$supplierKey])) {
                continue;
            }

            for ($week = 1; $week <= $weeks; $week++) {
                $expectedDate = now()->subWeeks($week)->next(Carbon::TUESDAY)->toDateString();
                $receivedAt = Carbon::parse($expectedDate)->setTime(9, 30);
                $code = sprintf('PO-%s-%s-%02d', strtoupper(substr($branchKey, 0, 3)), strtoupper(substr($supplierKey, 0, 3)), $week);

                $items = [];
                $subtotal = 0.0;
                $taxAmount = 0.0;

                foreach ($ingredientSlugs as $ingSlug) {
                    if (! isset($ingredients[$ingSlug])) {
                        continue;
                    }

                    $ing = $ingredients[$ingSlug];
                    $quantity = $this->purchaseQuantityFor($ingSlug, $branchKey);
                    $unitCost = $this->ingredientWac($ing, $branchKey) * (1 + (rand(-3, 3) / 100));
                    $unitCost = round($unitCost, 2);
                    $lineSubtotal = round($quantity * $unitCost, 2);
                    $taxRate = $this->purchaseTaxRateFor($ing->category);
                    $lineTax = round($lineSubtotal * $taxRate / 100, 2);
                    $lineTotal = round($lineSubtotal + $lineTax, 2);

                    $items[] = [
                        'ingredient' => $ing,
                        'description' => $ing->name,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineTotal,
                    ];

                    $subtotal += $lineSubtotal;
                    $taxAmount += $lineTax;
                }

                if ($items === []) {
                    continue;
                }

                $total = round($subtotal + $taxAmount, 2);

                $po = PurchaseOrder::query()->updateOrCreate(
                    ['company_nit' => $companyNit, 'code' => $code],
                    [
                        'supplier_id' => $suppliers[$supplierKey]->id,
                        'status' => 'received',
                        'expected_date' => $expectedDate,
                        'received_date' => $receivedAt,
                        'paid_date' => $receivedAt->copy()->addDays($suppliers[$supplierKey]->payment_terms_days ?? 0),
                        'subtotal' => round($subtotal, 2),
                        'tax_amount' => round($taxAmount, 2),
                        'total' => $total,
                        'payment_method' => 'transfer',
                        'payment_reference' => sprintf('PAY-%s-%s', strtoupper(substr($supplierKey, 0, 3)), $code),
                        'pending_supplier_refund' => false,
                        'notes' => 'Compra semanal habitual.',
                        'created_by' => $admin->id,
                        'received_by' => $admin->id,
                        'paid_by' => $admin->id,
                    ],
                );

                PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->delete();
                IngredientMovement::query()
                    ->where('company_nit', $companyNit)
                    ->where('reference', $code)
                    ->delete();

                foreach ($items as $line) {
                    $warehouse = $this->warehouseForIngredient($branchKey, $line['ingredient']);

                    PurchaseOrderItem::query()->create([
                        'purchase_order_id' => $po->id,
                        'ingredient_id' => $line['ingredient']->id,
                        'warehouse_id' => $warehouse->id,
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                    ]);

                    IngredientMovement::query()->create([
                        'company_nit' => $companyNit,
                        'warehouse_id' => $warehouse->id,
                        'ingredient_id' => $line['ingredient']->id,
                        'type' => 'entry',
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'reference' => $code,
                        'actor_id' => $admin->id,
                        'created_at' => $receivedAt,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function supplierToIngredientsMap(string $branchKey): array
    {
        if ($branchKey === 'pereira') {
            return [
                'frutiverduras' => ['papa-francesa', 'maiz-tierno', 'salsa-casa', 'limon'],
                'frigorifico' => ['salchicha-americana', 'carne-desmechada', 'pollo-desmechado', 'chorizo', 'tocineta'],
                'lacteos' => ['queso-fundido'],
                'panaderia' => ['pan-perro'],
                'bebidas' => ['gaseosa-personal', 'cerveza-aguila'],
            ];
        }

        return [
            'panaderia' => ['pan-brioche', 'arepa', 'brownie'],
            'frigorifico' => ['carne-150g', 'pechuga-apanada', 'tocineta', 'chicharron', 'chorizo'],
            'lacteos' => ['queso-tajado', 'helado-vainilla'],
            'frutiverduras' => ['lechuga', 'tomate', 'cebolla', 'salsa-bbq', 'papa-criolla', 'yuca', 'jugo-fruta'],
        ];
    }

    /**
     * Cantidad de compra semanal por ingrediente. Calibrada para que
     * stock_inicial + 8 entries cubra el consumo de 60 días con un buffer
     * razonable (no se vacía pero tampoco se acumula excesivamente).
     */
    private function purchaseQuantityFor(string $ingredientSlug, string $branchKey): float
    {
        $pereira = [
            'papa-francesa' => 65.0,
            'salchicha-americana' => 350.0,
            'carne-desmechada' => 12.0,
            'pollo-desmechado' => 10.0,
            'chorizo' => 60.0,
            'queso-fundido' => 12.0,
            'maiz-tierno' => 4.0,
            'pan-perro' => 80.0,
            'tocineta' => 4.0,
            'salsa-casa' => 16.0,
            'limon' => 8.0,
            'gaseosa-personal' => 200.0,
            'cerveza-aguila' => 96.0,
        ];

        $cartago = [
            'pan-brioche' => 150.0,
            'carne-150g' => 150.0,
            'pechuga-apanada' => 80.0,
            'queso-tajado' => 8.0,
            'lechuga' => 4.0,
            'tomate' => 6.0,
            'cebolla' => 8.0,
            'tocineta' => 4.0,
            'salsa-bbq' => 6.0,
            'chicharron' => 12.0,
            'chorizo' => 60.0,
            'papa-criolla' => 14.0,
            'arepa' => 120.0,
            'yuca' => 16.0,
            'jugo-fruta' => 18.0,
            'brownie' => 40.0,
            'helado-vainilla' => 6.0,
        ];

        $map = $branchKey === 'pereira' ? $pereira : $cartago;

        return $map[$ingredientSlug] ?? 4.0;
    }

    /**
     * IVA habitual en proveedores colombianos:
     *  - Cárnicos básicos sin valor agregado: 0%
     *  - Vegetales / frutas frescas: 0%
     *  - Lácteos / panadería: 0% para bienes de la canasta
     *  - Bebidas embotelladas / salsas industriales / postres: 19%
     *  - Cárnicos procesados (chorizo, salchicha, tocineta): 19%
     */
    private function purchaseTaxRateFor(string $category): float
    {
        return match ($category) {
            'Bebidas', 'Salsas', 'Postres' => 19.0,
            'Cárnicos' => 19.0,
            default => 0.0,
        };
    }

    /**
     * Recalcula `ingredient_stocks.quantity` por (ingrediente, bodega) como
     * SUM(movements.quantity) filtrado por warehouse_id. Mantiene los stocks
     * coherentes tras el seed: stock inicial + entries de compras +
     * consumos de ventas. Para el caso de TRANSFER, cada movimiento ya
     * lleva el signo correcto en su warehouse_id correspondiente.
     *
     * El WAC (current_cost) global del insumo se conserva del valor inicial.
     *
     * @param  array<string, Ingredient>  $ingredients
     */
    private function recalculateIngredientStocks(array $ingredients, string $branchKey): void
    {
        $warehouses = $this->warehousesByBranch[$branchKey];
        $warehouseIds = array_map(fn (Warehouse $w) => $w->id, $warehouses);

        foreach ($ingredients as $ingredient) {
            $sums = DB::table('ingredient_movements')
                ->where('ingredient_id', $ingredient->id)
                ->whereIn('warehouse_id', $warehouseIds)
                ->groupBy('warehouse_id')
                ->select('warehouse_id', DB::raw('SUM(quantity) as total'))
                ->pluck('total', 'warehouse_id');

            foreach ($warehouseIds as $warehouseId) {
                $total = (float) ($sums[$warehouseId] ?? 0);
                $quantity = max($total, 0);

                IngredientStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('warehouse_id', $warehouseId)
                    ->update([
                        'quantity' => $quantity,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Genera ingredient_movements tipo 'sale_consumption' agregados por día,
     * inferidos del volumen de órdenes históricas. Usa una aproximación
     * promedio para no recorrer cada item — el detalle real lo calcula
     * RecipeCostService en runtime, pero el seeder necesita movimientos
     * para que los reportes de inventario muestren consumos.
     *
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedSaleConsumption(string $companyNit, Branch $branch, string $branchKey, array $ingredients): void
    {
        $startDate = now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);

        // Consumo aproximado promedio por día (inferido del volumen base de cada sede).
        $dailyBaseline = $branchKey === 'pereira' ? 30 : 22;

        // Por simplicidad, registramos un movimiento "sale_consumption" agregado
        // diario por ingrediente, con cantidad proporcional al volumen del día.
        // No es un detalle item-by-item; sirve para alimentar reportes y validar
        // que los stocks siguen positivos al final.
        $consumptionRatio = $branchKey === 'pereira' ? [
            'papa-francesa' => 0.250,
            'salchicha-americana' => 1.5,
            'salsa-casa' => 0.060,
            'queso-fundido' => 0.040,
            'gaseosa-personal' => 0.4,
        ] : [
            'pan-brioche' => 0.6,
            'carne-150g' => 0.5,
            'queso-tajado' => 0.040,
            'lechuga' => 0.020,
            'jugo-fruta' => 0.080,
        ];

        for ($dayIndex = 0; $dayIndex < self::HISTORY_DAYS; $dayIndex++) {
            $date = $startDate->copy()->addDays($dayIndex);
            if ($date->dayOfWeek === Carbon::MONDAY) {
                continue;
            }

            $multiplier = match ($date->dayOfWeek) {
                Carbon::FRIDAY, Carbon::SATURDAY => 1.4,
                Carbon::SUNDAY => 1.1,
                default => 1.0,
            };

            $orders = (int) round($dailyBaseline * $multiplier);
            $consumedAt = $date->copy()->setTime(23, 30);
            $reference = sprintf('SALE-%s-%s', strtoupper(substr($branchKey, 0, 3)), $date->format('Ymd'));

            foreach ($consumptionRatio as $ingSlug => $perOrder) {
                if (! isset($ingredients[$ingSlug])) {
                    continue;
                }

                $qty = round($orders * $perOrder, 3);
                if ($qty <= 0) {
                    continue;
                }

                $ingredient = $ingredients[$ingSlug];
                $warehouse = $this->warehouseForIngredient($branchKey, $ingredient);

                IngredientMovement::query()->firstOrCreate(
                    [
                        'company_nit' => $companyNit,
                        'ingredient_id' => $ingredient->id,
                        'reference' => $reference,
                        'type' => 'sale_consumption',
                    ],
                    [
                        'warehouse_id' => $warehouse->id,
                        'quantity' => -1 * $qty,
                        'unit_cost' => null,
                        'created_at' => $consumedAt,
                    ],
                );
            }
        }
    }

    /**
     * @param  list<string>  $readableSlugs
     * @param  list<string>  $creatableSlugs
     * @param  list<string>  $updatableSlugs
     * @param  list<string>  $deletableSlugs
     */
    private function syncRolePermissions(
        CompanyRole $role,
        array $readableSlugs = [],
        array $creatableSlugs = [],
        array $updatableSlugs = [],
        array $deletableSlugs = []
    ): void {
        $implicitReadableSlugs = array_values(array_unique(array_merge(
            $readableSlugs,
            $creatableSlugs,
            $updatableSlugs,
            $deletableSlugs,
        )));

        Feature::query()->each(function (Feature $feature) use ($role, $implicitReadableSlugs, $creatableSlugs, $updatableSlugs, $deletableSlugs): void {
            CompanyRolePermission::updateOrCreate(
                ['company_role_id' => $role->id, 'feature_id' => $feature->id],
                [
                    'can_create' => in_array($feature->slug, $creatableSlugs, true),
                    'can_read' => in_array($feature->slug, $implicitReadableSlugs, true),
                    'can_update' => in_array($feature->slug, $updatableSlugs, true),
                    'can_delete' => in_array($feature->slug, $deletableSlugs, true),
                ]
            );
        });
    }

    /**
     * Genera warehouse_stock_snapshots para los últimos 60 días, reconstruyendo
     * el stock por (warehouse, ingredient, día) a partir de los movements.
     *
     * Lógica:
     *  - Por cada (warehouse, ingredient) ordena movements por fecha.
     *  - Itera día por día acumulando quantity.
     *  - Persiste un snapshot por (warehouse, ingredient, día) con unit_cost
     *    del WAC actual del ingrediente (aproximación suficiente para reportes
     *    históricos del seeder).
     *
     * Cubre la query de WarehouseStockHistoryService::seriesBetween al instante
     * sin necesidad de correr `inventory:snapshot-daily --backfill=60`.
     */
    private function generateHistoricalWarehouseSnapshots(string $companyNit): void
    {
        $startDate = now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);
        $endDate = now()->startOfDay();

        // Pares (warehouse_id, ingredient_id) con movements en el rango.
        // (#costeo-multibodega) La bodega es company-scoped: la sede sale del
        // pivot (primaria = default/más antigua) y el costo del WAC por bodega
        // en ingredient_stocks.
        $primaryBranch = DB::table('branch_warehouses')
            ->select('warehouse_id', DB::raw('(array_agg(branch_id ORDER BY is_default DESC, created_at ASC))[1] as branch_id'))
            ->groupBy('warehouse_id');

        $pairs = DB::table('ingredient_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('ingredients as i', 'i.id', '=', 'm.ingredient_id')
            ->joinSub($primaryBranch, 'pb', 'pb.warehouse_id', '=', 'm.warehouse_id')
            ->leftJoin('ingredient_stocks as st', function ($join) {
                $join->on('st.ingredient_id', '=', 'm.ingredient_id')
                    ->on('st.warehouse_id', '=', 'm.warehouse_id');
            })
            ->where('m.company_nit', $companyNit)
            ->select('m.warehouse_id', 'm.ingredient_id', 'pb.branch_id', DB::raw('COALESCE(st.current_cost, 0) as current_cost'))
            ->groupBy('m.warehouse_id', 'm.ingredient_id', 'pb.branch_id', 'st.current_cost')
            ->get();

        // Para cada par, obtenemos su acumulado por día.
        $bulk = [];
        $now = now();

        foreach ($pairs as $pair) {
            $movements = DB::table('ingredient_movements')
                ->where('warehouse_id', $pair->warehouse_id)
                ->where('ingredient_id', $pair->ingredient_id)
                ->where('created_at', '<=', $endDate->copy()->endOfDay())
                ->orderBy('created_at')
                ->select('created_at', 'quantity')
                ->get();

            $cumulative = 0.0;
            $movIndex = 0;
            $movsArr = $movements->all();

            for ($cursor = $startDate->copy(); $cursor->lessThanOrEqualTo($endDate); $cursor->addDay()) {
                $endOfDay = $cursor->copy()->endOfDay();

                while ($movIndex < count($movsArr)
                    && Carbon::parse($movsArr[$movIndex]->created_at)->lessThanOrEqualTo($endOfDay)) {
                    $cumulative += (float) $movsArr[$movIndex]->quantity;
                    $movIndex++;
                }

                $quantity = max($cumulative, 0);
                $unitCost = (float) $pair->current_cost;
                $lineValue = round($quantity * $unitCost, 2);

                $bulk[] = [
                    'id' => (string) Str::uuid7(),
                    'company_nit' => $companyNit,
                    'branch_id' => $pair->branch_id,
                    'warehouse_id' => $pair->warehouse_id,
                    'ingredient_id' => $pair->ingredient_id,
                    'snapshot_date' => $cursor->toDateString(),
                    'quantity' => number_format($quantity, 3, '.', ''),
                    'unit_cost' => number_format($unitCost, 2, '.', ''),
                    'line_value' => number_format($lineValue, 2, '.', ''),
                    'created_at' => $now,
                ];

                // Flush por chunks para no quemar memoria.
                if (count($bulk) >= 500) {
                    DB::table('warehouse_stock_snapshots')->upsert(
                        $bulk,
                        ['warehouse_id', 'ingredient_id', 'snapshot_date'],
                        ['quantity', 'unit_cost', 'line_value', 'created_at'],
                    );
                    $bulk = [];
                }
            }
        }

        if ($bulk !== []) {
            DB::table('warehouse_stock_snapshots')->upsert(
                $bulk,
                ['warehouse_id', 'ingredient_id', 'snapshot_date'],
                ['quantity', 'unit_cost', 'line_value', 'created_at'],
            );
        }
    }
}
