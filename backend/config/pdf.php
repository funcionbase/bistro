<?php

return [

    'engine' => env('PDF_ENGINE', 'dompdf'),

    'paper_size' => env('PDF_PAPER_SIZE', 'A4'),

    'orientation' => env('PDF_ORIENTATION', 'portrait'),

    'include_company_logo' => (bool) env('PDF_INCLUDE_COMPANY_LOGO', true),

    'footer_text' => env('PDF_FOOTER_TEXT', 'Generado por bistro'),

    'font_size' => (int) env('PDF_FONT_SIZE', 10),

    'max_rows' => (int) env('PDF_MAX_SYNC_ROWS', 500),

];
