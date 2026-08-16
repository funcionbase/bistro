<?php

namespace Database\Seeders;

use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\Feature;
use App\Models\PermissionTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Backfill de las features de gestión de colaboradores a empresas EXISTENTES.
 *
 * Las migraciones agregan las features al catálogo y `PermissionTemplateSeeder`
 * actualiza los templates owner/admin/employee. Pero los `company_role_permissions`
 * por empresa NO se generan automáticamente al sembrar templates — hay que
 * proyectarlos sobre cada rol del sistema (`is_system=true`).
 *
 * Idempotente: usa `firstOrCreate` por `(company_role_id, feature_id)`. Correrlo
 * varias veces no duplica filas y permite reaplicar si se agregan features nuevas
 * dentro del mismo grupo.
 */
class EmployeesFeatureBackfillSeeder extends Seeder
{
    private const TARGET_SLUGS = [
        'employees.read',
        'employees.create',
        'employees.update',
        'employees.delete',
        'employees.view_salary',
        'shifts.read',
        'shifts.manage',
        'shifts.suggest',
        'workforce.reports',
        'workforce.settings',
    ];

    public function run(): void
    {
        $features = Feature::whereIn('slug', self::TARGET_SLUGS)->get()->keyBy('slug');

        if ($features->isEmpty()) {
            Log::warning('EmployeesFeatureBackfillSeeder: no features encontrados. Corre primero FeatureSeeder.');

            return;
        }

        $systemRoles = CompanyRole::where('is_system', true)->get();

        foreach ($systemRoles as $role) {
            $roleType = $this->resolveRoleType($role->name);
            if ($roleType === null) {
                continue;
            }

            foreach ($features as $feature) {
                $template = PermissionTemplate::where('role_type', $roleType)
                    ->where('feature_id', $feature->id)
                    ->first();

                if ($template === null) {
                    continue;
                }

                CompanyRolePermission::firstOrCreate(
                    [
                        'company_role_id' => $role->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'can_create' => $template->can_create,
                        'can_read' => $template->can_read,
                        'can_update' => $template->can_update,
                        'can_delete' => $template->can_delete,
                    ]
                );
            }
        }
    }

    /**
     * Mapea el nombre legible del rol del sistema al role_type del template.
     * "Propietario" → owner, "Administrador" → admin, "Empleado" → employee.
     */
    private function resolveRoleType(string $roleName): ?string
    {
        $names = config('roles.role_names', []);
        $reverse = array_flip($names);

        return $reverse[$roleName] ?? null;
    }
}
