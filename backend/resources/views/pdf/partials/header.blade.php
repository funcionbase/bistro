<div style="margin-bottom: 24px; padding-bottom: 16px; border-bottom: 3px solid #0052FF;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: middle; width: 60%;">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="{{ $companyName }}" style="max-height: 44px; max-width: 150px; object-fit: contain; margin-bottom: 4px;" />
                    <div class="font-brand" style="font-size: 11px; font-weight: 600; color: #1E232E;">{{ $companyName }}</div>
                @else
                    <div class="font-brand" style="font-size: 20px; font-weight: 700; color: #0052FF; letter-spacing: -0.5px;">{{ $companyName }}</div>
                @endif
            </td>
            <td style="vertical-align: top; text-align: right; width: 40%;">
                <div style="font-size: 8pt; color: #6B7280; line-height: 1.7;">
                    @if (!empty($filters['date_from']) || !empty($filters['date_to']))
                        <div><span style="font-weight: 600; color: #1E232E;">Período:</span> {{ $filters['date_from'] ?? '—' }} — {{ $filters['date_to'] ?? '—' }}</div>
                    @endif
                    <div><span style="font-weight: 600; color: #1E232E;">Generado:</span> {{ $generatedAt }}</div>
                    <div><span style="font-weight: 600; color: #1E232E;">Registros:</span> {{ $totalRecords }}</div>
                </div>
            </td>
        </tr>
    </table>
</div>
