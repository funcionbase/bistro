<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\User;
use App\Services\Chat\ChatAuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ciclo de vida del canal de WhatsApp sobre Evolution (plan 8-whatsapp.md §6.5).
 *
 * El nombre de instancia es DETERMINISTA — `bistro-{env}-{nit}-{sede|company}` —
 * para que sea legible en los logs de Evolution y reconstruible sin consultar la
 * BD. El índice único parcial `cwa_evo_instance_unique` lo garantiza a nivel de
 * base.
 */
class EvolutionChannelService
{
    /** Eventos suscritos: los mínimos. El resto inundaría el webhook sin aportar. */
    private const EVENTS = [
        'QRCODE_UPDATED',
        'CONNECTION_UPDATE',
        'MESSAGES_UPSERT',
        'MESSAGES_UPDATE',
    ];

    /** El QR rota cada ~25 s; 90 s cubre el polling del wizard sin servir uno muerto. */
    private const QR_TTL_SECONDS = 90;

    public function __construct(
        private readonly EvolutionClient $client,
        private readonly ChatAuditLogger $auditLogger,
    ) {}

    public static function make(): self
    {
        return new self(EvolutionClient::default(), app(ChatAuditLogger::class));
    }

    /**
     * Actor del JWT ya validado. Queda `null` cuando la acción la dispara el
     * webhook o el scheduler, que es información en sí misma: distingue "lo
     * desconectó una persona" de "se cayó solo".
     */
    private function actor(): ?User
    {
        $payload = (array) request()->attributes->get('jwt_payload', []);

        return isset($payload['sub']) ? User::find((string) $payload['sub']) : null;
    }

    /**
     * Crea la fila del canal, la instancia en Evolution y registra el webhook.
     *
     * No conecta nada: deja el canal en `pending` a la espera de que alguien
     * escanee el QR. Es idempotente por el unique parcial de (empresa|sede).
     *
     * @return array{ok: bool, account?: CompanyWhatsappAccount, error?: string}
     */
    public function provision(Company $company, ?Branch $branch, ?string $label = null): array
    {
        $instance = $this->instanceName($company->nit, $branch);

        // Secreto propio por canal: es lo que autentica el webhook entrante. 32
        // bytes de aleatoriedad criptográfica, nunca derivado del NIT ni del id.
        $inboundSecret = bin2hex(random_bytes(32));

        $account = new CompanyWhatsappAccount;
        $account->forceFill([
            'company_nit' => $company->nit,
            'branch_id' => $branch?->id,
            'label' => $label ?? ($branch?->name ?? 'WhatsApp de la empresa'),
            'provisioning_mode' => 'self_service',
            'status' => 'pending',
            'is_business_verified' => false,
            'evo_instance' => $instance,
            'evo_server_url' => config('evolution.base_url'),
            'inbound_secret_encrypted' => $inboundSecret,
        ])->save();

        $created = $this->client->createInstance($instance);

        if (! ($created['ok'] ?? false)) {
            $account->delete();

            return ['ok' => false, 'error' => 'instance_create_failed'];
        }

        // Evolution devuelve el token de ESTA instancia; es el que firma la
        // mensajería. El global solo sirve para administrar instancias.
        $instanceToken = $created['hash'] ?? ($created['instance']['apikey'] ?? null);

        if (is_string($instanceToken) && $instanceToken !== '') {
            $account->forceFill(['evo_token_encrypted' => $instanceToken])->save();
        }

        $webhook = $this->client->setWebhook(
            $instance,
            rtrim((string) config('evolution.webhook.base_url'), '/').'/'.$account->id,
            [(string) config('evolution.webhook.header') => $inboundSecret],
            self::EVENTS,
        );

        if (! ($webhook['ok'] ?? false)) {
            // La instancia queda creada pero sin webhook: sin eso no entra un
            // solo mensaje. Se revierte entera para no dejar un canal mudo.
            $this->client->deleteInstance($instance);
            $account->delete();

            return ['ok' => false, 'error' => 'webhook_set_failed'];
        }

        $this->event($account, 'channel_provisioned', ['instance' => $instance]);

        // Auditoria de negocio (§7.6). Es la accion del USUARIO que inicia la
        // conexion; el `open` real lo confirma despues el webhook, sin actor.
        // Ni el secreto ni el token entran aca: solo identificadores.
        $this->auditLogger->log(
            action: 'whatsapp.channel.connected',
            user: $this->actor(),
            auditable: $account,
            data: [
                'channel_id' => $account->id,
                'company_nit' => $company->nit,
                'branch_id' => $branch?->id,
                'instance' => $instance,
            ],
        );

        return ['ok' => true, 'account' => $account];
    }

