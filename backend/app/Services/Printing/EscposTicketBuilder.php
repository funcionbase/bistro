<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\Printer;

/**
 * Genera un buffer ESC/POS textual para una comanda. Implementación sin
 * dependencia externa (sólo comandos crudos init/cut/feed) para permitir
 * impresión por agente HTTP que entiende bytes ESC/POS estándar.
 *
 * Si en el futuro se requiere QR/imagen, migrar a `mike42/escpos-php` sin
 * cambiar la firma de `build()`.
 */
class EscposTicketBuilder
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** @param array<int,array<string,mixed>> $items */
    public function build(Order $order, Printer $printer, array $items, bool $isReprint = false): string
    {
        $width = max(32, (int) (($printer->paper_width ?? 80) === 58 ? 32 : 42));
        $typeLabel = mb_strtoupper(config('printing.types.'.$printer->type, $printer->type));

        $out = self::ESC.'@'; // init
        $out .= self::ESC.'a'.chr(1); // center
        $out .= self::doubleSize();
        $out .= $typeLabel."\n";
        $out .= self::normalSize();

        $header = $order->order_type === 'dine_in'
            ? 'MESA '.self::sanitizePrintable((string) ($order->table_number ?? '-'))
            : mb_strtoupper((string) ($order->order_type ?? ''));
        $out .= $header."\n";

        $time = optional($order->ordered_at)->setTimezone(config('orders.timezone'))->format('H:i') ?? now()->format('H:i');
        $out .= $time.'  ord#'.$order->id."\n";

        if ($isReprint) {
            $out .= "[ RE-IMPRESION ]\n";
        }

        $out .= self::ESC.'a'.chr(0); // left
        $out .= str_repeat('-', $width)."\n";

        $notes = [];
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 1);
            $name = self::sanitizePrintable((string) ($item['name'] ?? ''));
            $line = sprintf('%dx %s', $qty, $name);
            $out .= mb_substr($line, 0, $width)."\n";

            if (! empty($item['notes'])) {
                $notes[] = '- '.$name.': '.self::sanitizePrintable((string) $item['notes']);
            }
        }

        if ($notes !== []) {
            $out .= str_repeat('-', $width)."\n";
            $out .= "NOTAS:\n";
            foreach ($notes as $n) {
                $out .= mb_substr($n, 0, $width)."\n";
            }
        }

        $out .= str_repeat('-', $width)."\n";
        $out .= "\n\n\n";
        $out .= self::GS.'V'.chr(66).chr(0); // partial cut

        return $out;
    }

    private static function doubleSize(): string
    {
        return self::GS.'!'.chr(0x11);
    }

    private static function normalSize(): string
    {
        return self::GS.'!'.chr(0x00);
    }

    /**
     * Filtra bytes que la impresora térmica interpreta como comandos
     * (`\x1B`/ESC, `\x1D`/GS) y otros control characters peligrosos
     * cuando vienen de texto del usuario (nombre del item, notas,
     * número de mesa). Sin esto, un cliente podría inyectar comandos
     * ESC/POS arbitrarios (abrir cajón, cortar papel, kick-out).
     *
     * Preserva `\n` (LF) que la lógica usa para line breaks naturales.
     */
    private static function sanitizePrintable(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value) ?? '';
    }
}
