<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\CartSession;
use App\Models\Company;
use App\Services\CartJwtService;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Carrito publico del cliente accedido por URL con CartJwt.
 *
 * No usa el middleware de empresa porque el cliente no esta autenticado: la unica
 * credencial es el JWT de carrito firmado y cifrado por CartJwtService.
 *
 * - migrateJwt(): valida el JWT, crea/actualiza la CartSession por jti y retorna el carrito.
 * - show(): consulta de carrito ya migrado (polling del front).
 */
class CartController extends Controller
{
    /**
     * Resuelve CartJwtService perezosamente. Si CART_JWT_SECRET no está configurado,
     * devolvemos null y el caller responde 401 (mismo contrato que ValidateBotJwt).
     */
    private function resolveService(): ?CartJwtService
    {
        try {
            return Container::getInstance()->make(CartJwtService::class);
        } catch (RuntimeException $e) {
            Log::warning('[cart] CART_JWT_SECRET no configurado; rechazando.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function migrateJwt(string $jwt): JsonResponse
    {
        $service = $this->resolveService();
        if ($service === null) {
            return response()->json(['error' => 'JWT de carrito inválido o expirado.'], 401);
        }

        try {
            $payload = $service->verify($jwt);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }

        if ($this->companyBlocked($payload['company_nit'])) {
            return response()->json(['error' => 'Carrito no disponible.'], 404);
        }

        $session = CartSession::updateOrCreate(
            ['jwt_jti' => $payload['jti']],
            [
                'company_nit' => $payload['company_nit'],
                'client_phone' => $payload['client_phone'],
                'status' => 'active',
                'expired_at' => Carbon::createFromTimestamp($payload['exp']),
            ]
        );

        return response()->json([
            'data' => new CartResource($session->load('items')),
        ]);
    }

    public function show(Request $request, string $jwt): JsonResponse
    {
        $service = $this->resolveService();
        if ($service === null) {
            return response()->json(['error' => 'JWT de carrito inválido o expirado.'], 401);
        }

        try {
            $payload = $service->verify($jwt);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }

        if ($this->companyBlocked($payload['company_nit'] ?? null)) {
            return response()->json(['error' => 'Carrito no disponible.'], 404);
        }

        $session = CartSession::where('jwt_jti', $payload['jti'])
            ->with('items')
            ->first();

        if (! $session) {
            return response()->json(['error' => 'Carrito no encontrado.'], 404);
        }

        return response()->json([
            'data' => new CartResource($session),
        ]);
    }

    /**
     * Guard de empresa operativa para el carrito público.
     *
     * Si la empresa está bloqueada por mora, el comensal recibe 404
     * indistinguible de "carrito no encontrado", consistente con la
     * política de no revelar al cliente final el motivo comercial.
     * Un comensal con JWT de carrito previo a la suspensión NO debe
     * poder seguir operando contra una empresa que ya no atiende.
     */
    private function companyBlocked(?string $companyNit): bool
    {
        if (! is_string($companyNit) || $companyNit === '') {
            return false;
        }

        $company = Company::query()->where('nit', $companyNit)->first();

        return $company !== null && ! $company->canServePublic();
    }
}
