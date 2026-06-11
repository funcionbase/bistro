{{-- Tabla key-value (DS §20 "patrón dashboard panel"). Para correos densos
     operativos. Espejo del DashboardPanel del frontend.

     Label izquierda: uppercase 11px, letter-spacing 0.18em, #6B7280, width 38%.
     Valor derecha: 15px #1E232E; primer campo en peso 600. Separadores
     border-bottom #E5E5E5 en cada fila (border-top extra en la primera).

     @param array<int, array{label: string, value: string}> $rows
            `value` es HTML ya escapado/compuesto por el caller (puede traer pills).
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0; border-collapse: collapse;">
@foreach ($rows as $row)
<tr>
<td style="padding: 14px 0; {{ $loop->first ? 'border-top: 1px solid #E5E5E5; ' : '' }}border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; width: 38%; vertical-align: top;">{{ $row['label'] }}</td>
<td style="padding: 14px 0; {{ $loop->first ? 'border-top: 1px solid #E5E5E5; ' : '' }}border-bottom: 1px solid #E5E5E5; color: #1E232E; font-size: 15px; {{ $loop->first ? 'font-weight: 600; ' : '' }}vertical-align: top;">{!! $row['value'] !!}</td>
</tr>
@endforeach
</table>
