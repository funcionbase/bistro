<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Table\AddItemRequest;
use App\Http\Requests\Table\AddNoteRequest;
use App\Http\Requests\Table\UpdateItemRequest;
use App\Models\OrderItem;
use App\Models\TableSessionGuest;
use App\Services\TableOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Endpoints públicos del carrito del comensal — API REST.
 *
 * Sin auth — identidad resuelta por el middleware `table.guest` desde la
 * cookie `tdt_*`. Migrado desde `Web\TableOrderController` (SPA): la
 * lógica es idéntica, solo cambia el namespace (Web → Public) y el prefijo
 * de ruta (`/t/` → `/api/v1/public/table/`).
 *
 * El frontend SPA (`pages/table/menu.tsx`) consume estos endpoints con
 * fetch + polling cada 5s para reflejar aprobaciones del mesero.
 */
class TableOrderController extends Controller
{
    public function __construct(private readonly TableOrderService $orders) {}

    public function state(Request $request, string $qrToken): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        return response()->json($this->orders->stateFor($guest));
    }

    public function addItem(AddItemRequest $request, string $qrToken): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        try {
            $item = $this->orders->addItem(
                $guest,
                (string) $request->input('menu_item_id'),
                (int) $request->input('quantity'),
                $request->input('notes'),
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->name,
                'unit_price' => (string) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'status' => $item->status,
            ],
            'state' => $this->orders->stateFor($guest),
        ], 201);
    }

    public function updateItem(UpdateItemRequest $request, string $qrToken, string $itemId): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        // Scope estructural por el guest dueño del item. Antes se
        // resolvía por UUID global y la pertenencia dependía solo del guard en
        // el service; ahora un item ajeno es 404 en la query.
        $item = OrderItem::query()->whereKey($itemId)->where('guest_id', $guest->id)->firstOrFail();

        try {
            $updated = $this->orders->updateItem(
                $item,
                $guest,
                [
                    'notes' => $request->input('notes'),
                    'quantity' => $request->input('quantity'),
                ],
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'quantity' => (int) $updated->quantity,
                'notes' => $updated->notes,
                'status' => $updated->status,
            ],
            'state' => $this->orders->stateFor($guest),
        ]);
    }

    public function cancelItem(Request $request, string $qrToken, string $itemId): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        // Scope estructural por el guest dueño del item (ver updateItem).
        $item = OrderItem::query()->whereKey($itemId)->where('guest_id', $guest->id)->firstOrFail();
        $reason = $request->input('reason');

        try {
            $result = $this->orders->cancelItem(
                $item,
                $guest,
                is_string($reason) ? $reason : null,
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'kind' => $result['kind'],
            'cancellation_request' => $result['request'] ? [
                'id' => $result['request']->id,
                'status' => $result['request']->status,
                'reason' => $result['request']->reason,
            ] : null,
            'state' => $this->orders->stateFor($guest),
        ]);
    }

    public function submitBatch(Request $request, string $qrToken): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        try {
            $count = $this->orders->submitBatch($guest, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'items_submitted' => $count,
            'state' => $this->orders->stateFor($guest),
        ]);
    }

    public function addNote(AddNoteRequest $request, string $qrToken): JsonResponse
    {
        unset($qrToken);
        $guest = $this->requireGuest($request);

        try {
            $note = $this->orders->addNote(
                $guest,
                (string) $request->input('scope'),
                (string) $request->input('body'),
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'note' => [
                'id' => $note->id,
                'scope' => $note->scope,
                'body' => $note->body,
            ],
            'state' => $this->orders->stateFor($guest),
        ], 201);
    }

    private function requireGuest(Request $request): TableSessionGuest
    {
        /** @var TableSessionGuest|null $guest */
        $guest = $request->attributes->get('table_guest');
        if ($guest === null) {
            throw new HttpException(401, 'No estás en la mesa. Volvé a escanear el QR.');
        }

        return $guest;
    }
}
