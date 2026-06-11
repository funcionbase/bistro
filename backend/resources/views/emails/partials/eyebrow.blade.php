{{-- Eyebrow / píldora de momento (DS §20). Espejo del PageHeader del producto.
     Variantes canónicas (color funcional al ~15% de superficie + texto pleno):
       accent      → logro / celebración   (#C0FD79 / #1E232E)
       neutral     → recordatorio / info    (#F6F5F3 / #6B7280)
       warning     → atención / plazo        (#FDF0DB / #F39C12)
       destructive → crítico / irreversible  (#FCD8D2 / #D9402A)

     Inline para que Outlook lo respete (strippea muchas clases).

     @param string $variant
     @param string $label
--}}
@php
    $eyebrowVariants = [
        'accent' => ['bg' => '#C0FD79', 'fg' => '#1E232E'],
        'neutral' => ['bg' => '#F6F5F3', 'fg' => '#6B7280'],
        'warning' => ['bg' => '#FDF0DB', 'fg' => '#F39C12'],
        'destructive' => ['bg' => '#FCD8D2', 'fg' => '#D9402A'],
    ];
    $eb = $eyebrowVariants[$variant ?? 'neutral'] ?? $eyebrowVariants['neutral'];
@endphp
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: {{ $eb['bg'] }}; color: {{ $eb['fg'] }}; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">{{ $label }}</span></div>
