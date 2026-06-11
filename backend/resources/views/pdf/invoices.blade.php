<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Historial de Facturas — {{ $companyName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: {{ config('pdf.font_size', 10) }}pt;
            color: #1E232E;
            background: #ffffff;
            padding: 32px 40px;
        }

        .page-title    { font-size: 20pt; font-weight: 700; color: #1E232E; margin-bottom: 2px; }
        .page-subtitle { font-size: 9pt; color: #6B7280; margin-bottom: 20px; }

        h2 {
            font-size: 11pt; font-weight: 700; color: #1E232E;
            margin-bottom: 10px; padding-bottom: 4px;
            border-bottom: 1px solid #e5e5e5;
        }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead { display: table-header-group; }
        th {
            background: #0052FF; color: #ffffff;
            font-weight: 700; font-size: 8pt;
            padding: 8px 12px; text-align: left;
        }
        th.text-right { text-align: right; }
        td { padding: 7px 12px; border-bottom: 1px solid #e5e5e5; font-size: 9pt; vertical-align: middle; }
        tr:nth-child(even) td { background: #f6f5f3; }
        tr { page-break-inside: avoid; }

        .badge {
            display: inline-block; padding: 3px 9px;
            border-radius: 99px; font-size: 7.5pt; font-weight: 700;
        }
        .badge-pending   { background: #fef9c3; color: #854d0e; }
        .badge-paid      { background: #dcfce7; color: #166534; }
        .badge-overdue   { background: #fee2e2; color: #991b1b; }
        .badge-voided    { background: #f3f4f6; color: #374151; }
        .badge-monthly   { background: #dbeafe; color: #1e40af; }
        .badge-proration { background: #ede9fe; color: #5b21b6; }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .text-muted  { color: #6B7280; }
        .font-bold   { font-weight: 700; }
        .color-blue  { color: #0052FF; }

        .summary-box {
            margin-top: 8px;
            padding: 12px 16px;
            background: #f6f5f3;
            border-radius: 6px;
            border-left: 4px solid #0052FF;
        }
        .summary-row { display: flex; justify-content: space-between; font-size: 9pt; margin-bottom: 4px; }
        .summary-total { font-size: 11pt; font-weight: 700; color: #0052FF; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Historial de Facturas</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @include('pdf.partials.header')

    <h2>Facturas</h2>
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Plan</th>
                <th>Período</th>
                <th class="text-right">Días</th>
                <th class="text-right">Precio Base</th>
                <th class="text-right">Descuento</th>
                <th class="text-right">Total</th>
                <th>Vencimiento</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                @php
                    $typeLabel = $invoice->type === 'monthly' ? 'Mensual' : 'Prorrateo';
                    $typeClass = $invoice->type === 'monthly' ? 'badge-monthly' : 'badge-proration';
                    $statusLabel = match($invoice->status) {
                        'pending' => 'Pendiente',
                        'paid'    => 'Pagada',
                        'overdue' => 'Vencida',
                        'voided'  => 'Anulada',
                        default   => ucfirst($invoice->status),
                    };
                    $statusClass = match($invoice->status) {
                        'pending' => 'badge-pending',
                        'paid'    => 'badge-paid',
                        'overdue' => 'badge-overdue',
                        'voided'  => 'badge-voided',
                        default   => 'badge-pending',
                    };
                    $planName = $invoice->subscription?->plan?->name ?? '—';
                    $periodFrom = \Carbon\Carbon::parse($invoice->period_from)->locale('es')->isoFormat('MMM YYYY');
                    $periodFrom = ucfirst($periodFrom);
                @endphp
                <tr>
                    <td><span class="badge {{ $typeClass }}">{{ $typeLabel }}</span></td>
                    <td class="text-muted" style="font-size: 8pt;">{{ $planName }}</td>
                    <td class="font-bold">{{ $periodFrom }}</td>
                    <td class="text-right text-muted">{{ $invoice->days_billed }}</td>
                    <td class="text-right">$ {{ number_format($invoice->base_amount, 0, ',', '.') }}</td>
                    <td class="text-right" style="color: #16a34a;">
                        @if ($invoice->discount_percent)
                            {{ $invoice->discount_percent }}%
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-right font-bold color-blue">
                        $ {{ number_format($invoice->amount, 0, ',', '.') }}
                    </td>
                    <td class="text-muted" style="font-size: 8pt;">
                        {{ \Carbon\Carbon::parse($invoice->due_date)->format('d/m/Y') }}
                    </td>
                    <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted" style="padding: 24px;">
                        Sin registros
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary-box">
        <div class="summary-row">
            <span>Total facturas:</span>
            <span class="font-bold">{{ $totalRecords }}</span>
        </div>
        <div class="summary-row summary-total">
            <span class="font-brand">Total acumulado:</span>
            <span>$ {{ number_format($totalAmount, 0, ',', '.') }} COP</span>
        </div>
    </div>

    @include('pdf.partials.footer')
</body>
</html>
