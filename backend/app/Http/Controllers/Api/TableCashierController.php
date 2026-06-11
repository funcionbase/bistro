<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TableSession;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\TableCashierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caja con pago dividido por comensal — API.
 *
 * Requiere JWT + sede + permission `orders.update`. Devuelve JSON.
 * UI: `pages/caja/table-session.tsx`.
 */
class TableCashierController extends Controller
{
    public function __construct(private readonly TableCashierService $cashier) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);

        return response()->json(['data' => $this->cashier->getSessionState($session)]);
    }

    public function payPartial(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'guest_id' => ['required', 'integer'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'client_uuid' => ['required', 'string', 'uuid'],
        ]);

        try {
            $receipt = $this->cashier->payPartial($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'payment_method' => $receipt->payment_method,
                'amount' => (string) $receipt->amount,
                'guest_id' => $receipt->guest_id,
                'paid_at' => optional($receipt->paid_at)?->toIso8601String(),
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    public function payAll(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'payment_method' => ['required', 'string', 'in:cash,card,transfer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'client_uuid' => ['required', 'string', 'uuid'],
            'payer_guest_id' => ['nullable', 'integer'],
        ]);

        try {
            $receipt = $this->cashier->payAll($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'payment_method' => $receipt->payment_method,
                'amount' => (string) $receipt->amount,
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    public function refundItem(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'item_id' => ['required', 'integer'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'client_uuid' => ['required', 'string', 'uuid'],
        ]);

        try {
            $receipt = $this->cashier->refundItem($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'amount' => (string) $receipt->amount,
                'payment_method' => $receipt->payment_method,
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    private function loadSession(Request $request, string $id): TableSession
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        return TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;

        return User::query()->findOrFail($sub);
    }
}
