<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Genera y almacena el PDF de una factura individual usando DomPDF.
 *
 * El PDF se genera una sola vez: si pdf_path ya existe en disco, retorna la ruta existente sin regenerar.
 * Ruta de almacenamiento: invoices/{invoice_id}.pdf en el disco configurado en billing.storage_disk.
 * Tras generar, actualiza pdf_path y pdf_generated_at en la tabla invoices via DB::table() para
 * evitar disparar el evento Updating que bloquea campos inmutables.
 *
 * @env PDF_DRIVER — motor PDF; actualmente usa DomPDF (config billing.storage_disk para disco)
 * @env FILESYSTEM_DISK — disco de almacenamiento de archivos
 */
class InvoicePdfService
{
    public function generateAndStore(Invoice $invoice): string
    {
        if ($invoice->pdf_path && Storage::disk(config('billing.storage_disk'))->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        $data = $this->buildPdfData($invoice);

        $pdf = Pdf::loadView('pdf.invoice', $data)->setPaper('A4');

        $path = "invoices/{$invoice->id}.pdf";
        Storage::disk(config('billing.storage_disk'))->put($path, $pdf->output());

        DB::table('invoices')->where('id', $invoice->id)->update([
            'pdf_path' => $path,
            'pdf_generated_at' => now(),
        ]);

        return $path;
    }

    /** @return array<string, mixed> */
    public function buildPdfData(Invoice $invoice): array
    {
        $invoice->loadMissing(['subscription.plan', 'lines', 'payments', 'company']);

        return [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'plan' => $invoice->subscription?->plan,
            'lines' => $invoice->lines,
            'payments' => $invoice->payments,
            'footerText' => config('pdf.footer_text', 'Generado por flexyflow'),
        ];
    }
}
