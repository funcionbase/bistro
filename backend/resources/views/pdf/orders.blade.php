<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Informe de Pedidos — {{ $companyName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: {{ config('pdf.font_size', 10) }}pt;
            color: #1E232E;
            background: #ffffff;
            padding: 32px 40px;
        }

        /* ── Page header ── */
        .page-title {
            font-size: 20pt;
            font-weight: 700;
            color: #1E232E;
            margin-bottom: 2px;
        }
        .page-subtitle {
            font-size: 9pt;
            color: #6B7280;
            margin-bottom: 20px;
        }

        /* ── Section heading ── */
        h2 {
            font-size: 11pt;
            font-weight: 700;
            color: #1E232E;
            margin-bottom: 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e5e5;
        }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        th {
            background-color: #0052FF;
            color: #ffffff;
            font-weight: 700;
            font-size: 8pt;
            padding: 8px 12px;
            text-align: left;
            letter-spacing: 0.3px;
        }
        td {
            padding: 7px 12px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 9pt;
            vertical-align: middle;
            color: #1E232E;
        }
        tr:nth-child(even) td { background-color: #f6f5f3; }
        tr { page-break-inside: avoid; }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 7.5pt;
            font-weight: 700;
        }
        /* Colores alineados con STATUS_BADGE_CLASS de pages/reports/index.tsx
           (Tailwind 100/700-800) para que el PDF coincida visualmente con la
           tabla "Detalle de Pedidos" en pantalla. */
        .badge-pending     { background: #fef9c3; color: #854d0e; } /* yellow-100/800 */
        .badge-in-kitchen  { background: #ffedd5; color: #9a3412; } /* orange-100/800 */
        .badge-ready       { background: #dbeafe; color: #1e40af; } /* blue-100/800   */
        .badge-in-transit  { background: #f3e8ff; color: #6b21a8; } /* purple-100/800 */
        .badge-success     { background: #dcfce7; color: #166534; } /* green-100/800  */
        .badge-failed      { background: #ffe4e6; color: #be123c; } /* rose-100/700   */
        .badge-cancel      { background: #fee2e2; color: #b91c1c; } /* red-100/700    */
        .badge-refunded    { background: #fce7f3; color: #be185d; } /* pink-100/700   */
        .badge-abandoned   { background: #fef3c7; color: #b45309; } /* amber-100/700  */
        .badge-default     { background: #f3f4f6; color: #374151; }

        /* ── Utilities ── */
        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .text-muted  { color: #6B7280; }
        .font-bold   { font-weight: 700; }
        .mono        { font-family: "Courier New", monospace; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Informe de Pedidos</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @include('pdf.partials.header')
    @include('pdf.partials.limit-notice')

    @php
        $methodLabel = [
            'cash'     => 'Efectivo',
            'card'     => 'Tarjeta',
            'transfer' => 'Transferencia',
            'refund'   => 'Devolución',
            'unknown'  => 'Sin registrar',
        ];
    @endphp

    <h2>Detalle de Pedidos</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Estado</th>
                <th>Medio de pago</th>
                <th class="text-right">Subtotal</th>
                <th class="text-right">Impuesto</th>
                <th class="text-right">Total (COP)</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                @php
                    // Mapeo a clases CSS (solo presentación). Las labels vienen del config canónico.
                    $badgeClass = match($order->status) {
                        'completed'  => 'badge-success',
                        'pending'    => 'badge-pending',
                        'in_kitchen' => 'badge-in-kitchen',
                        'ready'      => 'badge-ready',
                        'in_transit' => 'badge-in-transit',
                        'failed'     => 'badge-failed',
                        'cancelled'  => 'badge-cancel',
                        'refunded'   => 'badge-refunded',
                        'abandoned'  => 'badge-abandoned',
                        default      => 'badge-default',
                    };
                    $statusLabel = config("orders.labels.{$order->status}", ucfirst($order->status));
                    $originalReceipt = $order->receipts->first(function ($r) {
                        return ($r->payment_data['method'] ?? null) !== 'refund';
                    });
                    $methodKey = in_array($originalReceipt?->payment_data['method'] ?? null, ['cash','card','transfer'], true)
                        ? $originalReceipt->payment_data['method']
                        : 'unknown';
                @endphp
                <tr>
                    <td class="text-muted mono">#{{ $order->id }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span></td>
                    <td>{{ $methodLabel[$methodKey] }}</td>
                    <td class="text-right">$ {{ number_format($order->subtotal, 0, ',', '.') }}</td>
                    <td class="text-right">$ {{ number_format($order->tax_amount, 0, ',', '.') }}</td>
                    <td class="text-right font-bold">$ {{ number_format($order->total, 0, ',', '.') }}</td>
                    <td class="text-muted" style="font-size: 8pt;">
                        {{ $order->ordered_at ? \Carbon\Carbon::parse($order->ordered_at)->format('d/m/Y H:i') : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 24px;">
                        Sin registros en este período
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Resumen tributario: base gravable, impuesto y total bruto sobre las
         órdenes mostradas (snapshot al cobrar — refleja el régimen vigente
         en cada orden, incluso si la empresa cambió de régimen después). --}}
    @if (isset($taxableSubtotal))
        <h2>Resumen tributario</h2>
        <table>
            <tbody>
                <tr>
                    <td>Subtotal (base gravable)</td>
                    <td class="text-right font-bold">$ {{ number_format($taxableSubtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Impuesto</td>
                    <td class="text-right font-bold">$ {{ number_format($taxTotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="font-bold font-brand" style="background:#f3f4f6;">Total bruto</td>
                    <td class="text-right font-bold" style="background:#f3f4f6;">
                        $ {{ number_format($taxableSubtotal + $taxTotal, 0, ',', '.') }}
                    </td>
                </tr>
                @if (isset($tipsTotal) && $tipsTotal > 0)
                    <tr>
                        <td class="text-muted" style="font-size: 8pt;">
                            Propinas recaudadas (no forma parte del ingreso del restaurante)
                        </td>
                        <td class="text-right text-muted">$ {{ number_format($tipsTotal, 0, ',', '.') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    {{-- Resumen contable: gross/refunds/net por método. Lee de payment_receipts.amount
         (signed) → SUM en SQL. La fila 'refund' tiene gross=0 y refunds=abs(amount)
         del lado negativo, así que su net es negativo (compensa el cobro original). --}}
    @if (!empty($byMethod))
        <h2>Resumen por medio de pago</h2>
        <table>
            <thead>
                <tr>
                    <th>Método</th>
                    <th class="text-right">Cobros (Gross)</th>
                    <th class="text-right">Devoluciones</th>
                    <th class="text-right">Neto</th>
                    <th class="text-right">N° receipts</th>
                </tr>
            </thead>
            <tbody>
                @foreach (['cash','card','transfer','refund'] as $mk)
                    @php $row = $byMethod[$mk] ?? null; @endphp
                    @if ($row && $row['count'] > 0)
                        <tr>
                            <td>{{ $methodLabel[$mk] }}</td>
                            <td class="text-right">$ {{ number_format($row['gross'], 0, ',', '.') }}</td>
                            <td class="text-right">$ {{ number_format($row['refunds'], 0, ',', '.') }}</td>
                            <td class="text-right font-bold">$ {{ number_format($row['net'], 0, ',', '.') }}</td>
                            <td class="text-right text-muted">{{ $row['count'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-bold" style="background:#f3f4f6;">Total general</td>
                    <td class="text-right font-bold" style="background:#f3f4f6;">$ {{ number_format($grossTotal, 0, ',', '.') }}</td>
                    <td class="text-right font-bold" style="background:#f3f4f6;">$ {{ number_format($refundsTotal, 0, ',', '.') }}</td>
                    <td class="text-right font-bold" style="background:#f3f4f6;">$ {{ number_format($netTotal, 0, ',', '.') }}</td>
                    <td style="background:#f3f4f6;"></td>
                </tr>
            </tfoot>
        </table>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
