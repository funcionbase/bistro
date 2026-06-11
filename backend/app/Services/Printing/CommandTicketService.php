<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Jobs\PrintCommandTicketJob;
use App\Models\Order;
use App\Models\Printer;
use Illuminate\Support\Facades\Log;

/**
 * Particiona los items de una orden por categoría y los enruta a las
 * impresoras de comanda activas (kitchen|bar) cuyas `categories` contengan
 * la categoría del ítem. Despacha un job por (printer, items_subset).
 *
 * Ítems sin categoría o sin impresora destino → log de warning (no bloquea
 * el flujo de la orden — la comanda no es comprobante fiscal).
 */
class CommandTicketService
{
    /**
     * @return array{queued:int, orphan_items:int}
     */
    public function printForOrder(Order $order, bool $isReprint = false): array
    {
        $printers = Printer::query()
            ->forCompany((string) $order->company_nit)
            ->active()
            ->whereIn('type', config('printing.command_types'))
            ->get();

        if ($printers->isEmpty()) {
            return ['queued' => 0, 'orphan_items' => count($order->items ?? [])];
        }

        $items = $order->items ?? [];
        $byPrinter = [];
        $orphans = 0;

        foreach ($items as $item) {
            $category = (string) ($item['category'] ?? '');
            $matched = false;

            foreach ($printers as $printer) {
                if ($printer->matchesCategory($category)) {
                    $byPrinter[$printer->id][] = $item;
                    $matched = true;
                }
            }

            if (! $matched) {
                $orphans++;
            }
        }

        if ($orphans > 0) {
            Log::warning('CommandTicketService: items without printer', [
                'order_id' => $order->id,
                'company_nit' => $order->company_nit,
                'orphan_count' => $orphans,
            ]);
        }

        $queued = 0;
        foreach ($byPrinter as $printerId => $subset) {
            PrintCommandTicketJob::dispatch($order->id, (string) $printerId, $subset, $isReprint);
            $queued++;
        }

        return ['queued' => $queued, 'orphan_items' => $orphans];
    }

    /**
     * Genera un ticket de prueba (1 ítem ficticio) para validar conectividad
     * del agente HTTP. No depende de una orden real.
     */
    public function dispatchTest(Printer $printer): void
    {
        PrintCommandTicketJob::dispatch(
            null,
            $printer->id,
            [['name' => 'PRUEBA DE IMPRESION', 'quantity' => 1, 'category' => 'test']],
            false,
            true,
        );
    }
}
