<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Métricas Operativas — {{ $companyName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: {{ config('pdf.font_size', 10) }}pt;
            color: #1E232E;
            background: #ffffff;
            padding: 32px 40px;
        }

        .page-title   { font-size: 20pt; font-weight: 700; color: #1E232E; margin-bottom: 2px; }
        .page-subtitle{ font-size: 9pt; color: #6B7280; margin-bottom: 20px; }

        h2 {
            font-size: 11pt; font-weight: 700; color: #1E232E;
            margin: 20px 0 10px; padding-bottom: 4px;
            border-bottom: 1px solid #e5e5e5;
        }

        /* KPI grid */
        .kpi-table { width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 8px; }
        .kpi-cell {
            background: #f6f5f3;
            border-radius: 8px;
            padding: 12px 16px;
            width: 30%;
            vertical-align: top;
        }
        .kpi-label { font-size: 7.5pt; color: #6B7280; margin-bottom: 4px; }
        .kpi-value { font-size: 17pt; font-weight: 700; color: #1E232E; }
        .kpi-value-blue { font-size: 17pt; font-weight: 700; color: #0052FF; }
        .kpi-value-green{ font-size: 15pt; font-weight: 700; color: #166534; }
        .kpi-value-red  { font-size: 15pt; font-weight: 700; color: #991b1b; }
        .kpi-value-amber{ font-size: 15pt; font-weight: 700; color: #92400e; }

        /* Table */
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead { display: table-header-group; }
        th {
            background: #0052FF; color: #ffffff;
            font-weight: 700; font-size: 8pt;
            padding: 8px 12px; text-align: left;
        }
        td { padding: 7px 12px; border-bottom: 1px solid #e5e5e5; font-size: 9pt; vertical-align: middle; }
        tr:nth-child(even) td { background: #f6f5f3; }
        tr { page-break-inside: avoid; }

        /* Rank badge */
        .rank {
            display: inline-block; width: 20px; height: 20px; border-radius: 50%;
            background: #0052FF; color: #fff;
            font-size: 7pt; font-weight: 700;
            text-align: center; line-height: 20px;
        }
        .rank-2 { background: #232733; }
        .rank-3 { background: #C0FD79; color: #1E232E; }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .text-muted  { color: #6B7280; }
        .font-bold   { font-weight: 700; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Métricas Operativas</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @include('pdf.partials.header')
    @include('pdf.partials.limit-notice')

    <h2>KPIs del Período</h2>
    <table class="kpi-table">
        <tr>
            <td class="kpi-cell">
                <div class="kpi-label">Total de Órdenes</div>
                <div class="kpi-value">{{ number_format($kpis['total_orders'], 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Completadas</div>
                <div class="kpi-value-green">{{ number_format($kpis['completed'], 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Canceladas</div>
                <div class="kpi-value-red">{{ number_format($kpis['cancelled'], 0, ',', '.') }}</div>
            </td>
        </tr>
        <tr>
            <td class="kpi-cell">
                <div class="kpi-label">Devoluciones</div>
                <div class="kpi-value-red">{{ number_format($kpis['refunded'] ?? 0, 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Abandonadas</div>
                <div class="kpi-value-amber">{{ number_format($kpis['abandoned'], 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Ticket Promedio (COP)</div>
                <div class="kpi-value">$ {{ number_format($kpis['average_ticket'], 0, ',', '.') }}</div>
            </td>
        </tr>
    </table>

    <h2>Resumen de Ingresos (COP)</h2>
    <table class="kpi-table">
        <tr>
            <td class="kpi-cell">
                <div class="kpi-label">Ingresos Brutos</div>
                <div class="kpi-value-blue">$ {{ number_format($kpis['total_revenue'], 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Devoluciones</div>
                <div class="kpi-value-red">$ {{ number_format($kpis['total_refunded'] ?? 0, 0, ',', '.') }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Ingresos Netos</div>
                <div class="kpi-value-green">$ {{ number_format($kpis['net_revenue'] ?? $kpis['total_revenue'], 0, ',', '.') }}</div>
            </td>
        </tr>
        @if (($kpis['total_tips'] ?? 0) > 0)
            <tr>
                <td colspan="3" class="kpi-cell" style="text-align: left; font-size: 9pt; color: #6B7280;">
                    Propinas recaudadas en el período:
                    <strong>$ {{ number_format($kpis['total_tips'], 0, ',', '.') }}</strong>
                    (no forma parte del ingreso del restaurante; se entrega al staff)
                </td>
            </tr>
        @endif
    </table>

    @if (!empty($topItems))
        <h2>Top 10 Platos Más Vendidos</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Plato</th>
                    <th class="text-right">Unidades</th>
                    <th class="text-right">Ingresos (COP)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topItems as $i => $item)
                    <tr>
                        <td>
                            <span class="rank {{ $i === 1 ? 'rank-2' : ($i === 2 ? 'rank-3' : '') }}">
                                {{ $i + 1 }}
                            </span>
                        </td>
                        <td class="font-bold">{{ $item['name'] }}</td>
                        <td class="text-right">{{ number_format($item['qty'], 0, ',', '.') }}</td>
                        <td class="text-right text-muted">$ {{ number_format($item['revenue'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
