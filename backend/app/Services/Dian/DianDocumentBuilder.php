<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Services\Dian\DTOs\DocumentDto;
use App\Services\Dian\DTOs\LineItemDto;
use App\Services\Dian\DTOs\ReferencedDocumentDto;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Convierte una `Order` (o un par `Order`+`ReferencedDocument`) en un
 * `DocumentDto` neutral con todos los datos que el XML, el PDF y el CUFE
 * van a necesitar.
 *
 * - Items: usa `Order::orderItems()` materializados si están; cae a
 *   `Order::items` JSON cuando no.
 * - Desglose tributario por línea: hoy aplica una sola tasa global desde
 *   `Order::tax_rate` + `tax_regime`. Cuando se introduzca multi-régimen
 *   por item, esta lógica se extiende sin tocar `DianXmlBuilder` (DTO
 *   neutral).
 * - Régimen Simple (RST) → `tax_code='99'` (no responsable) por línea, sin
 *   IVA/INC. Régimen común → 'iva' (01) o 'inc' (04).
 * - Snapshot: lee del modelo activo en BD; el caller debe haber hecho
 *   `lockForUpdate` antes para asegurar consistencia.
 */
class DianDocumentBuilder
{
    public function __construct(
        private readonly RecipientResolver $recipientResolver,
    ) {}

    /**
     * @param  array{
     *   resolution_id: int, prefix: string, consecutive: int,
     *   full_number: string, technical_key: string, environment: string,
     * }  $allocation
     */
    public function build(
        Order $order,
        string $documentType,
        string $uniqueCodeType,
        array $allocation,
        ?ElectronicDocument $references = null,
        bool $isAutoEmit = false,
    ): DocumentDto {
        $company = $order->company()->first();

        if ($company === null) {
            throw new \RuntimeException("Order id={$order->id} no tiene company asociada.");
        }

        $recipient = $this->recipientResolver->resolveFromOrder($order, $isAutoEmit);

        $lines = $this->buildLines($order, $company);

        $issuedAt = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));

        $resolution = DianResolution::query()->findOrFail($allocation['resolution_id']);

        $referencesDto = $references !== null ? new ReferencedDocumentDto(
            id: (string) $references->getKey(),
            fullNumber: $references->full_number,
            uniqueCode: $references->unique_code,
            uniqueCodeType: $references->unique_code_type,
            issuedAt: DateTimeImmutable::createFromInterface($references->issued_at),
        ) : null;

        // Totales tributarios agregados desde líneas (defensa de
        // consistencia: la suma debe matchear `orders.total`).
        $subtotal = 0.0;
        $iva = 0.0;
        $inc = 0.0;
        $ica = 0.0;

        foreach ($lines as $line) {
            $subtotal += $line->lineSubtotal;
            match ($line->taxCode) {
                '01' => $iva += $line->taxAmount,
                '04' => $inc += $line->taxAmount,
                '03' => $ica += $line->taxAmount,
                default => null,
            };
        }

        return new DocumentDto(
            documentType: $documentType,
            unique_code_type: $uniqueCodeType,
            environment: $allocation['environment'],
            fullNumber: $allocation['full_number'],
            prefix: $allocation['prefix'],
            consecutive: $allocation['consecutive'],
            issuedAt: $issuedAt,
            currency: 'COP',
            issuerNit: (string) $company->nit,
            issuerDv: $company->dv,
            issuerLegalName: (string) $company->legal_name,
            issuerCommercialName: (string) $company->commercial_name,
            issuerEconomicActivityCode: $company->economic_activity_code,
            issuerFiscalResponsibilities: $company->fiscal_responsibilities ?? [],
            issuerMunicipalityCode: $company->municipality_dane_code,
            issuerAddress: $company->physical_address,
            issuerEmail: $company->billing_email,
            issuerPhone: $company->billing_phone,
            recipient: $recipient,
            lines: $lines,
            subtotal: round($subtotal, 2),
            discountAmount: (float) $order->discount_amount,
            taxableBase: round($subtotal - (float) $order->discount_amount, 2),
            ivaAmount: round($iva, 2),
            incAmount: round($inc, 2),
            icaAmount: round($ica, 2),
            tipAmount: (float) $order->tip_amount,
            total: (float) $order->total,
            resolutionId: (string) $resolution->getKey(),
            resolutionNumber: $resolution->resolution_number,
            technicalKey: $allocation['technical_key'],
            resolutionRangeFrom: (string) $resolution->range_from,
            resolutionRangeTo: (string) $resolution->range_to,
            references: $referencesDto,
            orderId: (string) $order->getKey(),
        );
    }

    /**
     * @return list<LineItemDto>
     */
    private function buildLines(Order $order, Company $company): array
    {
        $items = $order->relationLoaded('orderItems') && $order->orderItems->isNotEmpty()
            ? $order->orderItems->map(fn ($item) => [
                'name' => (string) $item->name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
            ])->all()
            : (array) ($order->items ?? []);

        $taxRate = (float) ($order->tax_rate ?? 0);
        $taxRegime = (string) ($order->tax_regime ?? $company->tax_regime ?? 'simple');
        $taxIncluded = (bool) ($order->tax_included_in_price ?? $company->tax_included_in_price ?? true);

        // En régimen simple no se reporta IVA/INC: las líneas van como tax_code='99'.
        $taxCode = match ($taxRegime) {
            'iva_19', 'iva_5', 'iva_exento' => '01',
            'inc_8' => '04',
            default => '99',
        };

        $lines = [];

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity <= 0) {
                continue;
            }

            $gross = round($price * $quantity, 2);

            if ($taxRate <= 0 || $taxCode === '99') {
                $lines[] = new LineItemDto(
                    name: (string) ($item['name'] ?? 'Item'),
                    quantity: $quantity,
                    unit: 'UNT',
                    unitPrice: $price,
                    lineSubtotal: $gross,
                    discountAmount: 0.0,
                    taxableBase: $gross,
                    taxCode: $taxCode,
                    taxRate: 0.0,
                    taxAmount: 0.0,
                    lineTotal: $gross,
                );

                continue;
            }

            // Cuando el precio incluye impuesto, descontamos para obtener la
            // base gravable: base = gross / (1 + rate/100), impuesto = gross - base.
            if ($taxIncluded) {
                $base = round($gross / (1 + ($taxRate / 100)), 2);
                $tax = round($gross - $base, 2);
            } else {
                $base = $gross;
                $tax = round($gross * ($taxRate / 100), 2);
            }

            $lines[] = new LineItemDto(
                name: (string) ($item['name'] ?? 'Item'),
                quantity: $quantity,
                unit: 'UNT',
                unitPrice: $price,
                lineSubtotal: $base,
                discountAmount: 0.0,
                taxableBase: $base,
                taxCode: $taxCode,
                taxRate: $taxRate,
                taxAmount: $tax,
                lineTotal: $base + $tax,
            );
        }

        return $lines;
    }
}
