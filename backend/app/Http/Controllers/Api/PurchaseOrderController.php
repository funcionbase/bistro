<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\CancelPurchaseOrderRequest;
use App\Http\Requests\Purchases\MarkPaidRequest;
use App\Http\Requests\Purchases\StorePurchaseOrderRequest;
use App\Http\Requests\Purchases\UpdatePurchaseOrderRequest;
use App\Http\Requests\Purchases\VoidPurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Órdenes de compra. La lógica vive en PurchaseService — este controller solo
 * ortografía HTTP, validación delegada y serialización.
 *
 * - GET    /api/v1/purchases                       — listado paginado con filtros.
 * - POST   /api/v1/purchases                       — alta como draft.
 * - GET    /api/v1/purchases/{id}                  — detalle.
 * - PATCH  /api/v1/purchases/{id}                  — editar draft.
 * - POST   /api/v1/purchases/{id}/submit           — draft → pending.
 * - POST   /api/v1/purchases/{id}/receive          — pending → received (mueve inventario).
 * - POST   /api/v1/purchases/{id}/pay              — received → paid.
 * - POST   /api/v1/purchases/{id}/cancel           — draft|pending → cancelled.
 * - POST   /api/v1/purchases/{id}/void             — received|paid → voided + NC + reverso.
 * - POST   /api/v1/purchases/{id}/settle-refund    — limpia bandera pending_supplier_refund.
 */
class PurchaseOrderController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);

        $query = PurchaseOrder::forCompany($companyNit)->with('supplier:id,name');

        if ($status = trim((string) $request->input('status', ''))) {
            $query->where('status', $status);
        }
        if ($supplierId = (string) $request->input('supplier_id', '')) {
            $query->where('supplier_id', $supplierId);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($request->boolean('pending_refund')) {
            $query->where('pending_supplier_refund', true);
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where('code', 'ilike', '%'.$q.'%');
        }

        $perPage = min((int) $request->input('per_page', 30), 200);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (PurchaseOrder $p) => $this->serializeSummary($p))->all(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $user = $this->actingUser($request);
        $validated = $request->validated();

        $supplier = Supplier::forCompany($companyNit)->findOrFail($validated['supplier_id']);

        $po = $this->purchases->createDraft(
            companyNit: $companyNit,
            supplier: $supplier,
            items: $validated['items'],
            meta: [
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
            actor: $user,
        );

        return response()->json(['data' => $this->serializeDetail($po)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)
            ->with(['supplier', 'items.ingredient:id,name,unit', 'attachments', 'creditNotes'])
            ->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail($po)]);
    }

    public function update(UpdatePurchaseOrderRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);
        $user = $this->actingUser($request);
        $validated = $request->validated();

        $po = $this->purchases->updateDraft(
            po: $po,
            items: $validated['items'] ?? null,
            meta: [
                'expected_date' => $validated['expected_date'] ?? $po->expected_date?->toDateString(),
                'notes' => $validated['notes'] ?? $po->notes,
            ],
            actor: $user,
        );

        return response()->json(['data' => $this->serializeDetail($po)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->submit($po, $this->actingUser($request))
        )]);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->receive($po, $this->actingUser($request))
        )]);
    }

    public function pay(MarkPaidRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);
        $validated = $request->validated();

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->markPaid(
                $po,
                $validated['payment_method'],
                $validated['payment_reference'] ?? null,
                $this->actingUser($request),
            )
        )]);
    }

    public function cancel(CancelPurchaseOrderRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->cancel(
                $po,
                $request->validated()['reason'] ?? null,
                $this->actingUser($request),
            )
        )]);
    }

    public function void(VoidPurchaseOrderRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->voidWithCreditNote(
                $po,
                $request->validated()['reason'],
                $this->actingUser($request),
            )
        )]);
    }

    public function settleRefund(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $po = PurchaseOrder::forCompany($companyNit)->findOrFail($id);

        return response()->json(['data' => $this->serializeDetail(
            $this->purchases->settleSupplierRefund(
                $po,
                $request->input('reference'),
                $this->actingUser($request),
            )
        )]);
    }

    /** @return array<string, mixed> */
    private function serializeSummary(PurchaseOrder $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'status' => $p->status,
            'supplier' => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'expected_date' => $p->expected_date?->toDateString(),
            'received_date' => $p->received_date?->toIso8601String(),
            'paid_date' => $p->paid_date?->toIso8601String(),
            'subtotal' => (string) $p->subtotal,
            'tax_amount' => (string) $p->tax_amount,
            'total' => (string) $p->total,
            'payment_method' => $p->payment_method,
            'pending_supplier_refund' => $p->pending_supplier_refund,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(PurchaseOrder $p): array
    {
        $p->loadMissing(['supplier', 'items.ingredient:id,name,unit', 'attachments', 'creditNotes']);

        return array_merge($this->serializeSummary($p), [
            'notes' => $p->notes,
            'payment_reference' => $p->payment_reference,
            'voided_at' => $p->voided_at?->toIso8601String(),
            'items' => $p->items->map(fn (PurchaseOrderItem $i) => [
                'id' => $i->id,
                'ingredient_id' => $i->ingredient_id,
                'description' => $i->description,
                'unit' => $i->ingredient?->unit,
                'quantity' => (string) $i->quantity,
                'unit_cost' => (string) $i->unit_cost,
                'tax_rate' => (string) $i->tax_rate,
                'tax_amount' => (string) $i->tax_amount,
                'line_total' => (string) $i->line_total,
            ])->all(),
            'attachments' => $p->attachments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size_bytes' => $a->size_bytes,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all(),
            'credit_notes' => $p->creditNotes->map(fn ($n) => [
                'id' => $n->id,
                'code' => $n->code,
                'reason' => $n->reason,
                'total_reversed' => (string) $n->total_reversed,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
