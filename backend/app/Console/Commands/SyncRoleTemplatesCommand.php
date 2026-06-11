<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\PermissionTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o sincroniza) los roles operativos en una o varias empresas
 * existentes.
 *
 * Roles que crea (todos `is_system=false` — el owner los puede
 * renombrar/eliminar):
 *  - Flujo mesa QR (#191 F7): waiter, cook, cashier.
 *  - Administrativos (#215 F4): manager, accountant, marketing,
 *    inventory_manager, supervisor.
 *
 * Sus permisos se leen de la tabla `permission_templates` (sembrada vía
 * `PermissionTemplateSeeder`).
 *
 * Idempotente: si el rol ya existe (buscando por `name` localizado + nit),
 * se omite creación y se reconcilian los permisos al template actual.
 * Útil cuando se ajustan los templates y se quiere propagar a las empresas
 * existentes sin tocar a mano.
 *
 * N-instance safe: ejecutar manualmente o desde un schedule con
 * `->onOneServer()->withoutOverlapping()` — recomendado mensual o en
 * deploys que tocan templates.
 */
class SyncRoleTemplatesCommand extends Command
{
    /** @var string */
    protected $signature = 'roles:sync-templates
        {--company=* : NIT(s) específicos a sincronizar. Si se omite, todas las empresas.}
        {--role=waiter,cook,cashier,manager,accountant,marketing,inventory_manager,supervisor : Tipos de rol a sincronizar (csv).}
        {--dry-run : Muestra cambios sin escribir.}';

    /** @var string */
    protected $description = 'Sincroniza los roles operativos (waiter/cook/cashier + manager/accountant/marketing/inventory_manager/supervisor) en empresas existentes según PermissionTemplate.';

    public function handle(): int
    {
        $nitsOption = $this->option('company');
        $roleTypes = explode(',', (string) $this->option('role'));
        $roleTypes = array_filter(array_map('trim', $roleTypes));
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->when(! empty($nitsOption), fn ($q) => $q->whereIn('nit', $nitsOption))
            ->get(['nit', 'commercial_name']);

        if ($companies->isEmpty()) {
            $this->warn('No se encontraron empresas para procesar.');

            return self::SUCCESS;
        }

        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($companies as $company) {
            $this->line("→ <fg=cyan>{$company->commercial_name}</> ({$company->nit})");
            foreach ($roleTypes as $roleType) {
                $result = $this->syncRoleForCompany($company->nit, $roleType, $dryRun);
                $totals[$result]++;
                $this->line("    {$roleType}: {$result}");
            }
        }

        $this->info(sprintf(
            'Listo. Creados: %d · Actualizados: %d · Sin cambios: %d%s',
            $totals['created'],
            $totals['updated'],
            $totals['unchanged'],
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function syncRoleForCompany(string $companyNit, string $roleType, bool $dryRun): string
    {
        $roleName = config("roles.role_names.{$roleType}");
        $roleColor = config("roles.role_colors.{$roleType}");

        if (! is_string($roleName)) {
            $this->warn("No hay nombre configurado para role_type={$roleType}");

            return 'unchanged';
        }

        $templates = PermissionTemplate::query()
            ->where('role_type', $roleType)
            ->get();

        if ($templates->isEmpty()) {
            $this->warn("No hay templates para role_type={$roleType}");

            return 'unchanged';
        }

        $existing = CompanyRole::query()
            ->where('company_nit', $companyNit)
            ->where('name', $roleName)
            ->first();

        $expectedPerms = $templates->mapWithKeys(fn (PermissionTemplate $t) => [
            $t->feature_id => [
                'can_create' => (bool) $t->can_create,
                'can_read' => (bool) $t->can_read,
                'can_update' => (bool) $t->can_update,
                'can_delete' => (bool) $t->can_delete,
            ],
        ])->all();

        if ($existing === null) {
            if ($dryRun) {
                return 'created';
            }

            DB::transaction(function () use ($companyNit, $roleName, $roleColor, $expectedPerms) {
                /** @var CompanyRole $role */
                $role = CompanyRole::create([
                    'company_nit' => $companyNit,
                    'name' => $roleName,
                    'description' => "Rol {$roleName} (sincronizado por roles:sync-templates).",
                    'is_system' => false,
                    'color' => $roleColor,
                ]);

                foreach ($expectedPerms as $featureId => $perms) {
                    $role->permissions()->create(array_merge(['feature_id' => $featureId], $perms));
                }
            });

            return 'created';
        }

        // Reconcilia permisos contra el template actual.
        $currentPerms = $existing->permissions
            ->keyBy('feature_id')
            ->map(fn (CompanyRolePermission $p) => [
                'can_create' => (bool) $p->can_create,
                'can_read' => (bool) $p->can_read,
                'can_update' => (bool) $p->can_update,
                'can_delete' => (bool) $p->can_delete,
            ])
            ->all();

        $changed = false;

        if ($dryRun) {
            foreach ($expectedPerms as $featureId => $exp) {
                $cur = $currentPerms[$featureId] ?? null;
                if ($cur !== $exp) {
                    $changed = true;
                    break;
                }
            }

            return $changed ? 'updated' : 'unchanged';
        }

        DB::transaction(function () use (&$changed, $existing, $expectedPerms, $currentPerms) {
            foreach ($expectedPerms as $featureId => $exp) {
                $cur = $currentPerms[$featureId] ?? null;
                if ($cur !== $exp) {
                    CompanyRolePermission::updateOrCreate(
                        ['company_role_id' => $existing->id, 'feature_id' => $featureId],
                        $exp,
                    );
                    $changed = true;
                }
            }
        });

        return $changed ? 'updated' : 'unchanged';
    }
}
