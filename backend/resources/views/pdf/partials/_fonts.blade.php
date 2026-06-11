{{-- Brand font for PDFs.
     Pre-installed in storage/fonts via setup script (flexyfont_normal.ufm + installed-fonts.json).
     dompdf resolves family 'flexyfont' (lowercase) automatically — no @font-face needed.
     Use class "font-brand" only on header (company name), document title,
     TOTAL/NETO labels and footer institucional. Never on tables, items,
     dates, NITs or money columns — body remains Arial / Courier New. --}}
<style>
    .font-brand {
        font-family: 'flexyfont', Arial, sans-serif;
    }
</style>
