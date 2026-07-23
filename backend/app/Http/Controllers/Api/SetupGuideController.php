<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use App\Models\Employee;
use App\Models\RestaurantMenu;
use App\Models\Table;
use App\Models\User;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Guía de configuración inicial para restaurantes nuevos.
 *
 * Los 6 pasos se auto-detectan desde el estado real del DB — no hay marcado
 * manual ni localStorage. Solo `setup_guide_dismissed_at` se persiste en BD.
 *
 * Visible únicamente para Propietario y Administrador (is_system=true,
 * excluyendo Empleado). Sin permiso nuevo en el catálogo.
 */
class SetupGuideController extends Controller
{
    public function __construct(
        private readonly FeaturePermissionService $permissions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit', '');

        if (! $this->isOwnerOrAdmin($request, $nit)) {
            return response()->json(['dismissed' => true, 'allDone' => false, 'steps' => []], 200);
        }

        $company = Company::query()
            ->where('nit', $nit)
            ->first(['logo_path', 'setup_guide_dismissed_at']);

        $dismissed = $company?->setup_guide_dismissed_at !== null;

        $branches = Branch::query()
            ->where('company_nit', $nit)
            ->whereNull('archived_at')
            ->get(['id', 'address']);

        $steps = $this->buildSteps($nit, $company, $branches);
        // El "¡Listo!" depende solo de los pasos esenciales (dirección, caja,
        // menú). Los opcionales (marca, equipo, mesas) no bloquean: un local
        // solo-domicilios o de un único dueño debe poder llegar al estado
        // completo sin invitar empleados ni configurar mesas.
        $allDone = collect($steps)
            ->reject(fn (array $s) => $s['optional'])
            ->every(fn (array $s) => $s['completed']);

        return response()->json(compact('steps', 'dismissed', 'allDone'));
    }

    public function dismiss(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit', '');

        if (! $this->isOwnerOrAdmin($request, $nit)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        Company::query()
            ->where('nit', $nit)
            ->whereNull('setup_guide_dismissed_at')
            ->update(['setup_guide_dismissed_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array{id: string, title: string, description: string, url: string, completed: bool, optional: bool}>
     */
    private function buildSteps(string $nit, ?Company $company, Collection $branches): array
    {
        $branchIds = $branches->pluck('id');
        $hasAddressedBranch = $branches->whereNotNull('address')->isNotEmpty();

        $hasCashRegister = $branchIds->isNotEmpty()
            && CashRegister::query()->whereIn('branch_id', $branchIds)->whereNull('archived_at')->exists();

        $hasTables = $branchIds->isNotEmpty()
            && Table::query()->whereIn('branch_id', $branchIds)->whereNull('archived_at')->exists();

        // El owner siempre tiene su propio Employee (creado en el enrolamiento),
        // así que `> 1` = ya invitó a alguien real del equipo. Con `exists()`
        // este paso nacía verde para todos y nunca empujaba a sumar al equipo.
        $hasTeam = Employee::query()->where('company_nit', $nit)->whereNull('archived_at')->count() > 1;

        // Orden = prioridad. Primero los 3 esenciales que hacen operar el
        // restaurante (cobrar un pedido); luego los opcionales que mejoran la
        // operación pero no la bloquean.
        return [
            [
                'id' => 'branch_setup',
                'title' => 'Completa la dirección de tu sede',
                'description' => 'Aparece en tus recibos y facturas, y ubica tu local para los domicilios.',
                'url' => '/company/branches',
                'completed' => $hasAddressedBranch,
                'optional' => false,
            ],
            [
                'id' => 'cash_register',
                'title' => 'Abre tu caja registradora',
                'description' => 'Sin caja no puedes cobrar ni cerrar pedidos: ahí queda registrada cada venta del día.',
                'url' => '/company/branches',
                'completed' => $hasCashRegister,
                'optional' => false,
            ],
            [
                'id' => 'menu',
                'title' => 'Crea tu menú',
                'description' => 'Agrega los productos y precios que vas a vender. Sin menú no hay nada que cobrar.',
                'url' => '/menu',
                'completed' => RestaurantMenu::query()->where('company_nit', $nit)->exists(),
                'optional' => false,
            ],
            [
                'id' => 'company_info',
                'title' => 'Personaliza tu marca',
                'description' => 'Sube tu logo. Se muestra en los recibos y en el portal de tus clientes.',
                'url' => '/company/preferences',
                'completed' => $company?->logo_path !== null,
                'optional' => true,
            ],
            [
                'id' => 'whatsapp',
                'title' => 'Conecta tu WhatsApp',
                'description' => 'Atiende a tus clientes por WhatsApp desde el panel, sin usar el celular del dueño.',
                'url' => '/company/whatsapp',
                'completed' => CompanyWhatsappAccount::query()
                    ->where('company_nit', $nit)
                    ->where('status', 'connected')
                    ->exists(),
                'optional' => true,
            ],
            [
                'id' => 'employees',
                'title' => 'Invita a tu equipo',
                'description' => 'Suma meseros, cocina o cajeros con sus permisos. Si trabajas solo, puedes saltarlo.',
                'url' => '/employees',
                'completed' => $hasTeam,
                'optional' => true,
            ],
            [
                'id' => 'tables',
                'title' => 'Organiza el salón',
                'description' => 'Crea las mesas si atiendes en sitio. Si solo haces domicilios, no lo necesitas.',
                'url' => '/orders/tables?tab=config',
                'completed' => $hasTables,
                'optional' => true,
            ],
        ];
    }

    private function isOwnerOrAdmin(Request $request, string $nit): bool
    {
        if ($nit === '') {
            return false;
        }

        $payload = $request->attributes->get('jwt_payload');
        $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;
        $user = $userId !== null ? User::find((string) $userId) : null;

        if ($user === null) {
            return false;
        }

        $resolved = $this->permissions->resolveRoleAndPermissions($user, $nit);
        $role = $resolved['role'];

        if (! ($role['is_system'] ?? false)) {
            return false;
        }

        $employeeName = strtolower((string) config('roles.role_names.employee', 'Empleado'));

        return strtolower((string) ($role['name'] ?? '')) !== $employeeName;
    }
}
