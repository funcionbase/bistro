<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de proveedores por empresa. `destroy` es soft-archive (archived_at)
 * para conservar el histórico de PO. Endpoints expuestos:
 *
 * - GET    /api/v1/suppliers                — paginado con filtros (q, archived).
 * - POST   /api/v1/suppliers                — alta.
 * - GET    /api/v1/suppliers/{id}           — detalle.
 * - PATCH  /api/v1/suppliers/{id}           — actualizar metadatos.
 * - DELETE /api/v1/suppliers/{id}           — archivar.
 * - POST   /api/v1/suppliers/{id}/restore   — restaurar.
 */
class SupplierController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);

        $query = Supplier::forCompany($companyNit);

        if ($request->boolean('archived')) {
            $query->archived();
        } else {
            $query->active();
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', '%'.$q.'%')
                    ->orWhere('document_number', 'ilike', '%'.$q.'%')
                    ->orWhere('contact_name', 'ilike', '%'.$q.'%');
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Supplier $s) => $this->serialize($s))->all(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $user = $this->actingUser($request);

        $supplier = Supplier::create(array_merge(
            $request->validated(),
            [
                'company_nit' => $companyNit,
                'branch_id' => $this->activeBranchId($request),
            ]
        ));

        $this->auditService->log('purchases.supplier.created', $user, $supplier, [
            'name' => $supplier->name,
            'document_number' => $supplier->document_number,
        ]);

        return response()->json(['data' => $this->serialize($supplier)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $supplier = Supplier::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serialize($supplier)]);
    }

    public function update(UpdateSupplierRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $supplier = Supplier::forCompany($companyNit)->findOrFail($id);

        $before = $supplier->only(['name', 'document_number', 'email', 'phone', 'payment_terms_days']);
        $supplier->fill($request->validated())->save();

        $this->auditService->log('purchases.supplier.updated', $this->actingUser($request), $supplier, [
            'before' => $before,
            'after' => $supplier->only(['name', 'document_number', 'email', 'phone', 'payment_terms_days']),
        ]);

        return response()->json(['data' => $this->serialize($supplier)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $supplier = Supplier::forCompany($companyNit)->findOrFail($id);

        $supplier->forceFill(['archived_at' => now()])->save();

        $this->auditService->log('purchases.supplier.archived', $this->actingUser($request), $supplier, [
            'name' => $supplier->name,
        ]);

        return response()->json(['data' => $this->serialize($supplier)]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $supplier = Supplier::forCompany($companyNit)->findOrFail($id);

        $supplier->forceFill(['archived_at' => null])->save();

        $this->auditService->log('purchases.supplier.restored', $this->actingUser($request), $supplier, [
            'name' => $supplier->name,
        ]);

        return response()->json(['data' => $this->serialize($supplier)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'document_type' => $s->document_type,
            'document_number' => $s->document_number,
            'contact_name' => $s->contact_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'address' => $s->address,
            'payment_terms_days' => $s->payment_terms_days,
            'notes' => $s->notes,
            'archived_at' => $s->archived_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
