<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\SignedAssetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retorna los datos públicos de la empresa activa del JWT.
 *
 * El company_nit proviene del middleware EnsureCompanyAccess (active_company_nit).
 * El logo_url se construye desde storage/public; puede ser null si no hay logo.
 */
class ActiveCompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $nit = $request->attributes->get('active_company_nit');

        $company = Company::where('nit', $nit)->firstOrFail();

        return response()->json([
            'company' => [
                'nit' => $company->nit,
                'name' => $company->commercial_name,
                'logo_url' => SignedAssetUrl::for($company->qr_code_path),
                'status' => $company->status,
            ],
        ]);
    }
}
