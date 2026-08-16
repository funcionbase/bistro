<?php

namespace Database\Seeders;

use App\Models\MetaPlatformCredential;
use Illuminate\Database\Seeder;

/**
 * Carga las credenciales de la app de Meta (Tech Provider/BSP de bistro).
 *
 * Lee SIEMPRE de variables de entorno (mismas keys que las de GitHub Actions
 * Variables del repo). Si una variable falta, todos los campos quedan en
 * placeholder visible para que se note en el log y el equipo los configure.
 *
 * Idempotente: una sola fila activa por ambiente. Vuelve a correrlo y los
 * valores se actualizan in-place via updateOrCreate.
 */
class MetaPlatformCredentialsSeeder extends Seeder
{
    public function run(): void
    {
        $environment = app()->environment('production') ? 'production' : 'qa';

        $configIdEnvKey = $environment === 'production' ? 'META_CONFIG_ID_PDN' : 'META_CONFIG_ID_QA';
        $verifyTokenEnvKey = $environment === 'production' ? 'META_WEBHOOK_VERIFY_TOKEN_PDN' : 'META_WEBHOOK_VERIFY_TOKEN_QA';

        $appId = env('META_APP_ID', '');
        $businessId = env('META_BUSINESS_ID', '');
        $systemUserId = env('META_SYSTEM_USER_ID', '');
        $configId = env($configIdEnvKey, '');
        $graphVersion = env('META_GRAPH_API_VERSION', 'v25.0');

        $appSecret = env('META_APP_SECRET');
        $systemUserToken = env('META_SYSTEM_USER_TOKEN');
        $verifyToken = env($verifyTokenEnvKey);

        if (! $appSecret) {
            $this->command?->warn('META_APP_SECRET ausente — usando placeholder. La validacion HMAC fallara hasta que configures el .env.');
            $appSecret = 'PLACEHOLDER_SET_META_APP_SECRET_IN_ENV';
        }

        if (! $systemUserToken) {
            $this->command?->warn('META_SYSTEM_USER_TOKEN ausente — usando placeholder.');
            $systemUserToken = 'PLACEHOLDER_SET_META_SYSTEM_USER_TOKEN_IN_ENV';
        }

        if (! $verifyToken) {
            $this->command?->info(
                "{$verifyTokenEnvKey} ausente — generando uno aleatorio (anotalo y configuralo en Meta Developer Console)."
            );
            $verifyToken = bin2hex(random_bytes(32));
            $this->command?->line("Verify token generado: {$verifyToken}");
        }

        MetaPlatformCredential::query()
            ->where('environment', $environment)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        MetaPlatformCredential::query()->updateOrCreate(
            ['environment' => $environment, 'is_active' => true],
            [
                'app_id' => $appId,
                'app_secret_encrypted' => $appSecret,
                'business_id' => $businessId,
                'system_user_id' => $systemUserId,
                'system_user_token_encrypted' => $systemUserToken,
                'config_id' => $configId,
                'webhook_verify_token_encrypted' => $verifyToken,
                'graph_api_version' => $graphVersion,
                'environment' => $environment,
                'is_active' => true,
                'rotated_at' => now(),
            ]
        );

        $this->command?->info("Credenciales Meta {$environment} cargadas (app_id={$appId}, config_id={$configId}).");
    }
}
