<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Cierre de Caja — {{ $companyName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: {{ config('pdf.font_size', 10) }}pt;
            color: #1E232E;
            background: #ffffff;
            padding: 32px 40px;
        }
        .page-title { font-size: 20pt; font-weight: 700; margin-bottom: 2px; }
        .page-subtitle { font-size: 9pt; color: #6B7280; margin-bottom: 20px; }
        .period { font-size: 10pt; color: #374151; margin-bottom: 20px; }
        h2 {
            font-size: 11pt;
            font-weight: 700;
            margin: 18px 0 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e5e5;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
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
        }
        tr:nth-child(even) td { background-color: #f6f5f3; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .text-muted { color: #6B7280; }
        .highlight {
            background: #ecfdf5 !important;
            color: #065f46;
            font-weight: 700;
        }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Cierre de Caja</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @php
        $methodLabel = [
            'cash'     => 'Efectivo',
            'card'     => 'Tarjeta',
            'transfer' => 'Transferencia',
            'refund'   => 'Devolución',
        ];
    @endphp

    <div class="period">
        <strong>Período:</strong>
        {{ $period['from']->format('d/m/Y') }} — {{ $period['to']->format('d/m/Y') }}
        <span class="text-muted">({{ $period['timezone'] }})</span>
        <br>
        <span class="text-muted">Generado el {{ $generatedAt }}</span>
    </div>

    <h2>Resumen por método de pago</h2>
    <table>
        <thead>
            <tr>
                <th>Método</th>
                <th class="text-right">Cobros (Gross)</th>
                <th class="text-right">Devoluciones</th>
                <th class="text-right">Neto</th>
                <th class="text-right">Propinas</th>
                <th class="text-right">N° receipts</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['cash','card','transfer','refund'] as $key)
                @php $row = $summary['by_method'][$key]; @endphp
                @if ($row['count'] > 0 || $row['gross'] > 0 || $row['refunds'] > 0 || $row['tips'] > 0)
                    <tr>
                        <td>{{ $methodLabel[$key] }}</td>
                        <td class="text-right">$ {{ number_format($row['gross'], 0, ',', '.') }}</td>
                        <td class="text-right">$ {{ number_format($row['refunds'], 0, ',', '.') }}</td>
                        <td class="text-right font-bold">$ {{ number_format($row['net'], 0, ',', '.') }}</td>
                        <td class="text-right">$ {{ number_format($row['tips'], 0, ',', '.') }}</td>
                        <td class="text-right text-muted">{{ $row['count'] }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr class="highlight">
                <td class="font-brand">Total general</td>
                <td class="text-right">$ {{ number_format($summary['totals']['gross'], 0, ',', '.') }}</td>
                <td class="text-right">$ {{ number_format($summary['totals']['refunds'], 0, ',', '.') }}</td>
                <td class="text-right">$ {{ number_format($summary['totals']['net'], 0, ',', '.') }}</td>
                <td class="text-right">$ {{ number_format($summary['totals']['tips'], 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <h2>Cierre de Caja Física (Efectivo)</h2>
    <table>
        <tbody>
            @php $cash = $summary['by_method']['cash']; @endphp
            <tr>
                <td>Saldo inicial (base de caja)</td>
                <td class="text-right">$ {{ number_format($summary['cash_opening_amount'] ?? 0, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>+ Cobros en efectivo</td>
                <td class="text-right">$ {{ number_format($cash['gross'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>+ Propinas recibidas en efectivo</td>
                <td class="text-right">$ {{ number_format($cash['tips'], 0, ',', '.') }}</td>
            </tr>
            {{-- Entradas de efectivo (no-venta) desglosadas por categoría + total. --}}
            @foreach ($summary['cash_incomes_by_category'] ?? [] as $cat => $amount)
                <tr>
                    <td>+ {{ config('cash_register.income_categories')[$cat] ?? $cat }}</td>
                    <td class="text-right">$ {{ number_format($amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            @if (($summary['cash_incomes_total'] ?? 0) > 0)
                <tr>
                    <td>Total entradas de efectivo</td>
                    <td class="text-right">$ {{ number_format($summary['cash_incomes_total'], 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr>
                <td>− Egresos en efectivo</td>
                <td class="text-right">$ {{ number_format($summary['cash_expenses_total'] ?? 0, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>− Devoluciones en efectivo</td>
                <td class="text-right">$ {{ number_format($cash['refunds'], 0, ',', '.') }}</td>
            </tr>
            <tr class="highlight">
                <td>Efectivo esperado en caja</td>
                <td class="text-right">$ {{ number_format($summary['cash_drawer_expected'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Cruce de domiciliarios (F6): abonos entregados a caja al despachar,
         reversiones por entrega fallida y tarifas de domicilio por pagar. --}}
    @if (!empty($summary['couriers']))
        <h2>Domiciliarios — abonos y tarifas</h2>
        <table>
            <thead>
                <tr>
                    <th>Domiciliario</th>
                    <th class="text-right">Abonos</th>
                    <th class="text-right">Reversiones</th>
                    <th class="text-right">Entregas</th>
                    <th class="text-right">Tarifas</th>
                    <th class="text-right">Pagado</th>
                    <th class="text-right">Por pagar</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['couriers'] as $courier)
                    <tr>
                        <td>{{ $courier['name'] }}</td>
                        <td class="text-right">$ {{ number_format($courier['advances'], 0, ',', '.') }}</td>
                        <td class="text-right">$ {{ number_format($courier['reversals'], 0, ',', '.') }}</td>
                        <td class="text-right">{{ $courier['completed_deliveries'] }}</td>
                        <td class="text-right">$ {{ number_format($courier['fees_owed'], 0, ',', '.') }}</td>
                        <td class="text-right">$ {{ number_format($courier['fees_paid'], 0, ',', '.') }}</td>
                        <td class="text-right">$ {{ number_format($courier['fees_pending'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted" style="font-size: 8pt; margin-top: 4px;">
            Abonos: efectivo que el domiciliario entregó a caja al despachar. Tarifas: domicilios de sus
            entregas completadas — se pagan con el egreso "Pago domiciliario" vinculado al repartidor.
        </p>
    @endif

    <p class="text-muted" style="font-size: 8pt; margin-top: 8px;">
        Las propinas son del personal y NO forman parte del ingreso del restaurante.
        Total de órdenes operadas en el período: <strong>{{ $summary['orders_count'] }}</strong>.
    </p>

    @include('pdf.partials.footer')
</body>
</html>
