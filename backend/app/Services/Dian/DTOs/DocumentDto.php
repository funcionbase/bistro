<?php

declare(strict_types=1);

namespace App\Services\Dian\DTOs;

use DateTimeImmutable;

/**
 * DTO neutral del documento DIAN.
 *
 * Lo produce `DianDocumentBuilder` desde una `Order` y se consume desde:
 *  - `CufeCudeGenerator` (computa el hash canónico).
 *  - `DianXmlBuilder` (renderiza UBL 2.1).
 *  - `DianRepresentationPdfBuilder` (renderiza PDF + QR).
 *  - `DianProviderContract::send` (lo envía al proveedor activo).
 *
 * Es plano e inmutable (constructor promovido + readonly). Cualquier mutación
 * requiere construir uno nuevo — refleja el snapshot del momento de emisión.
 */
final readonly class DocumentDto
{
    /**
     * @param  list<LineItemDto>  $lines
     * @param  array<int,string>  $issuerFiscalResponsibilities
     */
    public function __construct(
        public string $documentType,
        public string $unique_code_type,
        public string $environment,
        public string $fullNumber,
        public string $prefix,
        public int $consecutive,
        public DateTimeImmutable $issuedAt,
        public string $currency,
        // Issuer (emisor)
        public string $issuerNit,
        public ?string $issuerDv,
        public string $issuerLegalName,
        public string $issuerCommercialName,
        public ?string $issuerEconomicActivityCode,
        public array $issuerFiscalResponsibilities,
        public ?string $issuerMunicipalityCode,
        public ?string $issuerAddress,
        public ?string $issuerEmail,
        public ?string $issuerPhone,
        // Recipient (adquirente)
        public RecipientDto $recipient,
        // Lines (items)
        public array $lines,
        // Totals
        public float $subtotal,
        public float $discountAmount,
        public float $taxableBase,
        public float $ivaAmount,
        public float $incAmount,
        public float $icaAmount,
        public float $tipAmount,
        public float $total,
        // Resolution snapshot
        public string $resolutionId,
        public string $resolutionNumber,
        public string $technicalKey,
        public string $resolutionRangeFrom,
        public string $resolutionRangeTo,
        // Reference (NC/ND)
        public ?ReferencedDocumentDto $references = null,
        // Order ID (para audit/trace)
        public ?string $orderId = null,
    ) {}
}
