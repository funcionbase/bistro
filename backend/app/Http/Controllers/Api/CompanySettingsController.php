<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Services\CompanySettingsService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestiona la configuración dinámica por empresa (CompanySettingsService).
 *
 * Solo owner/admin pueden actualizar (can_update se resuelve por user_role del JWT).
 * Las claves válidas están definidas en CompanySettingsService::ALLOWED_KEYS; show() retorna 404 para claves desconocidas.
 * update() acepta un mapa {key: value} validado por UpdateCompanySettingsRequest.
 * Los valores se cachean; seedDefaults() siembra valores por defecto al crear la empresa.
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanySettingsService $settingsService,
        private readonly FeaturePermissionService $featurePermission,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $nit = $request->attributes->get('active_company_nit');
        // can_update se resuelve con el mismo servicio RBAC que el middleware
        // `permission:company.update,update`, no con el alias user_role que
        // solo conoce owner/admin/member por nombre y deja fuera a roles
        // personalizados con permiso explícito.
        $canUpdate = $this->featurePermission->hasPermission($request, 'company', 'update');

        return response()->json([
            'settings' => $this->settingsService->all($nit),
            'can_update' => $canUpdate,
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $nit = $request->attributes->get('active_company_nit');
        $defaults = config('company_defaults', []);

        if (! in_array($key, CompanySettingsService::ALLOWED_KEYS, true)) {
            return response()->json(['message' => 'Clave de configuración no encontrada.'], 404);
        }

        $value = $this->settingsService->get($nit, $key, $defaults[$key]['value'] ?? null);
        $type = $defaults[$key]['type'] ?? 'string';

        return response()->json([
            'key' => $key,
            'value' => $value,
            'type' => $type,
        ]);
    }

    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $nit = $request->attributes->get('active_company_nit');
        $settings = $request->validated()['settings'];

        $this->settingsService->setMany($nit, $settings);

        return response()->json([
            'settings' => $this->settingsService->all($nit),
        ]);
    }
}
