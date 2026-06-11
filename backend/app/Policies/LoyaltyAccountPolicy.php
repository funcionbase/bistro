<?php

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\LoyaltyAccount;
use App\Models\User;

/**
 * Authorization para fidelización (#122).
 *
 * El middleware permission:loyalty.{read,update} aplica RBAC. Esta policy
 * adicionalmente confirma que el actor pertenece a la empresa de la cuenta —
 * fundamental porque LoyaltyAccount NO tiene branch (cross-sede): bypassear
 * la pertenencia a empresa expondría datos de otros tenants.
 */
class LoyaltyAccountPolicy
{
    public function view(User $user, LoyaltyAccount $account): bool
    {
        return $this->belongsToCompany($user, $account->company_nit);
    }

    public function update(User $user, LoyaltyAccount $account): bool
    {
        return $this->belongsToCompany($user, $account->company_nit);
    }

    private function belongsToCompany(User $user, string $companyNit): bool
    {
        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('company_nit', $companyNit)
            ->exists();
    }
}
