<?php

namespace App\Services\Whatsapp;

use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logica de negocio sobre CompanyWhatsappAccount. El controller solo orquesta
 * permisos + verificacion + body validation y delega aqui.
 *
 * Idempotencia: connect/swap usan upsert por company_nit asi que si el cliente
 * cierra el popup a medio camino y vuelve a iniciar, sigue trabajando sobre la
 * misma fila — solo se actualizan los campos del nuevo intento.
 */
class WhatsappAccountService
{
    public function __construct(
        private readonly ?MetaGraphApiClient $graph,
    ) {}

    /**
     * Procesa el callback de Embedded Signup. El frontend nos pasa los IDs
     * que devolvio Meta (waba_id, phone_number_id, business_id) y el `code`
     * de autorizacion del Login con Facebook.
     *
     * Aqui:
     *   1) Intercambiamos `code` por access token del cliente.
     *   2) Suscribimos la app al WABA para recibir webhooks.
     *   3) Persistimos la fila como `connected` (upsert por company_nit).
     */
    public function completeEmbeddedSignup(
        string $companyNit,
        array $payload,
        ?string $actorUserId = null,
    ): CompanyWhatsappAccount {
        $accessToken = $payload['access_token'] ?? null;

        // En QA podemos skippear el intercambio si el frontend ya nos pasa el
        // token (test mode). En produccion siempre va por OAuth.
        if (! $accessToken && ! empty($payload['code']) && $this->graph !== null) {
            $tokenResponse = $this->graph->exchangeCodeForAccessToken($payload['code']);
            $accessToken = $tokenResponse['access_token'] ?? null;
        }

        $account = DB::transaction(function () use ($companyNit, $payload, $accessToken, $actorUserId) {
            /** @var CompanyWhatsappAccount $account */
            $account = CompanyWhatsappAccount::query()->updateOrCreate(
                ['company_nit' => $companyNit],
                [
                    'provisioning_mode' => 'embedded_signup',
                    'status' => 'connected',
                    'waba_id' => $payload['waba_id'] ?? null,
                    'phone_number_id' => $payload['phone_number_id'] ?? null,
                    'business_id' => $payload['business_id'] ?? null,
                    'phone_e164' => $payload['phone_e164'] ?? null,
                    'display_name' => $payload['display_name'] ?? null,
                    'access_token_encrypted' => $accessToken,
                    'connected_at' => now(),
                    'disconnected_at' => null,
                    'last_error' => null,
                ]
            );

            CompanyWhatsappAccountEvent::create([
                'company_whatsapp_account_id' => $account->id,
                'event_type' => 'embedded_signup_completed',
                'payload' => [
                    'waba_id' => $payload['waba_id'] ?? null,
                    'phone_number_id' => $payload['phone_number_id'] ?? null,
                ],
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
            ]);

            return $account;
        });

        $this->subscribeWebhookSafely($account);

        return $account->fresh();
    }

    public function deletePhoneAndPrepareSwap(CompanyWhatsappAccount $account, ?string $actorUserId = null): void
    {
        $phoneNumberId = $account->phone_number_id;
        $token = $account->accessToken();

        if ($phoneNumberId !== null && $token !== null && $this->graph !== null) {
            try {
                $this->graph->deletePhoneNumber($phoneNumberId, $token);
            } catch (\Throwable $e) {
                Log::channel('single')->warning('whatsapp.swap.delete_phone_failed', [
                    'company_nit' => $account->company_nit,
                    'error' => $e->getMessage(),
                ]);
                $account->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }

        $account->forceFill([
            'status' => 'pending',
            'phone_number_id' => null,
            'phone_e164' => null,
            'display_name' => null,
            'display_name_status' => null,
        ])->save();

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'swap_initiated',
            'payload' => ['previous_phone_number_id' => $phoneNumberId],
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }

    public function disconnect(CompanyWhatsappAccount $account, ?string $actorUserId = null): void
    {
        $phoneNumberId = $account->phone_number_id;
        $token = $account->accessToken();

        if ($phoneNumberId !== null && $token !== null && $this->graph !== null) {
            try {
                $this->graph->deletePhoneNumber($phoneNumberId, $token);
            } catch (\Throwable $e) {
                Log::channel('single')->warning('whatsapp.disconnect.delete_phone_failed', [
                    'company_nit' => $account->company_nit,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($account, $actorUserId): void {
            $account->forceFill([
                'status' => 'disconnected',
                'disconnected_at' => now(),
            ])->save();

            CompanyWhatsappAccountEvent::create([
                'company_whatsapp_account_id' => $account->id,
                'event_type' => 'disconnected',
                'payload' => null,
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
            ]);

            $account->delete(); // soft delete (auditoria preservada)
        });
    }

    public function createNaasRequest(string $companyNit, array $payload, ?string $actorUserId = null): CompanyWhatsappAccount
    {
        return DB::transaction(function () use ($companyNit, $payload, $actorUserId) {
            /** @var CompanyWhatsappAccount $account */
            $account = CompanyWhatsappAccount::query()->updateOrCreate(
                ['company_nit' => $companyNit],
                [
                    'provisioning_mode' => 'naas',
                    'status' => 'pending',
                    'naas_provider' => $payload['naas_provider'] ?? null,
                    'last_error' => null,
                ]
            );

            CompanyWhatsappAccountEvent::create([
                'company_whatsapp_account_id' => $account->id,
                'event_type' => 'naas_requested',
                'payload' => $payload,
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
            ]);

            return $account;
        });
    }

    private function subscribeWebhookSafely(CompanyWhatsappAccount $account): void
    {
        if ($this->graph === null || $account->waba_id === null) {
            return;
        }
        $token = $account->accessToken();
        if ($token === null) {
            return;
        }

        try {
            $this->graph->subscribeAppToWaba($account->waba_id, $token);
            $account->forceFill(['webhook_subscribed_at' => now()])->save();

            CompanyWhatsappAccountEvent::create([
                'company_whatsapp_account_id' => $account->id,
                'event_type' => 'webhook_subscribed',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('whatsapp.subscribe_webhook_failed', [
                'company_nit' => $account->company_nit,
                'error' => $e->getMessage(),
            ]);
            $account->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}
