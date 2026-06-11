<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Informe de Colaboradores — {{ $companyName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            color: #1E232E;
            background: #ffffff;
            padding: 24px 28px;
        }
        .page-title { font-size: 18pt; font-weight: 700; margin-bottom: 2px; }
        .page-subtitle { font-size: 9pt; color: #6B7280; margin-bottom: 14px; }
        .filters { font-size: 8pt; color: #4B5563; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th {
            background-color: #0052FF;
            color: #ffffff;
            font-weight: 700;
            font-size: 7.5pt;
            padding: 6px 8px;
            text-align: left;
            letter-spacing: 0.3px;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 8pt;
            vertical-align: middle;
        }
        tr:nth-child(even) td { background-color: #f6f5f3; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .totals td { background: #ecfdf5; font-weight: 700; font-family: 'flexyfont', Arial, sans-serif; }
        .footer { font-size: 7pt; color: #9CA3AF; margin-top: 16px; text-align: center; font-family: 'flexyfont', Arial, sans-serif; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Informe de Colaboradores</div>
    <div class="page-subtitle">{{ $companyName }}</div>
    <div class="filters">
        Período: {{ $filters['from'] }} → {{ $filters['to'] }}
        @if($filters['branch_id']) · Sede: {{ $filters['branch_id'] }} @endif
        @if($filters['employee_id']) · Colaborador: {{ $filters['employee_id'] }} @endif
        @if($filters['status']) · Estado: {{ $filters['status'] }} @endif
        · Generado {{ $generatedAt }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Colaborador</th>
                <th>Documento</th>
                <th>Cargo</th>
                <th>Sede</th>
                <th class="text-right">Asignadas (h)</th>
                <th class="text-right">Ejecutadas (h)</th>
                <th class="text-right">Canceladas (h)</th>
                <th class="text-right">Cancel. enferm.</th>
                <th class="text-right">Cancel. estado</th>
                <th class="text-right">Cancel. otras</th>
                <th class="text-right">Costo estimado (COP)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="font-bold">{{ $row['full_name'] }}</td>
                    <td>{{ $row['doc_number'] }}</td>
                    <td>{{ $row['position'] }}</td>
                    <td>{{ $row['primary_branch'] }}</td>
                    <td class="text-right">{{ number_format($row['scheduled_hours'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['executed_hours'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['cancelled_hours'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['cancellations']['sick'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['cancellations']['vinculation_state'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['cancellations']['other'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['estimated_cost'], 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:#6B7280;">Sin datos en el período.</td></tr>
            @endforelse
        </tbody>
        @if(count($rows) > 0)
        <tfoot class="totals">
            <tr>
                <td colspan="4" class="font-bold">Totales</td>
                <td class="text-right">{{ number_format($totals['scheduled_hours'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['executed_hours'], 2) }}</td>
                <td class="text-right">{{ number_format($totals['cancelled_hours'], 2) }}</td>
                <td colspan="3"></td>
                <td class="text-right">{{ number_format($totals['estimated_cost'], 2, ',', '.') }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <div class="footer">{{ $footerText }}</div>
</body>
</html>
