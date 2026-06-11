<?php

namespace App\Policies;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\CompanyUser;
use App\Models\User;

/**
 * Authorization para alertas (#124).
 *
 * El middleware `permission:reports.read,read` (view/dismiss/action) y
 * `permission:company.update,update` (rule upsert) aplican RBAC. Esta policy
 * adicionalmente confirma que el actor pertenece a la empresa dueña del
 * evento/regla — defensa en profundidad contra bypass via IDs ajenos.
 */
class AlertEventPolicy
{
    public function view(User $user, AlertEvent $event): bool
    {
        return $this->belongsToCompany($user, $event->company_nit);
    }

    public function manage(User $user, AlertEvent $event): bool
    {
        return $this->belongsToCompany($user, $event->company_nit);
    }

    public function updateRule(User $user, AlertRule $rule): bool
    {
        return $this->belongsToCompany($user, $rule->company_nit);
    }

    private function belongsToCompany(User $user, string $companyNit): bool
    {
        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('company_nit', $companyNit)
            ->exists();
    }
}
