<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Historial de Repartidores — {{ $companyName }}</title>
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
        td { padding: 7px 12px; border-bottom: 1px solid #e5e5e5; font-size: 9pt; vertical-align: middle; }
        tr:nth-child(even) td { background: #f6f5f3; }
        tr { page-break-inside: avoid; }

        .badge {
            display: inline-block; padding: 3px 9px;
            border-radius: 99px; font-size: 7.5pt; font-weight: 700;
        }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-pending   { background: #fef3c7; color: #92400e; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-default   { background: #f3f4f6; color: #374151; }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .text-muted  { color: #6B7280; }
        .font-bold   { font-weight: 700; }
        .mono        { font-family: "Courier New", monospace; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Historial de Repartidores</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @include('pdf.partials.header')
    @include('pdf.partials.limit-notice')

    <h2>Entregas Registradas</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Repartidor</th>
                <th>Orden</th>
                <th>Estado</th>
                <th>Asignado</th>
                <th>Completado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($deliveries as $delivery)
                @php
                    $badgeClass = match($delivery->status) {
                        'completed' => 'badge-completed',
                        'pending'   => 'badge-pending',
                        'cancelled' => 'badge-cancelled',
                        default     => 'badge-default',
                    };
                    $statusLabel = match($delivery->status) {
                        'completed' => 'Completada',
                        'pending'   => 'Pendiente',
                        'cancelled' => 'Cancelada',
                        default     => ucfirst($delivery->status),
                    };
                @endphp
                <tr>
                    <td class="text-muted mono">#{{ $delivery->id }}</td>
                    <td class="font-bold">{{ $delivery->deliverer?->name ?? '—' }}</td>
                    <td class="text-muted mono">#{{ $delivery->order_id ?? '—' }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span></td>
                    <td class="text-muted" style="font-size: 8pt;">
                        {{ $delivery->assigned_at ? \Carbon\Carbon::parse($delivery->assigned_at)->format('d/m/Y H:i') : '—' }}
                    </td>
                    <td class="text-muted" style="font-size: 8pt;">
                        {{ $delivery->delivered_at ? \Carbon\Carbon::parse($delivery->delivered_at)->format('d/m/Y H:i') : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 24px;">
                        Sin registros en este período
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('pdf.partials.footer')
</body>
</html>
