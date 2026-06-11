<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\Dian\DianDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emisión asíncrona disparada por `closeWithPayment` cuando
 * `dian.auto_emit_on_close` lo permite.
 *
 * N-instance safe (add-on §5):
 *  - ShouldBeUnique por (order_id, document_type) — si la queue re-encola
 *    por crash mid-job, no se duplica.
 *  - Backoff exponencial 1m/3m/5m/15m/30m/1h (6 intentos máximo).
 *  - DianDispatchService::emit ya hace lock + idempotencia interna —
 *    el job es defensa adicional.
 */
class EmitDianDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(
        public string $orderId,
        public string $documentType,
        public bool $isAutoEmit = true,
    ) {}

    public function uniqueId(): string
    {
        return "dian-emit-{$this->orderId}-{$this->documentType}";
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

    public function handle(DianDispatchService $dispatch): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null) {
            return;
        }

        $dispatch->emit($order, [
            'document_type' => $this->documentType,
            'is_auto_emit' => $this->isAutoEmit,
        ]);
    }
}
