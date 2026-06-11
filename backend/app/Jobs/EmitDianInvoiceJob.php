<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Dian\SaaSInvoiceDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Emisión asíncrona DIAN para invoices SaaS de FlexyFlow.
 *
 * Disparado por `BillingService::generateMonthlyInvoices` después del commit
 * de la invoice. Reusa `MockDianProvider` por ahora (pendiente integración con
 * provider real).
 *
 * N-instance safe:
 *  - ShouldBeUnique por invoice_id — re-encolado no duplica.
 *  - Backoff exponencial 1m/3m/5m/15m/30m/1h (6 intentos).
 *  - DianDispatchService::emit ya hace lock + idempotencia interna.
 */
class EmitDianInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public string $invoiceId) {}

    public function uniqueId(): string
    {
        return "dian-emit-invoice-{$this->invoiceId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 300, 900, 1800, 3600];
    }

    public function handle(SaaSInvoiceDispatchService $dispatch): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if ($invoice === null) {
            Log::warning('EmitDianInvoiceJob: invoice no encontrada', ['invoice_id' => $this->invoiceId]);

            return;
        }

        if ($invoice->electronic_document_id !== null) {
            // Idempotente — alguien más ya emitió el documento.
            return;
        }

        try {
            $dispatch->emit($invoice);
        } catch (\Throwable $e) {
            Log::error('EmitDianInvoiceJob: falló emisión', [
                'invoice_id' => $this->invoiceId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // re-throw para que la cola haga retry.
        }
    }
}
