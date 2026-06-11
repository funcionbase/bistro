<?php

declare(strict_types=1);

namespace App\Services\Dian\DTOs;

/**
 * Respuesta del proveedor DIAN al `send(Document)`.
 *
 * Contrato neutral compartido por `MockDianProvider` y futuros providers
 * reales. El `DianDispatchService` consume estos campos y actualiza
 * `electronic_documents` sin saber qué provider los produjo.
 *
 * @phpstan-type ProviderStatus 'accepted'|'rejected'|'sent'|'error'
 */
final readonly class ProviderResponse
{
    /**
     * @param  array<string,mixed>  $log
     */
    public function __construct(
        public string $status,
        public string $trackId,
        public ?string $rejectionReason,
        public array $log,
    ) {}
}
