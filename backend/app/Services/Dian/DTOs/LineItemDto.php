<?php

declare(strict_types=1);

namespace App\Services\Dian\DTOs;

/**
 * Línea del documento (item de la orden).
 *
 * Producida por `DianDocumentBuilder` desde `Order::items[]` o
 * `OrderItem`s materializados. Cada línea lleva su desglose
 * tributario propio porque DIAN exige `<cac:InvoiceLine>` con su
 * `<cac:TaxTotal>` por línea (la suma debe matchear `LegalMonetaryTotal`).
 */
final readonly class LineItemDto
{
    public function __construct(
        public string $name,
        public int $quantity,
        public string $unit,             // p.ej. "UNT" (DIAN: WR / KGM / UNT)
        public float $unitPrice,
        public float $lineSubtotal,
        public float $discountAmount,
        public float $taxableBase,
        public string $taxCode,          // 01 (IVA) | 04 (INC) | 03 (ICA) | 99 (no responsable)
        public float $taxRate,
        public float $taxAmount,
        public float $lineTotal,
    ) {}
}
