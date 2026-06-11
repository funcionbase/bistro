<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BusinessType;
use App\Models\PrepArea;
use App\Services\BusinessCapabilityService;
use App\Services\BusinessLabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint principal del frontend SPA para hidratar el contexto de la sesión:
 * vertical de la sede activa, capabilities resueltas, labels dinámicos y la
 * lista de prep_areas configuradas. El frontend lo llama después de
 * seleccionar empresa+sede, y al cambiar de sede activa.
 *
 * Se monta tras `jwt + company.access + branch.access` para que `active_branch`
 * ya esté inyectado en el request.
 */
class BusinessContextController extends Controller
{
    public function __construct(
        private readonly BusinessCapabilityService $capabilities,
        private readonly BusinessLabelService $labels,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('active_branch');
        $branch->loadMissing(['businessType', 'prepAreas' => fn ($q) => $q->whereNull('archived_at')->ordered()]);

        return response()->json([
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'is_default' => $branch->is_default,
            ],
            'business_type' => $branch->businessType ? [
                'slug' => $branch->businessType->slug,
                'label_es' => $branch->businessType->label_es,
                'label_en' => $branch->businessType->label_en,
                'icon_key' => $branch->businessType->icon_key,
            ] : null,
            'capabilities' => $this->capabilities->capabilities($branch),
            'labels' => $this->labels->labels($branch),
            'prep_areas' => $branch->prepAreas->map(fn (PrepArea $area) => [
                'id' => $area->id,
                'slug' => $area->slug,
                'label' => $area->label,
                'color' => $area->color,
                'icon_key' => $area->icon_key,
                'display_order' => $area->display_order,
            ])->values(),
        ]);
    }

    /**
     * Catálogo público (autenticado) de verticales. Usado por el wizard de
     * onboarding y por el selector de "cambiar tipo de negocio" de la sede.
     */
    public function catalog(): JsonResponse
    {
        $types = BusinessType::query()->active()->ordered()->get();

        return response()->json([
            'data' => $types->map(fn (BusinessType $t) => [
                'slug' => $t->slug,
                'label_es' => $t->label_es,
                'label_en' => $t->label_en,
                'icon_key' => $t->icon_key,
                'default_capabilities' => $t->default_capabilities,
                'prep_area_defaults' => $t->prep_area_defaults,
                'display_order' => $t->display_order,
            ])->values(),
        ]);
    }
}
