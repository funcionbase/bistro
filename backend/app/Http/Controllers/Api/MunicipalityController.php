<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de municipios DANE para el selector de ciudad del formulario de
 * dirección del contacto ("Ciudad, Departamento" con búsqueda).
 *
 * Datos públicos (no PII, no por empresa): un catálogo nacional. Requiere JWT
 * (es del panel) pero no scope de empresa/sede.
 */
class MunicipalityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'size:5'],
        ]);

        // Resolver una etiqueta por código exacto (para pre-llenar el selector al
        // editar un contacto que ya tiene municipality_dane_code).
        if (! empty($validated['code'])) {
            $m = Municipality::query()->find($validated['code']);

            return response()->json(['data' => $m ? [$this->format($m)] : []]);
        }

        $term = trim((string) ($validated['q'] ?? ''));
        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $items = Municipality::query()
            ->search($term)
            ->orderBy('city')
            ->limit(20)
            ->get();

        return response()->json(['data' => $items->map(fn (Municipality $m) => $this->format($m))]);
    }

    /** @return array{dane_code: string, city: string, department: string, label: string} */
    private function format(Municipality $m): array
    {
        return [
            'dane_code' => $m->dane_code,
            'city' => $m->city,
            'department' => $m->department,
            'label' => $m->label(),
        ];
    }
}
