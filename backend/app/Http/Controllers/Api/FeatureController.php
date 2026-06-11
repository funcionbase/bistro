<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lista todas las features del sistema disponibles para asignar permisos a roles.
 *
 * La ruta se gatea con `permission:roles.read,read` (ver routes/api.php): solo
 * quien puede ver roles obtiene el catálogo. Incluye `is_owner_only`, que el
 * editor usa para deshabilitar las features no asignables a roles no-sistema.
 * Ordenado por group luego name para presentación coherente en UI.
 */
class FeatureController extends Controller
{
    public function index(): JsonResource
    {
        $features = Feature::orderBy('group')->orderBy('name')->get();

        return JsonResource::collection($features);
    }
}
