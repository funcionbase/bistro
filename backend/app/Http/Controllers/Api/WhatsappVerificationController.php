<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappVerificationCode;
use App\Services\Whatsapp\WhatsappVerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints del flujo de codigo de verificacion para acciones sensibles.
 *
 *   POST /api/v1/whatsapp/verification/request  → genera y envia codigo al owner
 *   POST /api/v1/whatsapp/verification/verify   → pre-valida codigo (no consume)
 *   GET  /api/v1/whatsapp/verification/reject   → "No fui yo" (publico, vive en el correo)
 */
class WhatsappVerificationController extends Controller
{
    public function __construct(
        private readonly WhatsappVerificationCodeService $service,
    ) {}

    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', WhatsappVerificationCode::ACTIONS)],
        ]);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();
        $user = $this->user($request);

        try {
            $record = $this->service->request(
                company: $company,
                requester: $user,
                action: $validated['action'],
                ip: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'expires_at' => $record->expires_at->toIso8601String(),
                'owner_email_masked' => $this->maskEmail($record->owner->email ?? ''),
                'attempts_allowed' => WhatsappVerificationCode::MAX_ATTEMPTS,
            ],
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', WhatsappVerificationCode::ACTIONS)],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();
        $user = $this->user($request);

        try {
            $this->service->verify(
                company: $company,
                requester: $user,
                action: $validated['action'],
                code: $validated['code'],
                consume: false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['valid' => true]]);
    }

    /**
     * Endpoint publico (linkeado desde el correo). Sin JWT.
     */
    public function reject(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return response('Token faltante.', 400);
        }

        $record = $this->service->rejectByToken($token);

        if ($record === null) {
            return response('El codigo ya no esta activo o no existe.', 410);
        }

        return response('Solicitud rechazada. El codigo fue invalidado y se notifico al equipo de soporte.', 200);
    }

    private function user(Request $request): User
    {
        $payload = $request->attributes->get('jwt_payload');

        return User::query()->findOrFail($payload['sub'] ?? null);
    }

    private function maskEmail(string $email): string
    {
        $atPos = strpos($email, '@');

        if ($atPos === false || $atPos < 2) {
            return $email;
        }

        return substr($email, 0, 2).str_repeat('*', max(1, $atPos - 2)).substr($email, $atPos);
    }
}
