<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Printer;
use App\Services\AuditService;
use App\Services\Printing\Drivers\HttpAgentDriver;
use App\Services\Printing\EscposTicketBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía una comanda ESC/POS a una impresora específica vía el driver HTTP del
 * agente local. Reintenta `tries` veces con backoff progresivo. Cada éxito o
 * fallo definitivo se audita.
 *
 * @phpstan-type ItemShape array{id?:string,name:string,price?:int,quantity:int,category?:string,notes?:string}
 */
class PrintCommandTicketJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array<int,array<string,mixed>> $items */
    public function __construct(
        public ?string $orderId,
        public string $printerId,
        public array $items,
        public bool $isReprint = false,
        public bool $isTest = false,
    ) {}

    /** @return array<int,int> */
    public function backoff(): array
    {
        /** @var array<int,int> */
        return config('printing.job.backoff', [10, 30, 90]);
    }

    public function handle(
        EscposTicketBuilder $builder,
        HttpAgentDriver $driver,
        AuditService $audit,
    ): void {
        $printer = Printer::find($this->printerId);
        if (! $printer) {
            Log::warning('PrintCommandTicketJob: printer missing', [
                'printer_id' => $this->printerId,
            ]);

            return;
        }

        if ($this->isTest) {
            $order = new Order([
                'company_nit' => $printer->company_nit,
                'order_type' => 'dine_in',
                'table_number' => '0',
                'items' => $this->items,
                'ordered_at' => now(),
            ]);
            $order->id = 0;
        } else {
            $order = Order::find($this->orderId);
            if (! $order) {
                Log::warning('PrintCommandTicketJob: order missing', [
                    'order_id' => $this->orderId,
                ]);

                return;
            }
        }

        $payload = $builder->build($order, $printer, $this->items, $this->isReprint);
        $driver->send($printer, $payload);

        if ($this->isTest) {
            $printer->forceFill(['last_test_at' => now()])->save();
            $audit->log(config('printing.audit.tested'), null, $printer, [
                'printer_type' => $printer->type,
            ]);

            return;
        }

        $audit->log(
            $this->isReprint
                ? config('printing.audit.reprinted')
                : config('printing.audit.printed'),
            null,
            $order,
            [
                'printer_id' => $printer->id,
                'printer_type' => $printer->type,
                'item_count' => count($this->items),
                'is_reprint' => $this->isReprint,
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        app(AuditService::class)->log(
            config('printing.audit.failed'),
            null,
            null,
            [
                'order_id' => $this->orderId,
                'printer_id' => $this->printerId,
                'error' => $exception->getMessage(),
                'is_reprint' => $this->isReprint,
            ],
        );
    }
}
