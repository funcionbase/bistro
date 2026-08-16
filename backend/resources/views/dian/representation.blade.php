@php
    /** @var \App\Services\Dian\DTOs\DocumentDto $dto */
    $documentTypeLabels = [
        'invoice' => 'Factura Electrónica de Venta',
        'credit_note' => 'Nota Crédito FEV',
        'debit_note' => 'Nota Débito FEV',
        'pos_equivalent' => 'Documento Equivalente Electrónico POS',
        'pos_equivalent_credit_note' => 'Nota Crédito DEE POS',
    ];
    $documentLabel = $documentTypeLabels[$dto->documentType] ?? 'Documento DIAN';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>{{ $dto->fullNumber }} — {{ $documentLabel }}</title>
    <style>
        @page { margin: 12mm 12mm 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; }
        h1 { font-size: 14pt; margin: 0; }
        h2 { font-size: 11pt; margin: 0 0 4px 0; color: #111827; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .header td { vertical-align: top; padding: 0; }
        .header td.qr { text-align: right; width: 110px; }
        .qr img { width: 96px; height: 96px; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 6px; border: 1px solid #e5e7eb; }
        .parties td { padding: 6px; vertical-align: top; border: 1px solid #e5e7eb; width: 50%; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lines th, table.lines td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        table.lines th { background: #f9fafb; text-align: left; font-size: 8.5pt; color: #374151; }
        table.lines td.r { text-align: right; }
        .totals { width: 50%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
        .totals td { padding: 3px 6px; }
        .totals td.r { text-align: right; }
        .totals tr.grand td { font-size: 11pt; font-weight: bold; border-top: 1px solid #1f2937; padding-top: 6px; }
        .cufe { margin-top: 12px; padding: 8px; background: #f3f4f6; word-break: break-all; font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; }
        .watermark { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; transform: rotate(-25deg); color: rgba(239,68,68,0.18); font-size: 56pt; font-weight: bold; }
        .footer { margin-top: 16px; font-size: 7.5pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    @if($environmentLabel)
        <div class="watermark">{{ $environmentLabel }}</div>
    @endif

    <table class="header">
        <tr>
            <td>
                <h1>{{ $dto->issuerCommercialName }}</h1>
                <div class="small muted">{{ $dto->issuerLegalName }}</div>
                <div class="small">NIT {{ $dto->issuerNit }}@if($dto->issuerDv)-{{ $dto->issuerDv }}@endif</div>
                @if($dto->issuerAddress)<div class="small">{{ $dto->issuerAddress }}</div>@endif
                @if($dto->issuerEmail || $dto->issuerPhone)
                    <div class="small">
                        @if($dto->issuerEmail){{ $dto->issuerEmail }}@endif
                        @if($dto->issuerEmail && $dto->issuerPhone) · @endif
                        @if($dto->issuerPhone){{ $dto->issuerPhone }}@endif
                    </div>
                @endif
                <div class="small muted" style="margin-top: 4px;">Responsabilidades: {{ implode(', ', $dto->issuerFiscalResponsibilities ?: ['R-99-PN']) }}</div>
            </td>
            <td style="text-align: center;">
                <h2>{{ $documentLabel }}</h2>
                <div><strong>{{ $dto->fullNumber }}</strong></div>
                <div class="small muted">Emisión: {{ $dto->issuedAt->format('Y-m-d H:i:s') }} ({{ $dto->environment }})</div>
                <div class="small muted">Resolución DIAN {{ $dto->resolutionNumber }}</div>
                <div class="small muted">Rango {{ $dto->prefix }}{{ $dto->resolutionRangeFrom }} a {{ $dto->prefix }}{{ $dto->resolutionRangeTo }}</div>
            </td>
            <td class="qr">
                <div class="qr"><img src="data:image/png;base64,{{ $qrBase64 }}" /></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <h2>Adquirente</h2>
                <div><strong>{{ $dto->recipient->legalName }}</strong></div>
                <div class="small">{{ $dto->recipient->docType }} {{ $dto->recipient->docNumber }}@if($dto->recipient->dv)-{{ $dto->recipient->dv }}@endif</div>
                @if($dto->recipient->email)<div class="small">{{ $dto->recipient->email }}</div>@endif
                @if($dto->recipient->address)<div class="small">{{ $dto->recipient->address }}</div>@endif
                @if($dto->recipient->isFinalConsumer())<div class="small muted" style="margin-top: 4px;">Consumidor final genérico (DIAN)</div>@endif
            </td>
            <td>
                <h2>Pago</h2>
                <div class="small">Moneda: {{ $dto->currency }}</div>
                <div class="small">Tipo: {{ $documentLabel }}</div>
                @if($dto->references)
                    <div class="small">Referencia: {{ $dto->references->fullNumber }}</div>
                    <div class="small" style="word-break: break-all;">{{ strtoupper($dto->references->uniqueCodeType) }}: {{ $dto->references->uniqueCode }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 32px;">#</th>
                <th>Descripción</th>
                <th style="width: 40px;">Cant.</th>
                <th class="r" style="width: 80px;">Precio</th>
                <th class="r" style="width: 70px;">Base</th>
                <th class="r" style="width: 60px;">Imp.</th>
                <th class="r" style="width: 80px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dto->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->name }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td class="r">{{ number_format($line->unitPrice, 2, '.', ',') }}</td>
                    <td class="r">{{ number_format($line->taxableBase, 2, '.', ',') }}</td>
                    <td class="r">{{ number_format($line->taxAmount, 2, '.', ',') }}</td>
                    <td class="r">{{ number_format($line->lineTotal, 2, '.', ',') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="r">{{ number_format($dto->subtotal, 2, '.', ',') }}</td></tr>
        @if($dto->discountAmount > 0)
            <tr><td>Descuento</td><td class="r">-{{ number_format($dto->discountAmount, 2, '.', ',') }}</td></tr>
        @endif
        <tr><td>Base gravable</td><td class="r">{{ number_format($dto->taxableBase, 2, '.', ',') }}</td></tr>
        @if($dto->ivaAmount > 0)
            <tr><td>IVA</td><td class="r">{{ number_format($dto->ivaAmount, 2, '.', ',') }}</td></tr>
        @endif
        @if($dto->incAmount > 0)
            <tr><td>INC</td><td class="r">{{ number_format($dto->incAmount, 2, '.', ',') }}</td></tr>
        @endif
        @if($dto->icaAmount > 0)
            <tr><td>ICA</td><td class="r">{{ number_format($dto->icaAmount, 2, '.', ',') }}</td></tr>
        @endif
        @if($dto->tipAmount > 0)
            <tr><td class="muted">Propina (no afecta total)</td><td class="r muted">{{ number_format($dto->tipAmount, 2, '.', ',') }}</td></tr>
        @endif
        <tr class="grand"><td>Total a pagar</td><td class="r">{{ number_format($dto->total, 2, '.', ',') }} {{ $dto->currency }}</td></tr>
    </table>

    <div class="cufe">
        <div class="small muted">{{ strtoupper($dto->unique_code_type) }} (SHA-384)</div>
        <div>{{ $cufeOrCude }}</div>
    </div>

    <div class="footer">
        Software: bistro · {{ $dto->environment === 'produccion' ? 'Producción' : 'Habilitación (pruebas)' }} ·
        Documento generado el {{ now('America/Bogota')->format('Y-m-d H:i:s') }} (UTC-5).
    </div>
</body>
</html>
