<?php

namespace App\Observers;

use App\Models\CompanyRolePermission;
use App\Models\CompanyUser;
use App\Services\FeaturePermissionService;

/**
 * Invalida la caché de matriz de permisos cuando se modifican los CRUD
 * de un rol de empresa.
 *
 * `FeaturePermissionService` cachea la matriz por (company_nit, user_id)
 * con TTL flexible. Sin invalidación, un cambio de permiso tardaría hasta
 * 10 minutos en propagarse (stale_ttl). Con este observer, la propagación
 * es inmediata para todos los miembros con el rol modificado.
 */
class CompanyRolePermissionObserver
{
    public function saved(CompanyRolePermission $permission): void
    {
        $this->forgetRoleMembers($permission);
    }

    public function deleted(CompanyRolePermission $permission): void
    {
        $this->forgetRoleMembers($permission);
    }

    private function forgetRoleMembers(CompanyRolePermission $permission): void
    {
        $role = $permission->role()->first();
        if ($role === null) {
            return;
        }

        $companyNit = $role->company_nit;

        CompanyUser::where('company_role_id', $permission->company_role_id)
            ->where('company_nit', $companyNit)
            ->pluck('user_id')
            ->each(fn ($uid) => FeaturePermissionService::forgetUserCache($companyNit, (string) $uid));
    }
}
