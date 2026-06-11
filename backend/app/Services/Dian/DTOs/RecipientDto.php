<?php

declare(strict_types=1);

namespace App\Services\Dian\DTOs;

/**
 * Adquirente del documento DIAN.
 *
 * Producido por `RecipientResolver` aplicando la cascada §5.3 del
 * refinamiento: lookup por phone → datos del modal → default de empresa →
 * CONSUMIDOR FINAL DIAN estándar.
 *
 * @phpstan-type RecipientType 'person'|'company'|'final_consumer'
 */
final readonly class RecipientDto
{
    /**
     * @param  array<int,string>  $fiscalResponsibilities
     */
    public function __construct(
        public string $docType,
        public string $docNumber,
        public ?string $dv,
        public string $legalName,
        public ?string $email,
        public ?string $address,
        public ?string $municipalityCode,
        public array $fiscalResponsibilities,
        public string $recipientType,
    ) {}

    public function isFinalConsumer(): bool
    {
        return $this->recipientType === 'final_consumer';
    }
}
