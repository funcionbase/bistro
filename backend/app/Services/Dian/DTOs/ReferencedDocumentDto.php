<?php

declare(strict_types=1);

namespace App\Services\Dian\DTOs;

use DateTimeImmutable;

/**
 * Referencia al documento original en notas crédito / débito.
 *
 * En UBL 2.1 Colombia el bloque `<cac:BillingReference>` apunta a la factura
 * original (CUFE o CUDE) cuando este documento es NC/ND.
 */
final readonly class ReferencedDocumentDto
{
    public function __construct(
        public string $id,
        public string $fullNumber,
        public string $uniqueCode,
        public string $uniqueCodeType,
        public DateTimeImmutable $issuedAt,
    ) {}
}
