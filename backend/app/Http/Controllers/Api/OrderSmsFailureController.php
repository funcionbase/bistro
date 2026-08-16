<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderSmsNotification;
use App\Models\Scopes\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feedback al usuario que disparó un cambio de estado cuando el SMS al cliente
 * falló en el envío async (SendOrderStatusSmsJob marca `failed`).
 *
 * Self-scoped: cada quien ve SOLO los SMS fallidos que él mismo originó
 * (`user_id` = JWT sub) en la empresa activa y que aún no acusó recibo
 * (`user_notified_at IS NULL`). El front los muestra una vez (toast) y luego
 * llama a `seen()` para marcarlos — garantiza el "una sola vez" a nivel
 * servidor, idempotente entre dispositivos y N instancias.
 *
 * Impacto RBAC: NINGÚN permiso nuevo. No expone datos de terceros ni acciones
 * sensibles, solo el feedback propio del actor; se apoya en los middlewares
 * existentes (jwt + company.access). El scope por `user_id` + `company_nit` es
 * la frontera de aislamiento (no se filtra por sede a propósito: el usuario debe
 * enterarse de su SMS fallido aunque ya haya cambiado de sede activa).
 */
class OrderSmsFailureController extends Controller
{
    use ResolvesActiveContext;
    use ResolvesJwtActor;

    private const MAX_BATCH = 100;

    /**
     * SMS fallidos del usuario actual, en la empresa activa, no vistos aún.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->actingUserOrFail($request);
        $companyNit = $this->activeCompanyNit($request);

        $failures = OrderSmsNotification::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('company_nit', $companyNit)
            ->where('user_id', $user->id)
            ->where('status', 'failed')
            ->whereNull('user_notified_at')
            ->orderBy('created_at')
            ->limit(self::MAX_BATCH)
            ->get(['id', 'order_id', 'to_status', 'created_at']);

        $orders = Order::withoutBranchScope()
            ->whereIn('id', $failures->pluck('order_id')->all())
            ->get(['id'])
            ->keyBy('id');

        $data = $failures->map(fn (OrderSmsNotification $f): array => [
            'id' => $f->id,
            'order_id' => $f->order_id,
            'order_code' => $orders->get($f->order_id)?->shortCode(),
            'to_status' => $f->to_status,
            'created_at' => $f->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Marca como vistos (ack) los SMS fallidos indicados. Atómico y N-instance
     * safe: el UPDATE filtra por `user_id` del actor + `user_notified_at IS NULL`,
     * así un segundo dispositivo/instancia no re-muestra ni pisa el timestamp.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $user = $this->actingUserOrFail($request);
        $companyNit = $this->activeCompanyNit($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX_BATCH],
            'ids.*' => ['string', 'uuid'],
        ]);

        $marked = OrderSmsNotification::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('company_nit', $companyNit)
            ->where('user_id', $user->id)
            ->whereIn('id', $validated['ids'])
            ->whereNull('user_notified_at')
            ->update(['user_notified_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }
}
