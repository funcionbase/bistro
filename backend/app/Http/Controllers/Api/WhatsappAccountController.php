<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use App\Models\MetaPlatformCredential;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\Whatsapp\MetaGraphApiClient;
use App\Services\Whatsapp\WhatsappAccountService;
use App\Services\Whatsapp\WhatsappVerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Acciones sobre la cuenta de WhatsApp de la empresa activa.
 *
 *   GET    /api/v1/whatsapp                          (whatsapp.read)
 *   POST   /api/v1/whatsapp/embedded-signup-callback (whatsapp.connect + verif)
 *   POST   /api/v1/whatsapp/naas-request             (whatsapp.connect + verif)
 *   DELETE /api/v1/whatsapp/phone                    (whatsapp.swap_phone + verif)
 *   DELETE /api/v1/whatsapp                          (whatsapp.disconnect + verif)
 *
 * Las acciones que mutan la cuenta requieren un codigo de verificacion enviado
 * al owner. El codigo viaja en el header `X-Whatsapp-Verification-Code`.
 */
class WhatsappAccountController extends Controller
{
    public function __construct(
        private readonly WhatsappVerificationCodeService $verificationService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $account = CompanyWhatsappAccount::query()->where('company_nit', $companyNit)->first();
        $platform = MetaPlatformCredential::activeForCurrentEnvironment();

        return response()->json([
            'data' => [
                'connected' => $account?->isConnected() ?? false,
                'status' => $account?->status ?? 'not_connected',
                'provisioning_mode' => $account?->provisioning_mode,
                'phone_e164' => $account?->phone_e164,
                'display_name' => $account?->display_name,
                'display_name_status' => $account?->display_name_status,
                'quality_rating' => $account?->quality_rating,
                'messaging_tier' => $account?->messaging_tier,
                'is_business_verified' => $account?->is_business_verified ?? false,
                'connected_at' => $account?->connected_at?->toIso8601String(),
                'last_synced_at' => $account?->last_synced_at?->toIso8601String(),
                'last_error' => $account?->last_error,
            ],
            'meta' => [
                'config_id' => $platform?->config_id,
                'app_id' => $platform?->app_id,
                'graph_api_version' => $platform?->graph_api_version,
                'environment' => $platform?->environment,
            ],
        ]);
    }

    public function embeddedSignupCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'waba_id' => ['required', 'string', 'max:50'],
            'phone_number_id' => ['required', 'string', 'max:50'],
            'business_id' => ['nullable', 'string', 'max:50'],
            'phone_e164' => ['nullable', 'string', 'max:30'],
            'display_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
        ]);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $user = $this->user($request);

        $this->consumeVerificationCode($request, 'connect');

        $service = $this->makeAccountService();

        $account = $service->completeEmbeddedSignup($companyNit, $validated, $user->id);

        return response()->json([
            'data' => $account->only([
                'company_nit', 'status', 'provisioning_mode', 'waba_id',
                'phone_number_id', 'phone_e164', 'display_name',
            ]),
        ], 201);
    }

    public function naasRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'naas_provider' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 2000, allowWhitespace: true)],
            'preferred_display_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
        ]);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $user = $this->user($request);

        $this->consumeVerificationCode($request, 'connect');

        $service = $this->makeAccountService();
        $account = $service->createNaasRequest($companyNit, $validated, $user->id);

        return response()->json(['data' => ['status' => $account->status]], 202);
    }

    public function deletePhone(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $account = $this->findAccountOrFail($companyNit);
        $user = $this->user($request);

        if (! $user->can('swapPhone', $account)) {
            return response()->json(['message' => 'Solo el propietario puede cambiar el numero.'], 403);
        }

        $this->consumeVerificationCode($request, 'swap');

        $this->makeAccountService()->deletePhoneAndPrepareSwap($account, $user->id);

        return response()->json(['data' => ['status' => 'pending']]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $account = $this->findAccountOrFail($companyNit);
        $user = $this->user($request);

        if (! $user->can('disconnect', $account)) {
            return response()->json(['message' => 'Solo el propietario puede desconectar WhatsApp.'], 403);
        }

        $this->consumeVerificationCode($request, 'disconnect');

        $this->makeAccountService()->disconnect($account, $user->id);

        return response()->json(['data' => ['status' => 'disconnected']]);
    }

    private function consumeVerificationCode(Request $request, string $action): void
    {
        $code = (string) $request->header('X-Whatsapp-Verification-Code', '');

        if ($code === '') {
            abort(response()->json(['message' => 'Falta el codigo de verificacion.'], 422));
        }

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();
        $user = $this->user($request);

        try {
            $this->verificationService->verify($company, $user, $action, $code);
        } catch (RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    private function findAccountOrFail(string $companyNit): CompanyWhatsappAccount
    {
        $account = CompanyWhatsappAccount::query()->where('company_nit', $companyNit)->first();

        if ($account === null) {
            abort(response()->json(['message' => 'Esta empresa no tiene WhatsApp conectado.'], 404));
        }

        return $account;
    }

    private function makeAccountService(): WhatsappAccountService
    {
        return new WhatsappAccountService(MetaGraphApiClient::forCurrentEnvironment());
    }

    private function user(Request $request): User
    {
        $payload = $request->attributes->get('jwt_payload');

        return User::query()->findOrFail($payload['sub'] ?? null);
    }
}
