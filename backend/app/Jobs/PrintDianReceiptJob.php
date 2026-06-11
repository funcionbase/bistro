<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ElectronicDocument;
use App\Models\Printer;
use App\Services\AuditService;
use App\Services\Printing\DianReceiptBuilder;
use App\Services\Printing\PrinterDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Imprime una tirilla DIAN (DEE POS o FEV) a una impresora térmica.
 *
 * N-instance safe (add-on §5): ShouldBeUnique por (document_id, printer_id).
 * Reimpresión explícita pasa `force=true` y el uniqueId incluye un ULID
 * para bypassar la unicidad.
 *
 * Si `PrinterDispatcher` no existe en este repo (proyectos varían), el
 * job log-only — el dev decide cómo conectar al driver real más adelante.
 */
class PrintDianReceiptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $documentId,
        public ?string $printerId = null,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        $base = "dian-print-{$this->documentId}-".($this->printerId ?? 'auto');

        return $this->force ? $base.'-force-'.Str::ulid() : $base;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(DianReceiptBuilder $builder, AuditService $audit): void
    {
        $document = ElectronicDocument::query()->find($this->documentId);
        if ($document === null) {
            return;
        }

        $printer = $this->printerId
            ? Printer::query()->find($this->printerId)
            : Printer::query()
                ->where('branch_id', $document->branch_id)
                ->where('is_active', true)
                ->where('type', 'customer_receipt')
                ->first();

        if ($printer === null) {
            return;
        }

        $widthMm = (int) ($printer->paper_width_mm ?? 58);
        $bytes = $builder->build($document, $widthMm);

        $dispatcherClass = '\\App\\Services\\Printing\\PrinterDispatcher';
        if (class_exists($dispatcherClass)) {
            app($dispatcherClass)->send($printer, $bytes);
        }

        $audit->log('dian.document.printed', null, $document, [
            'printer_id' => $printer->id,
            'paper_width_mm' => $widthMm,
            'force' => $this->force,
        ]);
    }
}
