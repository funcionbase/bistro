<?php

namespace Database\Seeders;

use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\Feature;
use App\Models\PermissionTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Backfill de las features KDS (#115) a empresas existentes.
 *
 * `FeatureSeeder` agrega `kds.view_station`, `kds.operate_station` y
 * `kds.manage_stations` al catálogo y `PermissionTemplateSeeder` define
 * los defaults por role_type. Pero los `company_role_permissions` por
 * empresa NO se generan automáticamente — hay que proyectarlos sobre
 * cada rol del sistema (`is_system=true`) ya existente.
 *
 * Para los roles operativos no-system (waiter/cook/cashier/manager/etc.),
 * el comando `php artisan roles:sync-templates` aplica los templates.
 *
 * Idempotente: `firstOrCreate` por `(company_role_id, feature_id)`.
 */
class KdsFeatureBackfillSeeder extends Seeder
{
    private const TARGET_SLUGS = [
        'kds.read',
        'kds.create',
        'kds.update',
        'kds.delete',
        'kds_stations.read',
        'kds_stations.create',
        'kds_stations.update',
        'kds_stations.delete',
    ];

    public function run(): void
    {
        $features = Feature::whereIn('slug', self::TARGET_SLUGS)->get()->keyBy('slug');

        if ($features->isEmpty()) {
            Log::warning('KdsFeatureBackfillSeeder: no features encontrados. Corre primero FeatureSeeder.');

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

    private function resolveRoleType(string $roleName): ?string
    {
        $names = config('roles.role_names', []);
        $reverse = array_flip($names);

        return $reverse[$roleName] ?? null;
    }
}
