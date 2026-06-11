<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Factura #{{ $invoice->id }} — {{ $company->commercial_name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: {{ config('pdf.font_size', 10) }}pt;
            color: #1E232E;
            background: #ffffff;
            padding: 32px 40px;
        }

        .header-bar { border-bottom: 3px solid #0052FF; padding-bottom: 16px; margin-bottom: 24px; }
        .header-bar table { width: 100%; border-collapse: collapse; }
        .brand { font-size: 20pt; font-weight: 700; color: #0052FF; }
        .brand-sub { font-size: 9pt; color: #6B7280; }
        .invoice-title { font-size: 18pt; font-weight: 700; color: #1E232E; text-align: right; }
        .invoice-meta { font-size: 8.5pt; color: #6B7280; text-align: right; line-height: 1.8; }

        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .info-grid td { vertical-align: top; width: 50%; padding-right: 16px; }
        .info-block { background: #f6f5f3; border: 1px solid #e5e5e5; border-radius: 4px; padding: 12px 16px; }
        .info-block .label { font-size: 7.5pt; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-block .value { font-size: 10pt; font-weight: 700; color: #1E232E; }
        .info-block .sub { font-size: 8.5pt; color: #6B7280; }

        h2 {
            font-size: 11pt; font-weight: 700; color: #1E232E;
            margin-bottom: 10px; padding-bottom: 4px;
            border-bottom: 1px solid #e5e5e5;
        }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.data thead { display: table-header-group; }
        table.data th {
            background: #0052FF; color: #ffffff;
            font-weight: 700; font-size: 8pt;
            padding: 8px 12px; text-align: left;
        }
        table.data td { padding: 7px 12px; border-bottom: 1px solid #e5e5e5; font-size: 9pt; vertical-align: middle; }
        table.data tr:nth-child(even) td { background: #f6f5f3; }
        table.data tr { page-break-inside: avoid; }

        .totals-table { width: 40%; margin-left: auto; border-collapse: collapse; margin-bottom: 20px; }
        .totals-table td { padding: 5px 12px; font-size: 9pt; }
        .totals-table .total-row td { font-size: 11pt; font-weight: 700; border-top: 2px solid #1E232E; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #6B7280; }
        .font-bold { font-weight: 700; }

        .badge {
            display: inline-block; padding: 3px 9px;
            border-radius: 99px; font-size: 8pt; font-weight: 700;
        }
        .badge-paid    { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef9c3; color: #854d0e; }
        .badge-overdue { background: #fee2e2; color: #991b1b; }
        .badge-voided  { background: #f3f4f6; color: #374151; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    {{-- Header --}}
    <div class="header-bar">
        <table>
            <tr>
                <td style="vertical-align: middle; width: 50%;">
                    <div class="brand font-brand">flexyflow.co</div>
                    <div class="brand-sub">construimos la operación digital de tu negocio</div>
                </td>
                <td style="vertical-align: top; width: 50%;">
                    <div class="invoice-title font-brand">Factura #{{ $invoice->id }}</div>
                    <div class="invoice-meta">
                        <div><strong>Emitida:</strong> {{ $invoice->generated_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</div>
                        <div><strong>Tipo:</strong> {{ $invoice->type === 'monthly' ? 'Mensual' : 'Prorrateo' }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Info empresa + factura --}}
    <table class="info-grid">
        <tr>
            <td>
                <div class="info-block">
                    <div class="label">Empresa</div>
                    <div class="value">{{ $company->commercial_name }}</div>
                    <div class="sub">NIT: {{ $company->nit }}</div>
                </div>
            </td>
            <td>
                <div class="info-block">
                    <div class="label">Plan</div>
                    <div class="value">{{ $plan?->name ?? '—' }}</div>
                    <div class="sub">
                        Período: {{ $invoice->period_from->format('d/m/Y') }} — {{ $invoice->period_to->format('d/m/Y') }}
                        ({{ $invoice->days_billed }} días)
                    </div>
                    <div class="sub">Vence: {{ $invoice->due_date->format('d/m/Y') }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Líneas de factura --}}
    <h2>Detalle de Cargos</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="text-right">Cant.</th>
                <th class="text-right">Precio Base</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="text-right">{{ $line->quantity }}</td>
                    <td class="text-right">$ {{ number_format($line->unit_price, 0, ',', '.') }}</td>
                    <td class="text-right font-bold">$ {{ number_format($line->subtotal, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted" style="padding: 24px;">Sin líneas</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totales --}}
    <table class="totals-table">
        <tr>
            <td class="text-muted">Precio base</td>
            <td class="text-right">$ {{ number_format($invoice->base_amount, 0, ',', '.') }}</td>
        </tr>
        @if ($invoice->discount_percent)
            <tr>
                <td class="text-muted">Descuento ({{ number_format($invoice->discount_percent, 0) }}%)</td>
                <td class="text-right" style="color: #166534;">- $ {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td>
            </tr>
        @endif
        <tr class="total-row">
            <td class="font-brand">Total a pagar</td>
            <td class="text-right">$ {{ number_format($invoice->amount, 0, ',', '.') }} {{ $invoice->currency }}</td>
        </tr>
    </table>

    {{-- Estado --}}
    <h2>Estado</h2>
    <div style="margin-bottom: 20px;">
        @php
            $statusLabels = [
                'pending' => ['label' => 'Pendiente', 'class' => 'badge-pending'],
                'paid'    => ['label' => 'Pagada',    'class' => 'badge-paid'],
                'overdue' => ['label' => 'Vencida',   'class' => 'badge-overdue'],
                'voided'  => ['label' => 'Anulada',   'class' => 'badge-voided'],
            ];
            $st = $statusLabels[$invoice->status] ?? ['label' => ucfirst($invoice->status), 'class' => 'badge-pending'];
        @endphp
        <span class="badge {{ $st['class'] }}">{{ $st['label'] }}</span>

        @if ($invoice->status === 'paid' && $payments->isNotEmpty())
            @php $firstPayment = $payments->first(); @endphp
            <span style="font-size: 8.5pt; color: #6B7280; margin-left: 8px;">
                Pagada el {{ \Carbon\Carbon::parse($firstPayment->payment_date)->format('d/m/Y') }}
                — Ref: {{ $firstPayment->payment_reference }}
            </span>
        @elseif ($invoice->status === 'pending' || $invoice->status === 'overdue')
            <span style="font-size: 8.5pt; color: #6B7280; margin-left: 8px;">
                Vence el {{ $invoice->due_date->format('d/m/Y') }}
            </span>
        @endif
    </div>

    @include('pdf.partials.footer')
</body>
</html>
