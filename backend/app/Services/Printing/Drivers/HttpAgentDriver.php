<?php

declare(strict_types=1);

namespace App\Services\Printing\Drivers;

use App\Models\Printer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP hacia un agente local (PrintNode-style) que recibe bytes
 * ESC/POS y los envía a la impresora física conectada por USB/Bluetooth/LAN.
 *
 * `Printer::address` debe ser una URL del agente (e.g. http://10.0.0.50:9100/print).
 * El agente local es responsable del transporte físico (USB/BT/LAN).
 */
class HttpAgentDriver
{
    public function send(Printer $printer, string $payload): void
    {
        $timeout = (int) config('printing.http_agent.timeout', 5);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/octet-stream',
                    'X-Printer-Type' => $printer->type,
                    'X-Printer-Width' => (string) $printer->paper_width,
                ])
                ->withBody($payload, 'application/octet-stream')
                ->post($printer->address);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Print agent unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Print agent returned status '.$response->status());
        }
    }
}
