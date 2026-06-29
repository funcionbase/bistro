<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
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
        $allDone = collect($steps)->every(fn (array $s) => $s['completed']);

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
     * @return list<array{id: string, title: string, description: string, url: string, completed: bool}>
     */
    private function buildSteps(string $nit, ?Company $company, Collection $branches): array
    {
        $branchIds = $branches->pluck('id');
        $hasAddressedBranch = $branches->whereNotNull('address')->isNotEmpty();

        $hasCashRegister = $branchIds->isNotEmpty()
            && CashRegister::query()->whereIn('branch_id', $branchIds)->whereNull('archived_at')->exists();

        $hasTables = $branchIds->isNotEmpty()
            && Table::query()->whereIn('branch_id', $branchIds)->whereNull('archived_at')->exists();

        return [
            [
                'id' => 'company_info',
                'title' => 'Información de empresa',
                'description' => 'Agrega el nombre, logo y horario de atención',
                'url' => '/company/preferences',
                'completed' => $company?->logo_path !== null,
            ],
            [
                'id' => 'branch_setup',
                'title' => 'Configura tu sede',
                'description' => 'Personaliza la dirección y datos de tu local',
                'url' => '/company/branches',
                'completed' => $hasAddressedBranch,
            ],
            [
                'id' => 'cash_register',
                'title' => 'Agrega tu caja registradora',
                'description' => 'Crea la caja de tu sede para empezar a cobrar',
                'url' => '/company/branches',
                'completed' => $hasCashRegister,
            ],
            [
                'id' => 'menu',
                'title' => 'Crea tu menú',
                'description' => 'Agrega los productos que vas a vender',
                'url' => '/menu',
                'completed' => RestaurantMenu::query()->where('company_nit', $nit)->exists(),
            ],
            [
                'id' => 'employees',
                'title' => 'Agrega empleados',
                'description' => 'Invita a tu equipo y asígnales roles',
                'url' => '/employees',
                'completed' => Employee::query()->where('company_nit', $nit)->whereNull('archived_at')->exists(),
            ],
            [
                'id' => 'tables',
                'title' => 'Configura las mesas',
                'description' => 'Organiza el salón (opcional si haces domicilios)',
                'url' => '/orders/tables?tab=config',
                'completed' => $hasTables,
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