    /**
     * QR para vincular. Sale de la caché que llena el webhook `qrcode.updated`;
     * si no hay (primer pedido, o expiró), lo pide a Evolution.
     *
     * @return array{ok: bool, qr?: string, pairing_code?: ?string, error?: string}
     */
    public function qr(CompanyWhatsappAccount $account): array
    {
        // Quien ve el QR puede vincular el WhatsApp del cliente: es un secreto de
        // sesion, asi que el acceso se audita aunque sea una lectura. Dedupe de
        // 5 min porque el wizard hace polling mientras la pantalla esta abierta.
        $this->auditLogger->log(
            action: 'whatsapp.channel.qr_viewed',
            user: $this->actor(),
            auditable: $account,
            data: ['channel_id' => $account->id, 'company_nit' => $account->company_nit],
            dedupeKey: $account->id,
        );

        $cached = Cache::get($this->qrCacheKey($account));

        if (is_string($cached) && $cached !== '') {
            return ['ok' => true, 'qr' => $cached, 'pairing_code' => null];
        }

        $response = EvolutionClient::forAccount($account)->connect((string) $account->evo_instance);

        if (! ($response['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'connect_failed'];
        }

        $qr = $response['base64'] ?? ($response['qrcode']['base64'] ?? null);

        if (! is_string($qr) || $qr === '') {
            return ['ok' => false, 'error' => 'qr_unavailable'];
        }

        $this->cacheQr($account, $qr);

        return [
            'ok' => true,
            'qr' => $qr,
            'pairing_code' => $response['pairingCode'] ?? ($response['qrcode']['pairingCode'] ?? null),
        ];
    }

    /**
     * Código de 8 dígitos como alternativa al QR (sin cámara). Evolution solo lo
     * emite si la instancia se creó con `number`, así que si no existe hay que
     * recrearla apuntando al teléfono.
     *
     * @return array{ok: bool, pairing_code?: string, error?: string}
     */
    public function pairingCode(CompanyWhatsappAccount $account, string $phoneE164): array
    {
        $instance = (string) $account->evo_instance;
        $client = EvolutionClient::forAccount($account);

        $client->deleteInstance($instance);
        $created = $client->createInstance($instance, $phoneE164);

        if (! ($created['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'instance_create_failed'];
        }

        $token = $created['hash'] ?? ($created['instance']['apikey'] ?? null);
        if (is_string($token) && $token !== '') {
            $account->forceFill(['evo_token_encrypted' => $token])->save();
        }

        // Recrear la instancia borra su webhook: hay que volver a registrarlo o
        // el canal queda mudo.
        $client->setWebhook(
            $instance,
            rtrim((string) config('evolution.webhook.base_url'), '/').'/'.$account->id,
            [(string) config('evolution.webhook.header') => (string) $account->inboundSecret()],
            self::EVENTS,
        );

        $code = $created['pairingCode'] ?? ($created['qrcode']['pairingCode'] ?? null);

        if (! is_string($code) || $code === '') {
            return ['ok' => false, 'error' => 'pairing_code_unavailable'];
        }

        return ['ok' => true, 'pairing_code' => $code];
    }

    /**
     * Consulta el estado real contra Evolution y lo refleja en la fila. Lo usa el
     * poll de salud y el wizard.
     *
     * @return array{ok: bool, state: string}
     */
    public function syncState(CompanyWhatsappAccount $account): array
    {
        $response = EvolutionClient::forAccount($account)->connectionState((string) $account->evo_instance);

        if (! ($response['ok'] ?? false)) {
            $account->forceFill(['last_connection_check_at' => now()])->save();

            return ['ok' => false, 'state' => 'unknown'];
        }

        $state = (string) ($response['instance']['state'] ?? 'close');

        $account->forceFill([
            'status' => $this->statusFor($state),
            'last_connection_check_at' => now(),
        ] + ($state === 'open' && $account->connected_at === null ? ['connected_at' => now()] : []))->save();

        return ['ok' => true, 'state' => $state];
    }

    /**
     * Cierra la sesión en WhatsApp y hace soft-delete del canal. Los chats
     * sobreviven (`nullOnDelete` no aplica al soft-delete: la fila sigue ahí).
     *
     * Exige OTP en el caller — acá no se valida permiso.
     */
    public function disconnect(CompanyWhatsappAccount $account): bool
    {
        EvolutionClient::forAccount($account)->logout((string) $account->evo_instance);

        $account->forceFill([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ])->save();

        $this->event($account, 'channel_disconnected', ['instance' => $account->evo_instance]);

        $this->auditLogger->log(
            action: 'whatsapp.channel.disconnected',
            user: $this->actor(),
            auditable: $account,
            data: [
                'channel_id' => $account->id,
                'company_nit' => $account->company_nit,
                'branch_id' => $account->branch_id,
            ],
        );

        Cache::forget($this->qrCacheKey($account));

        // Soft-delete: libera el slot del unique parcial para reconectar.
        $account->delete();

        return true;
    }

    /** Borra la instancia en Evolution. Irreversible: pierde las credenciales de sesión. */
    public function destroy(CompanyWhatsappAccount $account): bool
    {
        $response = EvolutionClient::forAccount($account)->deleteInstance((string) $account->evo_instance);

        $this->event($account, 'channel_destroyed', [
            'instance' => $account->evo_instance,
            'ok' => $response['ok'] ?? false,
        ]);

        Cache::forget($this->qrCacheKey($account));
        $account->forceDelete();

        return (bool) ($response['ok'] ?? false);
    }

    /** `bistro-{env}-{nit}-{sede|company}` — determinista y legible en los logs. */
    public function instanceName(string $companyNit, ?Branch $branch): string
    {
        return implode('-', [
            (string) config('evolution.instance_prefix', 'bistro'),
            Str::slug((string) config('app.env')),
            Str::slug($companyNit),
            $branch !== null ? Str::slug((string) $branch->name).'-'.substr((string) $branch->id, -8) : 'company',
        ]);
    }

    public function cacheQr(CompanyWhatsappAccount $account, string $qrBase64): void
    {
        // El QR es un secreto de sesión efímero: va a caché, NUNCA a
        // `webhook_events` ni a ninguna tabla (§6.3).
        Cache::put($this->qrCacheKey($account), $qrBase64, self::QR_TTL_SECONDS);
    }

    public function qrCacheKey(CompanyWhatsappAccount $account): string
    {
        return "wa:qr:{$account->id}";
    }

    /** Mapa del enum de Evolution al vocabulario de estados de bistro (§8.6). */
    private function statusFor(string $state): string
    {
        return match ($state) {
            'open' => 'connected',
            'connecting' => 'verifying',
            default => 'disconnected',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(CompanyWhatsappAccount $account, string $type, array $payload): void
    {
        try {
            CompanyWhatsappAccountEvent::create([
                'company_whatsapp_account_id' => $account->id,
                'event_type' => $type,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // La bitácora no puede tumbar la operación que la genera.
            Log::channel('single')->warning('evolution.channel.event_failed', [
                'account_id' => $account->id,
                'type' => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
