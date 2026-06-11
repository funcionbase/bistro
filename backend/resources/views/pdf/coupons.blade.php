<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Historial de Cupones — {{ $companyName }}</title>
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

        /* Badges */
        .badge {
            display: inline-block; padding: 3px 9px;
            border-radius: 99px; font-size: 7.5pt; font-weight: 700;
        }
        .badge-active   { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f3f4f6; color: #374151; }
        .badge-expired  { background: #fee2e2; color: #991b1b; }
        /* Type badges use app accent colors */
        .badge-percent  { background: #dbeafe; color: #1e40af; }
        .badge-fixed    { background: #C0FD79; color: #1E232E; }

        /* Code chip */
        .code {
            font-family: "Courier New", monospace; font-size: 8.5pt;
            background: #f6f5f3; border: 1px solid #e5e5e5;
            padding: 2px 7px; border-radius: 4px;
            letter-spacing: 0.5px;
        }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .text-muted  { color: #6B7280; }
        .font-bold   { font-weight: 700; }
    </style>
    @include('pdf.partials._fonts')
</head>
<body>
    <div class="page-title font-brand">Historial de Cupones</div>
    <div class="page-subtitle">{{ $companyName }}</div>

    @include('pdf.partials.header')
    @include('pdf.partials.limit-notice')

    <h2>Cupones Registrados</h2>
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Tipo</th>
                <th>Descuento</th>
                <th>Estado</th>
                <th class="text-right">Usos</th>
                <th class="text-right">Límite</th>
                <th>Vence</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($coupons as $coupon)
                @php
                    $statusLabel = match($coupon->status ?? 'active') {
                        'active'   => 'Activo',
                        'inactive' => 'Inactivo',
                        'expired'  => 'Expirado',
                        default    => ucfirst($coupon->status ?? 'Activo'),
                    };
                    $statusClass = match($coupon->status ?? 'active') {
                        'active'   => 'badge-active',
                        'inactive' => 'badge-inactive',
                        'expired'  => 'badge-expired',
                        default    => 'badge-inactive',
                    };
                    $typeLabel = ($coupon->type ?? 'percent') === 'percent' ? 'Porcentaje' : 'Fijo';
                    $typeClass = ($coupon->type ?? 'percent') === 'percent' ? 'badge-percent' : 'badge-fixed';
                    $discountValue = ($coupon->type ?? 'percent') === 'percent'
                        ? number_format($coupon->value ?? 0, 0) . ' %'
                        : '$ ' . number_format($coupon->value ?? 0, 0, ',', '.');
                @endphp
                <tr>
                    <td><span class="code">{{ $coupon->code }}</span></td>
                    <td><span class="badge {{ $typeClass }}">{{ $typeLabel }}</span></td>
                    <td class="font-bold">{{ $discountValue }}</td>
                    <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                    <td class="text-right">{{ $coupon->redemptions_count ?? 0 }}</td>
                    <td class="text-right text-muted">{{ $coupon->max_uses ?? '∞' }}</td>
                    <td class="text-muted" style="font-size: 8pt;">
                        {{ $coupon->expires_at ? \Carbon\Carbon::parse($coupon->expires_at)->format('d/m/Y') : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 24px;">
                        Sin registros
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('pdf.partials.footer')
</body>
</html>
