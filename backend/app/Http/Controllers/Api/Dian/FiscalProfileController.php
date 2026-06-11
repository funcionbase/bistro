<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\UpdateFiscalProfileRequest;
use App\Models\Company;
use App\Models\CompanyRolePermission;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil fiscal DIAN del emisor (extensión a `companies`).
 *
 * Se edita desde /company/settings → "Información"; gateado con el feature
 * dedicado `company.fiscal_profile` (read/update). Owner-only por template;
 * los roles de sistema (owner/admin/employee) bypassean por is_system, los
 * operativos quedan restringidos salvo asignación explícita.
 */
class FiscalProfileController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json([
            'data' => [
                'nit' => $company->nit,
                'dv' => $company->dv,
                'commercial_name' => $company->commercial_name,
                'legal_name' => $company->legal_name,
                'legal_representative_name' => $company->legal_representative_name,
                'legal_representative_doc_type' => $company->legal_representative_doc_type,
                'legal_representative_doc_number' => $company->legal_representative_doc_number,
                'economic_activity_code' => $company->economic_activity_code,
                'fiscal_responsibilities' => $company->fiscal_responsibilities ?? [],
                'tax_obligations' => $company->tax_obligations ?? [],
                'municipality_dane_code' => $company->municipality_dane_code,
                'billing_email' => $company->billing_email,
                'billing_phone' => $company->billing_phone,
                'physical_address' => $company->physical_address,
                'country_code' => $company->country_code,
            ],
            'catalogs' => [
                'doc_types' => config('dian.doc_types_catalog'),
                'fiscal_responsibilities' => config('dian.fiscal_responsibilities_catalog'),
            ],
            // El frontend usa esto para habilitar/deshabilitar la edición. Se
            // resuelve igual que el middleware `permission:company.fiscal_profile,update`:
            // los roles de sistema bypassean; los operativos requieren el bit.
            'can_update' => $this->canUpdate($request),
        ]);
    }

    /**
     * Resuelve si el rol activo puede editar el perfil fiscal, replicando la
     * lógica de EnsureFeaturePermission (no se usa FeaturePermissionService
     * porque su convención de slug asume `<grupo>.<accion-CRUD>`, y este
     * feature es `company.fiscal_profile`).
     */
    private function canUpdate(Request $request): bool
    {
        if ($request->attributes->get('company_role_is_system', false)) {
            return true;
        }

        $roleId = $request->attributes->get('company_role_id');

        if ($roleId === null) {
            return false;
        }

        return CompanyRolePermission::where('company_role_id', $roleId)
            ->whereHas('feature', fn ($q) => $q->where('slug', 'company.fiscal_profile'))
            ->where('can_update', true)
            ->exists();
    }

    public function update(UpdateFiscalProfileRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $payload = $request->validated();

        $company->fill($payload)->save();

        $this->audit->log('dian.profile.updated', null, $company, [
            'changed_fields' => array_keys($payload),
        ]);

        return $this->show($request);
    }

    private function company(Request $request): Company
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        return Company::query()->where('nit', $nit)->firstOrFail();
    }
}
