<?php

/**
 * Guarda anti-fuga: los endpoints/servicios que manejan documentos privados
 * (factura, reportes, adjuntos de compras) NUNCA deben construir URLs
 * publicas via Storage::url() ni $disk->url(). Esos archivos contienen
 * monto, datos del cliente y/o información protegida DIAN.
 *
 * El patrón correcto es:
 *   - Storage::temporaryUrl($path, $expiresAt)  — firma S3 con TTL
 *   - Servirlos via Laravel signed route + streaming server-side
 *
 * Ver issue #43 (T3.d) y CLAUDE.md → REGLAS CONTABLES.
 */
it('forbids Storage::url() and ->url() in private file namespaces', function () {
    $privateFiles = [
        app_path('Http/Controllers/Billing/BillingController.php'),
        app_path('Http/Controllers/Api/BillingController.php'),
        app_path('Services/InvoicePdfService.php'),
        app_path('Services/PurchaseAttachmentService.php'),
        app_path('Http/Controllers/Reports/OrderReportController.php'),
        app_path('Jobs/GenerateReportPdf.php'),
    ];

    $offenders = [];

    foreach ($privateFiles as $file) {
        if (! is_file($file)) {
            continue;
        }
        $content = (string) file_get_contents($file);

        if (preg_match('/Storage::url\s*\(/', $content)) {
            $offenders[] = basename($file).': usa Storage::url() (publico permanente)';
        }
        if (preg_match('/Storage::disk\s*\([^)]*\)\s*->\s*url\s*\(/', $content)) {
            $offenders[] = basename($file).': usa Storage::disk(...)->url() (publico permanente)';
        }
    }

    expect($offenders)->toBeEmpty(
        "Endpoints privados con URL publica (DIAN/privacidad):\n - ".implode("\n - ", $offenders)."\n\n".
        'Reemplazar por Storage::temporaryUrl($path, $expires) o servir via signed route + streaming.'
    );
});
