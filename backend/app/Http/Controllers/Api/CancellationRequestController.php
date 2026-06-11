<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\TableWaiterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolución (approve/deny) de solicitudes de cancelación creadas por el
 * comensal sobre items que ya fueron aprobados.
 *
 * Requiere JWT + permiso `orders.update`.
 */
class CancellationRequestController extends Controller
{
    public function __construct(private readonly TableWaiterService $waiter) {}

    public function resolve(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'decision' => ['required', 'string', 'in:approved,denied'],
            'reason' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $cr = CancellationRequest::query()->findOrFail($id);

        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;
        /** @var User $user */
        $user = User::query()->findOrFail($sub);

        try {
            $updated = $this->waiter->resolveCancellationRequest(
                $cr,
                $payload['decision'],
                $payload['reason'] ?? null,
                $user,
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'cancellation_request' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'reason' => $updated->reason,
                'resolved_at' => optional($updated->resolved_at)?->toIso8601String(),
            ],
        ]);
    }
}
