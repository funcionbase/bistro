<div class="font-brand" style="margin-top: 32px; padding-top: 10px; border-top: 1px solid #e5e5e5; text-align: center; font-size: 8pt; color: #6B7280;">
    {{ $footerText }}
</div>

<script type="text/php">
    if (isset($pdf)) {
        $text = "Página " . $PAGE_NUM . " de " . $PAGE_COUNT;
        $font = $fontMetrics->get_font("Arial, sans-serif", "normal");
        $pdf->page_text(490, 818, $text, $font, 8, [0.42, 0.45, 0.50]);
    }
</script>
