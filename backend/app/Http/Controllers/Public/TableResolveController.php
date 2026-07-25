<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CartSession;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público (sin auth) que resuelve una mesa por NIT + número y
 * reporta si hay una sesión grupal activa (#191).
 *
 * Usado por el menú público (`/menus/{nit}?table=N`) para decidir si
 * ofrecer al cliente "Unirme a esta mesa" (flujo `/t/{qr_token}`).
 *
 * Resolución de sede: si la empresa tiene una `is_default=true` no
 * archivada, se usa esa. Si no, la primera sede no archivada por
 * `created_at`. Si la empresa no tiene sedes activas, 404.
 */
class TableResolveController extends Controller
{
    /**
     * Resuelve una sede por su `menu_qr_token` (QR de menú de sede:
     * `/menus?branch={menu_qr_token}`). Devuelve NIT + branch_id para que
     * el SPA pueda cargar el menú correcto sin exponer el UUID en la URL.
     */
    public function showBranchByToken(string $menuQrToken): JsonResponse
    {
        $branch = Branch::query()
            ->where('menu_qr_token', $menuQrToken)
            ->whereNull('archived_at')
            ->first();

        if ($branch === null) {
            return response()->json(['branch_exists' => false], 404);
        }

        $company = Company::query()->where('nit', $branch->company_nit)->first();
        if ($company === null || ! $company->canServePublic()) {
            return response()->json(['branch_exists' => false], 404);
        }

        return response()->json([
            'branch_exists' => true,
            'company_nit' => $branch->company_nit,
            'branch_id' => (string) $branch->id,
            'branch_name' => $branch->name,
        ]);
    }

    /**
     * Resuelve una sesión de carta enviada desde /chats (`/menus?cart={uuid}`).
     *
     * Devuelve el contexto para cargar la carta de la sede (NIT, branch,
     * `menu_qr_token` para el checkout) + prefill del cliente (teléfono sin
     * indicativo y nombre del Contact si existe). El token es un bearer que
     * viaja por el WhatsApp del propio cliente; el prefill es su propio dato.
     *
     * Si la sesión expiró, el contexto se devuelve igual con `expired: true`:
     * el cliente puede seguir pidiendo, solo se pierde el vínculo con el chat.
     */
    public function showCartSession(string $token): JsonResponse
    {
        // Escape del BranchScope: flujo público sin JWT. La sesión es un
        // bearer único (jwt_jti UNIQUE) creado por el operador del chat.
        $session = CartSession::withoutGlobalScopes()
            ->where('jwt_jti', $token)
            ->first();

        if ($session === null) {
            return response()->json(['session_exists' => false], 404);
        }

        $branch = Branch::query()
            ->whereKey($session->branch_id)
            ->whereNull('archived_at')
            ->first();

        $company = Company::query()->where('nit', $session->company_nit)->first();
        if ($branch === null || $company === null || ! $company->canServePublic()) {
            return response()->json(['session_exists' => false], 404);
        }

        $expired = $session->status !== 'active' || $session->expired_at->isPast();

        // Tracking (F3): primer open marca viewed_at — el chat muestra "abrió
        // la carta". Idempotente, sin audit (telemetría, no operación).
        if ($session->viewed_at === null) {
            $session->viewed_at = now();
            $session->last_activity_at = now();
            $session->save();
        }

        $contact = Contact::withoutBranchScope()
            ->where('company_nit', $session->company_nit)
            ->where('phone', $session->client_phone)
            ->first(['name']);

        // El form de checkout pide el celular SIN indicativo (10 dígitos).
        $phone = (string) $session->client_phone;
        $localPhone = str_starts_with($phone, '57') && strlen($phone) === 12
            ? substr($phone, 2)
            : $phone;

        return response()->json([
            'session_exists' => true,
            'expired' => $expired,
            'company_nit' => $session->company_nit,
            'branch_id' => (string) $session->branch_id,
            'menu_qr_token' => (string) $branch->menu_qr_token,
            'prefill' => [
                'phone' => $localPhone,
                'name' => $contact?->name,
            ],
        ]);
    }

    /**
     * Resuelve una mesa por su `qr_token` opaco (nuevo formato de QR:
     * `/menus?table={qr_token}`). Devuelve el mismo payload que `show()`
     * más `company_nit` para que el frontend pueda cargar el menú sin
     * exponer el NIT en la URL escaneada.
     */
    public function showByToken(string $qrToken): JsonResponse
    {
        $table = Table::withoutBranchScope()
            ->where('qr_token', $qrToken)
            ->whereNull('archived_at')
            ->first();

        if ($table === null) {
            return response()->json(['table_exists' => false], 404);
        }

        // Sede archivada = QR muerto (paridad con show/showBranchByToken y
        // TableSessionService::resolveTable — archivar una sede no archiva
        // sus mesas, así que hay que chequearlo acá).
        $branch = Branch::query()
            ->whereKey($table->branch_id)
            ->whereNull('archived_at')
            ->first();
        if ($branch === null) {
            return response()->json(['table_exists' => false], 404);
        }

        $nit = $branch->company_nit;
        $company = Company::query()->where('nit', $nit)->first();

        if ($company === null || ! $company->canServePublic()) {
            return response()->json(['table_exists' => false], 404);
        }

        $activeSession = TableSession::withoutBranchScope()
            ->where('table_id', $table->id)
            ->whereIn('status', config('tables.active_statuses'))
            ->withCount('guests')
            ->first();

        $waiterOrderActive = Order::withoutGlobalScopes()
            ->where('company_nit', $nit)
            ->where('branch_id', $branch->id)
            ->where('order_type', 'table')
            ->where('table_number', $table->number)
            ->whereNull('table_session_id')
            ->whereIn('status', ['pending', 'in_kitchen', 'ready', 'pending_approval'])
            ->exists();

        return response()->json([
            'table_exists' => true,
            'company_nit' => $nit,
            'qr_token' => $table->qr_token,
            'table_number' => $table->number,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'active_session' => $activeSession ? [
                'id' => $activeSession->id,
                'guests_count' => (int) $activeSession->guests_count,
                'accepts_new_guests' => (bool) $activeSession->accepts_new_guests,
                'opened_at' => optional($activeSession->opened_at)?->toIso8601String(),
            ] : null,
            'waiter_order_active' => $waiterOrderActive,
        ]);
    }

    public function show(Request $request, string $nit, string $tableNumber): JsonResponse
    {
        unset($request);
        $company = Company::query()->where('nit', $nit)->first();
        if ($company === null) {
            return response()->json(['table_exists' => false], 404);
        }

        // Empresa bloqueada por mora — respuesta indistinguible de "no
        // existe la mesa" para no revelar al comensal el motivo comercial.
        if (! $company->canServePublic()) {
            return response()->json(['table_exists' => false], 404);
        }

        $branch = Branch::query()
            ->where('company_nit', $nit)
            ->whereNull('archived_at')
            ->where('is_default', true)
            ->first()
            ?? Branch::query()
                ->where('company_nit', $nit)
                ->whereNull('archived_at')
                ->orderBy('created_at')
                ->first();

        if ($branch === null) {
            return response()->json(['table_exists' => false], 404);
        }

        // Scope escape justificado (#192): endpoint público sin JWT. La sede
        // se resolvió arriba a partir del NIT; el filtro explícito por
        // branch_id ya garantiza el aislamiento que BranchScope normalmente
        // haría desde el request.
        $table = Table::withoutBranchScope()
            ->where('branch_id', $branch->id)
            ->where('number', $tableNumber)
            ->whereNull('archived_at')
            ->first();

        if ($table === null) {
            return response()->json(['table_exists' => false], 404);
        }

        // Misma justificación: público sin JWT — el filtro por `table_id`
        // ya delimita la sede correctamente.
        $activeSession = TableSession::withoutBranchScope()
            ->where('table_id', $table->id)
            ->whereIn('status', config('tables.active_statuses'))
            ->withCount('guests')
            ->first();

        // Detectar si el mesero tomó una orden manualmente (vía /orders/board
        // o /orders/cashier) sin pasar por el flujo QR. Eso pasa cuando hay
        // una orden con order_type=table y table_number coincidente pero SIN
        // table_session_id — la mesa está físicamente ocupada por clientes
        // que ya pidieron al mesero. En ese caso bloqueamos el pedido por QR
        // para evitar duplicar comanda y le pedimos al cliente que hable con
        // el mesero.
        $waiterOrderActive = Order::withoutGlobalScopes()
            ->where('company_nit', $nit)
            ->where('branch_id', $branch->id)
            ->where('order_type', 'table')
            ->where('table_number', $table->number)
            ->whereNull('table_session_id')
            ->whereIn('status', ['pending', 'in_kitchen', 'ready', 'pending_approval'])
            ->exists();

        return response()->json([
            'table_exists' => true,
            'qr_token' => $table->qr_token,
            'table_number' => $table->number,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'active_session' => $activeSession ? [
                'id' => $activeSession->id,
                'guests_count' => (int) $activeSession->guests_count,
                'accepts_new_guests' => (bool) $activeSession->accepts_new_guests,
                'opened_at' => optional($activeSession->opened_at)?->toIso8601String(),
            ] : null,
            // Si true, la UI del QR debe mostrar un mensaje "contactá al
            // mesero" en lugar del CTA para abrir o unirse a la mesa.
            'waiter_order_active' => $waiterOrderActive,
        ]);
    }
}
