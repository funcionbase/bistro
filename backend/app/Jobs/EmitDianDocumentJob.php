<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\BillingService;
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
 *    por crash mid-job, no se duplica. `uniqueFor` (3900s) cubre el peor
 *    backoff (3600s) + margen, para que el lock de dedup sobreviva todo el
 *    schedule de reintentos del job y el cron no encole un duplicado.
 *  - Backoff exponencial 1m/3m/5m/15m/30m/1h (6 intentos máximo).
 *  - DianDispatchService::emit es idempotente por (order_id, document_type):
 *    si ya hay un documento no-borrador, reintenta el existente (reusa
 *    consecutivo) o lo devuelve — nunca quema un consecutivo nuevo.
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
        // Debe superar el peor backoff (3600s) para que el lock de unicidad
        // sobreviva el schedule completo de reintentos y el cron no encole un
        // 2º job para el mismo (order_id, document_type) mientras uno reintenta.
        return 3900;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 300, 900, 1800, 3600];
    }

    public function handle(DianDispatchService $dispatch, BillingService $billing): void
    {
        // Kill-switch global (DIAN_EMISSION_ENABLED=false, default): no-op en
        // vez de dejar que DianDispatchService::emit() lance y el job queme
        // los 6 reintentos con backoff contra un flag que no va a cambiar solo.
        if (! config('dian.emission_enabled', false)) {
            return;
        }

        $order = Order::query()->find($this->orderId);
        if ($order === null) {
            return;
        }

        // Módulo DIAN exclusivo del Plan Plus. No-op (no burn de reintentos)
        // si la empresa bajó de plan entre el cierre de la orden y que este
        // job corriera.
        if (! $billing->companyHasFeature($order->company_nit, 'dian')) {
            return;
        }

        $dispatch->emit($order, [
            'document_type' => $this->documentType,
            'is_auto_emit' => $this->isAutoEmit,
        ]);
    }
}
