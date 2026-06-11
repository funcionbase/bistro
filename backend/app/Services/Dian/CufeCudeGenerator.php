<?php

declare(strict_types=1);

namespace App\Services\Dian;

/**
 * Generador determinístico de CUFE / CUDE DIAN (algoritmo oficial).
 *
 * - CUFE: factura electrónica de venta (FEV), notas crédito/débito FEV.
 *     SHA-384 sobre NumFac+FecFac+HorFac+ValFac+CodImp1+ValImp1+CodImp2+
 *     ValImp2+CodImp3+ValImp3+NitOFE+NumAdq+ClTec+TipoAmbiente
 *
 * - CUDE: documento equivalente POS (DEE POS) y NC DEE POS.
 *     Misma estructura pero con NumPOS/FecPOS/HorPOS/ValPOS y DocAdq en
 *     lugar de NumAdq.
 *
 * Reglas de formateo (críticas — DIAN rechaza si fallan):
 *  - Montos: format `0.00` con punto decimal, dos decimales (PHP
 *    `number_format($v, 2, '.', '')`).
 *  - Fecha emisión: `YYYY-MM-DD` (10 chars).
 *  - Hora emisión: `HH:MM:SS-05:00` (offset Colombia fijo).
 *  - NIT emisor: solo dígitos, SIN dígito verificación.
 *  - Doc adquirente: solo dígitos cuando es NIT, sin guiones ni espacios.
 *  - TipoAmbiente: `1` (produccion) o `2` (habilitacion).
 *  - Output: 96 chars hex lowercase.
 *
 * El método público es determinístico: mismos inputs → mismo hash. Esto es
 * load-bearing para la idempotencia (la UNIQUE en `unique_code` atrapa
 * cualquier doble emisión en concurrencia).
 */
class CufeCudeGenerator
{
    /**
     * @param  array{
     *   full_number: string,
     *   issued_at: \DateTimeInterface,
     *   total: float|string,
     *   iva_amount?: float|string,
     *   inc_amount?: float|string,
     *   ica_amount?: float|string,
     *   issuer_nit: string,
     *   recipient_doc_number: string,
     *   technical_key: string,
     *   environment: string,
     * }  $payload
     */
    public function generate(array $payload): string
    {
        $environmentCode = (string) config("dian.environment_codes.{$payload['environment']}", '2');
        $taxCodes = config('dian.tax_codes', ['iva' => '01', 'inc' => '04', 'ica' => '03']);

        $issuedAt = $payload['issued_at'];
        $date = $issuedAt->format('Y-m-d');
        $time = $issuedAt->format('H:i:s').'-05:00';

        $canonical = implode('', [
            $payload['full_number'],
            $date,
            $time,
            $this->money($payload['total']),
            $taxCodes['iva'], $this->money($payload['iva_amount'] ?? 0),
            $taxCodes['inc'], $this->money($payload['inc_amount'] ?? 0),
            $taxCodes['ica'], $this->money($payload['ica_amount'] ?? 0),
            $this->onlyDigits($payload['issuer_nit']),
            $this->onlyDigits($payload['recipient_doc_number']),
            $payload['technical_key'],
            $environmentCode,
        ]);

        return hash('sha384', $canonical);
    }

    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
