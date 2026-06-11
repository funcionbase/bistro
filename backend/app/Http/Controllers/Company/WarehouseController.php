<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchWarehouse;
use App\Models\IngredientStock;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD de bodegas (warehouses) + asignación a sedes (#costeo-multibodega).
 *
 * Una bodega es un recurso de EMPRESA (company-scoped). La relación con las
 * sedes vive en el pivot `branch_warehouses`: una bodega sirve a N sedes y cada
 * sede declara cuál de sus bodegas asignadas es la default (la que reciben
 * recetas/compras sin bodega explícita).
 *
 * Reglas:
 *  - `slug` único por empresa.
 *  - Default por sede: una sola fila `is_default=true` por sede en el pivot.
 *  - Archivar es soft-delete; se rechaza con stock != 0 en cualquier sede y se
 *    impide dejar la empresa sin ninguna bodega activa. Al archivar, las sedes
 *    cuya default era esta bodega promueven otra asignada (si existe).
 *  - Desasignar de una sede se bloquea si hay recetas activas de esa sede
 *    apuntando a la bodega.
 */
class WarehouseController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $includeArchived = $request->boolean('include_archived');
        $branchId = $request->query('branch_id');

        $query = Warehouse::query()
            ->where('company_nit', $nit)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($branchId !== null, fn ($q) => $q->forBranch((string) $branchId))
            ->with('branchAssignments')
            ->orderByDesc('is_default')
            ->orderBy('name');

        $warehouses = $query->get();

        return response()->json([
            'data' => $warehouses->map(fn (Warehouse $w) => $this->toArray($w))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'type' => ['required', Rule::in(Warehouse::VALID_TYPES)],
            // Opcional: asignar de una vez a una sede (y marcar default).
            'branch_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $branch = null;
        if (! empty($validated['branch_id'])) {
            $branch = Branch::query()
                ->where('company_nit', $nit)
                ->where('id', $validated['branch_id'])
                ->whereNull('archived_at')
                ->firstOrFail();
        }

        $slug = $this->uniqueSlug($nit, $validated['slug'] ?? Str::slug($validated['name']));

        $warehouse = DB::transaction(function () use ($nit, $branch, $validated, $slug) {
            $warehouse = Warehouse::create([
                'company_nit' => $nit,
                'name' => $validated['name'],
                'slug' => $slug,
                'type' => $validated['type'],
                'is_default' => false,
            ]);

            if ($branch !== null) {
                Warehouse::assignToBranch($nit, $branch->id, $warehouse->id, (bool) ($validated['is_default'] ?? false));
            }

            return $warehouse;
        });

        $this->auditService->log('warehouse.created', null, $warehouse, [
            'warehouse_id' => $warehouse->id,
            'branch_id' => $branch?->id,
            'slug' => $slug,
            'type' => $warehouse->type,
        ]);

        return response()->json(['data' => $this->toArray($warehouse->fresh('branchAssignments'))], 201);
    }

    public function update(Request $request, string $warehouse): JsonResponse
    {
        $model = $this->resolveWarehouse($request, $warehouse);
        $nit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'name' => ['sometimes', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'slug' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'type' => ['sometimes', Rule::in(Warehouse::VALID_TYPES)],
        ]);

        if (array_key_exists('slug', $validated)) {
            $validated['slug'] = $this->uniqueSlug($nit, $validated['slug'], $model->id);
        }

        $before = $model->only(['name', 'slug', 'type']);

        $model->fill($validated)->save();

        $this->auditService->log('warehouse.updated', null, $model, [
            'warehouse_id' => $model->id,
            'before' => $before,
            'after' => $model->fresh()->only(array_keys($before)),
        ]);

        return response()->json(['data' => $this->toArray($model->fresh('branchAssignments'))]);
    }

    public function destroy(Request $request, string $warehouse): JsonResponse
    {
        $model = $this->resolveWarehouse($request, $warehouse);
        $nit = (string) $request->attributes->get('active_company_nit');

        if ($model->isArchived()) {
            return response()->json(['message' => 'La bodega ya está archivada.'], 422);
        }

        $hasStock = IngredientStock::query()
            ->where('warehouse_id', $model->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return response()->json([
                'message' => 'No puedes archivar una bodega con existencias. Transfiere su inventario primero.',
                'code' => 'WAREHOUSE_HAS_STOCK',
            ], 422);
        }

        // Bloqueo contable: archivar elimina la bodega de TODAS las sedes, así
        // que cualquier receta activa que costee desde ella (de cualquier sede)
        // quedaría sin fuente de costo. withoutBranchScope porque es recurso
        // company-level y se evalúan todas las sedes.
        $recipesUsing = Recipe::withoutBranchScope()
            ->where('warehouse_id', $model->id)
            ->whereNull('archived_at')
            ->exists();

        if ($recipesUsing) {
            return response()->json([
                'message' => 'No puedes archivar la bodega: hay recetas que la usan para costear. Reasigna esas recetas a otra bodega primero.',
                'code' => 'WAREHOUSE_USED_BY_RECIPES',
            ], 422);
        }

        // No dejar a la empresa sin ninguna bodega activa.
        $remainingActive = Warehouse::query()
            ->where('company_nit', $nit)
            ->whereNull('archived_at')
            ->where('id', '!=', $model->id)
            ->count();

        if ($remainingActive === 0) {
            return response()->json([
                'message' => 'No puedes archivar la única bodega activa de la empresa.',
                'code' => 'LAST_ACTIVE_WAREHOUSE',
            ], 422);
        }

        DB::transaction(function () use ($model, $nit) {
            // Sedes cuya default era esta bodega: promover otra bodega activa
            // asignada (la más antigua) para que no queden sin default.
            $defaultForBranches = BranchWarehouse::query()
                ->where('warehouse_id', $model->id)
                ->where('is_default', true)
                ->pluck('branch_id');

            foreach ($defaultForBranches as $branchId) {
                $replacement = Warehouse::query()
                    ->where('company_nit', $nit)
                    ->whereNull('archived_at')
                    ->where('id', '!=', $model->id)
                    ->forBranch((string) $branchId)
                    ->orderBy('created_at')
                    ->first();

                if ($replacement !== null) {
                    Warehouse::assignToBranch($nit, (string) $branchId, $replacement->id, isDefault: true);
                }
            }

            // Quitar las asignaciones de esta bodega y archivarla.
            BranchWarehouse::query()->where('warehouse_id', $model->id)->delete();
            $model->forceFill(['archived_at' => now(), 'is_default' => false])->save();
        });

        $this->auditService->log('warehouse.archived', null, $model, [
            'warehouse_id' => $model->id,
            'slug' => $model->slug,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Asignar una bodega a una sede (y opcionalmente marcarla default).
     * POST /company/warehouses/{warehouse}/branches  { branch_id, is_default? }
     */
    public function assignBranch(Request $request, string $warehouse): JsonResponse
    {
        $model = $this->resolveWarehouse($request, $warehouse);
        $nit = (string) $request->attributes->get('active_company_nit');

        if ($model->isArchived()) {
            return response()->json([
                'message' => 'No puedes asignar una bodega archivada a una sede.',
                'code' => 'WAREHOUSE_ARCHIVED',
            ], 422);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'string', 'uuid'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $branch = Branch::query()
            ->where('company_nit', $nit)
            ->where('id', $validated['branch_id'])
            ->whereNull('archived_at')
            ->firstOrFail();

        $isDefault = (bool) ($validated['is_default'] ?? false);

        // Si la sede no tiene ninguna bodega aún, esta pasa a ser su default.
        $hasAny = BranchWarehouse::query()->where('branch_id', $branch->id)->exists();
        if (! $hasAny) {
            $isDefault = true;
        }

        Warehouse::assignToBranch($nit, $branch->id, $model->id, $isDefault);

        $this->auditService->log('warehouse.branch_assigned', null, $model, [
            'warehouse_id' => $model->id,
            'branch_id' => $branch->id,
            'is_default' => $isDefault,
        ]);

        return response()->json(['data' => $this->toArray($model->fresh('branchAssignments'))]);
    }

    /**
     * Desasignar una bodega de una sede.
     * DELETE /company/warehouses/{warehouse}/branches/{branch}
     */
    public function unassignBranch(Request $request, string $warehouse, string $branch): JsonResponse
    {
        $model = $this->resolveWarehouse($request, $warehouse);
        $nit = (string) $request->attributes->get('active_company_nit');

        $pivot = BranchWarehouse::query()
            ->where('warehouse_id', $model->id)
            ->where('branch_id', $branch)
            ->first();

        if ($pivot === null) {
            return response()->json([
                'message' => 'La bodega no está asignada a esa sede.',
                'code' => 'WAREHOUSE_NOT_ASSIGNED',
            ], 422);
        }

        // Bloqueo contable: recetas activas de la sede que costean desde esta
        // bodega quedarían sin fuente de costo. withoutBranchScope: la gestión
        // de bodegas es company-level y la sede activa del admin puede no ser
        // $branch; sin esto el BranchScope añadiría branch_id=active_branch_id y
        // el guard daría 0 filas (falso negativo → bypass del bloqueo).
        $recipesUsing = Recipe::withoutBranchScope()
            ->where('branch_id', $branch)
            ->where('warehouse_id', $model->id)
            ->whereNull('archived_at')
            ->exists();

        if ($recipesUsing) {
            return response()->json([
                'message' => 'No puedes desasignar la bodega: hay recetas de esta sede que la usan para costear. Reasigna esas recetas primero.',
                'code' => 'WAREHOUSE_USED_BY_RECIPES',
            ], 422);
        }

        DB::transaction(function () use ($pivot, $branch, $nit) {
            $wasDefault = $pivot->is_default;
            $pivot->delete();

            // Si era la default y quedan otras bodegas asignadas, promover la
            // más antigua para que la sede no quede sin default operativa.
            if ($wasDefault) {
                $replacement = Warehouse::query()
                    ->where('company_nit', $nit)
                    ->whereNull('archived_at')
                    ->forBranch($branch)
                    ->orderBy('created_at')
                    ->first();

                if ($replacement !== null) {
                    Warehouse::assignToBranch($nit, $branch, $replacement->id, isDefault: true);
                }
            }
        });

        $this->auditService->log('warehouse.branch_unassigned', null, $model, [
            'warehouse_id' => $model->id,
            'branch_id' => $branch,
        ]);

        return response()->json(['data' => $this->toArray($model->fresh('branchAssignments'))]);
    }

    /**
     * Marcar una bodega como default de una sede (debe estar asignada).
     * PUT /company/warehouses/{warehouse}/branches/{branch}/default
     */
    public function setBranchDefault(Request $request, string $warehouse, string $branch): JsonResponse
    {
        $model = $this->resolveWarehouse($request, $warehouse);
        $nit = (string) $request->attributes->get('active_company_nit');

        $assigned = BranchWarehouse::query()
            ->where('warehouse_id', $model->id)
            ->where('branch_id', $branch)
            ->exists();

        if (! $assigned) {
            return response()->json([
                'message' => 'La bodega no está asignada a esa sede. Asígnala antes de marcarla como default.',
                'code' => 'WAREHOUSE_NOT_ASSIGNED',
            ], 422);
        }

        if ($model->isArchived()) {
            return response()->json([
                'message' => 'No puedes marcar como default una bodega archivada.',
                'code' => 'WAREHOUSE_ARCHIVED',
            ], 422);
        }

        Warehouse::assignToBranch($nit, $branch, $model->id, isDefault: true);

        $this->auditService->log('warehouse.branch_default_set', null, $model, [
            'warehouse_id' => $model->id,
            'branch_id' => $branch,
        ]);

        return response()->json(['data' => $this->toArray($model->fresh('branchAssignments'))]);
    }

    private function resolveWarehouse(Request $request, string $warehouse): Warehouse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        return Warehouse::query()
            ->where('company_nit', $nit)
            ->where('id', $warehouse)
            ->firstOrFail();
    }

    /**
     * Genera un slug único por empresa. Si el slug propuesto colisiona con otra
     * bodega de la empresa, le agrega un sufijo numérico (-2, -3, …).
     */
    private function uniqueSlug(string $companyNit, string $slug, ?string $ignoreId = null): string
    {
        $slug = substr($slug, 0, 64);
        $candidate = $slug;
        $n = 1;

        while (
            Warehouse::query()
                ->where('company_nit', $companyNit)
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $n++;
            $suffix = '-'.$n;
            $candidate = substr($slug, 0, 64 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function toArray(Warehouse $w): array
    {
        $assignments = $w->relationLoaded('branchAssignments')
            ? $w->branchAssignments
            : $w->branchAssignments()->get();

        return [
            'id' => $w->id,
            'name' => $w->name,
            'slug' => $w->slug,
            'type' => $w->type,
            'is_default' => (bool) $w->is_default,
            'branches' => $assignments->map(fn (BranchWarehouse $bw) => [
                'branch_id' => $bw->branch_id,
                'is_default' => (bool) $bw->is_default,
            ])->values()->all(),
            'archived_at' => optional($w->archived_at)->toIso8601String(),
            'created_at' => optional($w->created_at)->toIso8601String(),
        ];
    }
}
