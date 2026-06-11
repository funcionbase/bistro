<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\ElectronicDocument;

/**
 * Compositor de tirilla DIAN (DEE POS o FEV) sobre `EscposBuilder`.
 *
 * Resolución DIAN 165/2023 exige en la tirilla:
 *  - Datos del emisor (NIT+DV, razón social, dirección, resolución).
 *  - Datos del adquirente.
 *  - Desglose de items + base + impuestos.
 *  - QR escaneable apuntando al catálogo público DIAN.
 *  - CUFE/CUDE legible debajo del QR (legibilidad mínima 8pt).
 *  - Leyenda del régimen tributario.
 *
 * Soporta `paper_width` 58 (32 cols) y 80 (48 cols).
 */
class DianReceiptBuilder
{
    public function build(ElectronicDocument $document, int $paperWidthMm = 58): string
    {
        $document->loadMissing(['order', 'resolution', 'company']);

        $builder = EscposBuilder::forWidth($paperWidthMm);
        $company = $document->company;
        $order = $document->order;
        $resolution = $document->resolution;

        $isAccepted = $document->isAccepted();
        $documentLabel = match ($document->document_type) {
            'invoice' => 'FACTURA ELECTRONICA',
            'credit_note' => 'NOTA CREDITO FEV',
            'debit_note' => 'NOTA DEBITO FEV',
            'pos_equivalent_credit_note' => 'NOTA CREDITO POS',
            default => 'DOC. EQUIV. POS',
        };

        $builder->init()
            ->alignCenter()
            ->boldOn()->doubleSize()
            ->text($company->commercial_name)
            ->normalSize()->boldOff()
            ->text($company->legal_name)
            ->text('NIT '.$company->nit.($company->dv ? '-'.$company->dv : ''))
            ->text((string) ($company->physical_address ?: ''))
            ->text('Resp. '.implode(', ', $company->fiscal_responsibilities ?: ['R-99-PN']))
            ->rule('=')
            ->boldOn()
            ->text($documentLabel)
            ->text($document->full_number)
            ->boldOff()
            ->text('Resolucion DIAN '.$resolution->resolution_number)
            ->text('Rango '.$resolution->prefix.$resolution->range_from.' a '.$resolution->prefix.$resolution->range_to)
            ->text('Vigencia '.$resolution->valid_until->format('Y-m-d'))
            ->text($document->dian_environment_code === 'produccion' ? 'AMBIENTE PRODUCCION' : 'AMBIENTE HABILITACION')
            ->rule('=')
            ->alignLeft();

        // Adquirente
        $builder->boldOn()->text('ADQUIRENTE')->boldOff()
            ->text(($order?->billing_legal_name ?: 'CONSUMIDOR FINAL'));

        if ($order?->billing_doc_number) {
            $builder->text(($order->billing_doc_type ?: 'CC').' '.$order->billing_doc_number.($order->billing_dv ? '-'.$order->billing_dv : ''));
        }
        if ($order?->billing_address) {
            $builder->text($order->billing_address);
        }

        $builder->rule('-')->boldOn()->text('ITEMS')->boldOff();

        // Items: usamos snapshot de orders.items (JSON)
        $items = (array) ($order?->items ?? []);
        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? 'Item');
            $qty = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $line = round($price * $qty, 2);
            $builder->text("$qty x $name");
            $builder->twoCols('   @ '.number_format($price, 2, '.', ','), '$'.number_format($line, 2, '.', ','));
        }

        $builder->rule('-');

        if ($order) {
            $builder->twoCols('Subtotal', '$'.number_format((float) $order->subtotal, 2, '.', ','));

            if ((float) $order->discount_amount > 0) {
                $builder->twoCols('Descuento', '-$'.number_format((float) $order->discount_amount, 2, '.', ','));
            }
            if ((float) $order->tax_amount > 0) {
                $builder->twoCols('Impuesto ('.number_format((float) $order->tax_rate, 2, '.', ',').'%)', '$'.number_format((float) $order->tax_amount, 2, '.', ','));
            }
            if ((float) $order->tip_amount > 0) {
                $builder->twoCols('Propina', '$'.number_format((float) $order->tip_amount, 2, '.', ','));
            }
            $builder->boldOn()->twoCols('TOTAL', '$'.number_format((float) $order->total, 2, '.', ','))->boldOff();
        }

        $builder->rule('-')
            ->alignCenter();

        if (! $isAccepted) {
            $builder->text('--- DOCUMENTO '.strtoupper($document->status).' ---');
        }

        $builder->qrCode($document->qr_data ?? '', size: $paperWidthMm === 80 ? 7 : 5)
            ->text(strtoupper($document->unique_code_type).':')
            ->text(substr($document->unique_code, 0, 48))
            ->text(substr($document->unique_code, 48))
            ->rule('-')
            ->text('Valide el documento en')
            ->text('catalogo-vpfe'.($document->dian_environment_code === 'produccion' ? '' : '-hab').'.dian.gov.co')
            ->feed(1)
            ->text('Gracias por su compra')
            ->cut();

        return $builder->getBuffer();
    }
}
