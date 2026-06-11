@if ($limitApplied)
<div style="
    margin-bottom: 16px;
    padding: 10px 14px;
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    border-radius: 0 6px 6px 0;
    font-size: 8.5pt;
    color: #92400e;
    line-height: 1.6;
">
    <span style="font-weight: 700en Límite automático aplicado.</span>
    Este PDF muestra los <span style="font-weight: 700;">{{ number_format($maxRows, 0, ',', '.') }} registros más recientes</span>
    de un total de <span style="font-weight: 700;">{{ number_format($totalRecords, 0, ',', '.') }}</span>.
    Para obtener un reporte más preciso, aplica un rango de fechas más específico o utiliza filtros adicionales.
</div>
@endif
