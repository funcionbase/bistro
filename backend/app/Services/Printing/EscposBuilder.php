<?php

namespace App\Services\Printing;

/**
 * Constructor de buffers ESC/POS para impresoras térmicas (subset Epson estándar).
 *
 * Solo emite los comandos requeridos por recibos de venta y comandas:
 * init, alineación, énfasis, doble alto/ancho, separadores, corte total y caja
 * registradora opcional. Los métodos retornan $this para encadenamiento.
 *
 * Las impresoras de 58mm imprimen 32 columnas en fuente A; las de 80mm, 48.
 * El ancho se inyecta vía constructor para que helpers como line() y twoCols()
 * generen separadores y alineaciones consistentes con la impresora destino.
 */
class EscposBuilder
{
    public const WIDTH_58MM = 32;

    public const WIDTH_80MM = 48;

    private string $buffer = '';

    public function __construct(private readonly int $cols = self::WIDTH_58MM) {}

    public static function forWidth(int $widthMm): self
    {
        return new self($widthMm === 80 ? self::WIDTH_80MM : self::WIDTH_58MM);
    }

    public function init(): self
    {
        return $this->raw("\x1B\x40"); // ESC @
    }

    public function alignLeft(): self
    {
        return $this->raw("\x1B\x61\x00");
    }

    public function alignCenter(): self
    {
        return $this->raw("\x1B\x61\x01");
    }

    public function alignRight(): self
    {
        return $this->raw("\x1B\x61\x02");
    }

    public function boldOn(): self
    {
        return $this->raw("\x1B\x45\x01");
    }

    public function boldOff(): self
    {
        return $this->raw("\x1B\x45\x00");
    }

    /** Doble alto y doble ancho. */
    public function doubleSize(): self
    {
        return $this->raw("\x1D\x21\x11");
    }

    public function normalSize(): self
    {
        return $this->raw("\x1D\x21\x00");
    }

    public function feed(int $lines = 1): self
    {
        return $this->raw(str_repeat("\n", max(1, $lines)));
    }

    public function cut(): self
    {
        // GS V 0 — full cut. Algunas térmicas requieren feed previo para no
        // cortar texto: emitimos 4 saltos de línea como margen seguro.
        return $this->feed(4)->raw("\x1D\x56\x00");
    }

    /** Pulso para abrir cajón conectado al puerto DK (pin 2 por defecto). */
    public function openDrawer(): self
    {
        return $this->raw("\x1B\x70\x00\x32\xFA");
    }

    public function text(string $text): self
    {
        return $this->raw($this->encode($text)."\n");
    }

    /**
     * Línea de dos columnas: $left a la izquierda, $right pegado a la derecha,
     * relleno con espacios. Si la suma excede el ancho, recorta $left.
     */
    public function twoCols(string $left, string $right): self
    {
        $left = $this->encode($left);
        $right = $this->encode($right);
        $rightLen = mb_strlen($right, '8bit');
        $space = $this->cols - $rightLen;

        if ($space < 1) {
            return $this->raw($left."\n".$right."\n");
        }

        if (mb_strlen($left, '8bit') > $space - 1) {
            $left = mb_substr($left, 0, $space - 1, '8bit');
        }

        $padding = str_repeat(' ', $this->cols - mb_strlen($left, '8bit') - $rightLen);

        return $this->raw($left.$padding.$right."\n");
    }

    public function rule(string $char = '-'): self
    {
        return $this->raw(str_repeat($char, $this->cols)."\n");
    }

    public function raw(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    /**
     * Imprime un QR code nativo ESC/POS (familia GS ( k, comandos 81-87).
     *
     * Soportado por la mayoría de impresoras Epson TM-T20/T82/T88 y clones
     * chinos comunes en Colombia (XPrinter, ZJ, EPOS). Para impresoras sin
     * soporte de QR nativo, el `DianReceiptBuilder` cae a renderizar el
     * CUFE/CUDE legible y un texto que el cliente puede teclear.
     *
     * Parámetros (DIAN aprueba size 6-8, EC level M):
     *  - $model: 49 (Model 2 — el estándar moderno).
     *  - $size: 4-16 (módulo de cada celda en dots). Default 6 = ~30mm en
     *    80mm; 4-5 para tirillas 58mm con poca data; 8 para alta lectura.
     *  - $errorCorrection: 48 (L) | 49 (M) | 50 (Q) | 51 (H). Usamos 49 (M)
     *    por default — balance entre tamaño y robustez.
     */
    public function qrCode(string $data, int $size = 6, int $errorCorrection = 49, int $model = 49): self
    {
        $data = $this->encode($data);
        $size = max(4, min(16, $size));
        $errorCorrection = in_array($errorCorrection, [48, 49, 50, 51], true) ? $errorCorrection : 49;

        // Función 165 — selecciona modelo (1 o 2). [Function 165] GS ( k pL pH cn 65 n1 n2
        $this->raw("\x1D\x28\x6B\x04\x00\x31\x41".chr($model)."\x00");
        // Función 167 — define tamaño del módulo. GS ( k pL pH cn 67 n
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x43".chr($size));
        // Función 169 — define nivel de error correction. GS ( k pL pH cn 69 n
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x45".chr($errorCorrection));
        // Función 180 — almacena los datos en el buffer. GS ( k pL pH cn 80 m d1..dk
        $len = strlen($data) + 3;
        $pL = $len & 0xFF;
        $pH = ($len >> 8) & 0xFF;
        $this->raw("\x1D\x28\x6B".chr($pL).chr($pH)."\x31\x50\x30".$data);
        // Función 181 — imprime el QR almacenado. GS ( k pL pH cn 81 m
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x51\x30");

        return $this->raw("\n");
    }

    /**
     * Convierte UTF-8 → CP437/CP850. Las térmicas más comunes en Colombia
     * traen Code Page PC850 (Latin-1). Tildes y eñe se preservan.
     */
    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }
}
