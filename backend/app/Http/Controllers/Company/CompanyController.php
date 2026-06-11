<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use App\Services\JwtService;
use App\Services\LogoIconRasterizer;
use App\Support\SignedAssetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CRUD de datos de la empresa activa (información bancaria, razón social, QR/logo).
 *
 * update(): acepta multipart/form-data; los archivos qr_code y logo se almacenan en
 * storage/public/companies/qr-codes y companies/logos respectivamente.
 * El campo 'role' del response proviene del atributo user_role inyectado por EnsureCompanyAccess.
 * Registra before/after en audit para trazabilidad de cambios financieros.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly JwtService $jwtService,
        private readonly FeaturePermissionService $featurePermission,
        private readonly LogoIconRasterizer $logoRasterizer,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = Company::where('nit', $request->attributes->get('active_company_nit'))->firstOrFail();

        return response()->json([
            'company' => [
                'nit' => $company->nit,
                'commercial_name' => $company->commercial_name,
                'legal_name' => $company->legal_name,
                'bank_id' => $company->bank_id,
                'bank_name' => $company->bank ? $company->bank->name : null,
                'account_number' => $company->account_number,
                'account_type' => $company->account_type,
                'breb_key' => $company->breb_key,
                'qr_code_path' => $company->qr_code_path,
                'qr_code_url' => SignedAssetUrl::for($company->qr_code_path),
                'logo_path' => $company->logo_path,
                'logo_url' => SignedAssetUrl::for($company->logo_path),
                'status' => $company->status,
                // Configuración tributaria parametrizable (CO).
                'tax_regime' => $company->tax_regime,
                'default_tax_rate' => (float) $company->default_tax_rate,
                'default_tax_label' => $company->default_tax_label,
                'tax_included_in_price' => (bool) $company->tax_included_in_price,
            ],
            'tax_presets' => config('taxes.regimes'),
            'role' => $request->attributes->get('user_role'),
            'can_update' => $this->featurePermission->hasPermission($request, 'company', 'update'),
        ]);
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $company = Company::where('nit', $request->attributes->get('active_company_nit'))->firstOrFail();
        $user = User::findOrFail($request->attributes->get('jwt_payload')['sub']);

        $before = $company->only([
            'commercial_name', 'legal_name', 'bank_id',
            'account_number', 'account_type', 'breb_key', 'qr_code_path', 'logo_path',
            'tax_regime', 'default_tax_rate', 'default_tax_label', 'tax_included_in_price',
        ]);

        $validated = $request->validated();

        $publicDisk = config('filesystems.default');

        if ($request->hasFile('qr_code')) {
            $validated['qr_code_path'] = $request->file('qr_code')->store('companies/qr-codes', $publicDisk);
            unset($validated['qr_code']);
            // Borrar el archivo previo para no dejarlo huérfano. El delete es
            // best-effort: si el archivo ya no existe (por ejemplo por una
            // reseed manual), Storage::delete devuelve true y no lanza.
            if (! empty($before['qr_code_path']) && $before['qr_code_path'] !== $validated['qr_code_path']) {
                Storage::disk($publicDisk)->delete($before['qr_code_path']);
            }
        }

        if ($request->hasFile('logo')) {
            $validated['logo_path'] = $request->file('logo')->store('companies/logos', $publicDisk);
            unset($validated['logo']);
            if (! empty($before['logo_path']) && $before['logo_path'] !== $validated['logo_path']) {
                Storage::disk($publicDisk)->delete($before['logo_path']);
            }

            // Rasterizar el logo en los 5 iconos PWA. Best-effort: si falla
            // (formato exótico, GD sin soporte), se loggea y se sigue — el
            // manifest hace fallback a los iconos flexyflow por defecto.
            try {
                $this->logoRasterizer->rasterize($company->nit, $validated['logo_path']);
            } catch (\Throwable $e) {
                Log::warning('PWA logo rasterization failed', [
                    'company_nit' => $company->nit,
                    'logo_path' => $validated['logo_path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $company->update($validated);

        $after = $company->fresh()->only([
            'commercial_name', 'legal_name', 'bank_id',
            'account_number', 'account_type', 'breb_key', 'qr_code_path', 'logo_path',
            'tax_regime', 'default_tax_rate', 'default_tax_label', 'tax_included_in_price',
        ]);

        $this->auditService->log('company.updated', $user, $company, [
            'before' => $before,
            'after' => $after,
        ], $request);

        // Reemitir JWT para reflejar el commercial_name actualizado en active_company_name
        // (el sidebar lee el nombre desde el payload del token). Se devuelve también
        // en el body para que el cliente sincronice su copia en localStorage usada
        // como Authorization Bearer en `apiFetch`; sin esto, el cliente seguía
        // mandando el JWT viejo en cada request hasta el próximo login.
        $token = $this->jwtService->issue($user, $user->companies()->get(), $company->nit);

        return response()
            ->json([
                'company' => [
                    'nit' => $company->nit,
                    'commercial_name' => $company->commercial_name,
                    'legal_name' => $company->legal_name,
                    'bank_id' => $company->bank_id,
                    'bank_name' => $company->bank ? $company->bank->name : null,
                    'account_number' => $company->account_number,
                    'account_type' => $company->account_type,
                    'breb_key' => $company->breb_key,
                    'qr_code_path' => $company->qr_code_path,
                    'qr_code_url' => SignedAssetUrl::for($company->qr_code_path),
                    'logo_path' => $company->logo_path,
                    'logo_url' => SignedAssetUrl::for($company->logo_path),
                    'status' => $company->status,
                    'tax_regime' => $company->tax_regime,
                    'default_tax_rate' => (float) $company->default_tax_rate,
                    'default_tax_label' => $company->default_tax_label,
                    'tax_included_in_price' => (bool) $company->tax_included_in_price,
                ],
                'authenticated' => true,
                'token' => $token,
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }
}
