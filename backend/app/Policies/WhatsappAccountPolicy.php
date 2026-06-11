<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CompanyWhatsappAccount;
use App\Models\User;

/**
 * Authorization para acciones sobre la cuenta de WhatsApp de una empresa.
 *
 * El middleware permission:whatsapp.{accion} ya valida el permiso RBAC. Esta
 * policy se usa SOLO para acciones owner-only (swap_phone, disconnect): aunque
 * un admin tenga teoricamente el permiso (no debería: PermissionTemplateSeeder
 * lo bloquea), aqui asumimos paranoia y validamos a nivel de aplicacion que
 * el actor tiene rol con `name = config('roles.role_names.owner')`.
 */
class WhatsappAccountPolicy
{
    public function view(User $user, CompanyWhatsappAccount $account): bool
    {
        return $this->isMember($user, $account->company_nit);
    }

    public function update(User $user, CompanyWhatsappAccount $account): bool
    {
        return $this->isMember($user, $account->company_nit);
    }

    public function swapPhone(User $user, CompanyWhatsappAccount $account): bool
    {
        return $this->isOwner($user, $account->company_nit);
    }

    public function disconnect(User $user, CompanyWhatsappAccount $account): bool
    {
        return $this->isOwner($user, $account->company_nit);
    }

    public function connect(User $user, Company $company): bool
    {
        return $this->isMember($user, $company->nit);
    }

    private function isMember(User $user, string $companyNit): bool
    {
        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('company_nit', $companyNit)
            ->exists();
    }

    private function isOwner(User $user, string $companyNit): bool
    {
        $ownerRoleName = config('roles.role_names.owner', 'Propietario');

        return CompanyUser::query()
            ->join('company_roles', 'company_users.company_role_id', '=', 'company_roles.id')
            ->where('company_users.user_id', $user->id)
            ->where('company_users.company_nit', $companyNit)
            ->where('company_roles.is_system', true)
            ->where('company_roles.name', $ownerRoleName)
            ->exists();
    }
}
