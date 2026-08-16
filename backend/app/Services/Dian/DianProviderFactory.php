<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\DianProviderConfig;
use App\Services\Dian\Contracts\DianProviderContract;
use App\Services\Dian\Providers\MockDianProvider;
use RuntimeException;

/**
 * Factory que mapea `provider_slug` → instancia de `DianProviderContract`.
 *
 * Cuando se contrate un provider real, basta agregar el binding aquí + crear
 * la clase implementadora. APIs/UI/jobs no cambian.
 */
class DianProviderFactory
{
    public function make(DianProviderConfig $config): DianProviderContract
    {
        return match ($config->provider_slug) {
            'mock' => app(MockDianProvider::class),
            default => throw new RuntimeException("Provider DIAN no soportado: {$config->provider_slug}"),
        };
    }
}
