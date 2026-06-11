<?php

declare(strict_types=1);

namespace App\Http\Resources\Dian;

use App\Models\DianProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DianProviderConfig
 *
 * IMPORTANTE: NUNCA expongas los `*_encrypted` en GET. La UI ve solo metadatos
 * + flag "configurado" para que el owner sepa si necesita rotar.
 */
class DianProviderConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_slug' => $this->provider_slug,
            'api_base_url' => $this->api_base_url,
            'software_id' => $this->software_id,
            'test_set_id' => $this->test_set_id,
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'has_api_token' => filled($this->api_token_encrypted),
            'has_software_pin' => filled($this->software_pin_encrypted),
            'has_webhook_secret' => filled($this->webhook_secret_encrypted),
        ];
    }
}
