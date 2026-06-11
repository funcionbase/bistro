import { apiFetch } from '@/lib/api';
import { downloadBinary, isWebUsbSupported, pickThermalPrinter, sendBinaryToPrinter } from '@/lib/printing/escpos-printer';
import { useState } from 'react';

type Props = {
    orderId: string;
    width?: 58 | 80;
    copy?: boolean;
    label?: string;
    className?: string;
};

/**
 * Botón "Imprimir recibo": pide el binario ESC/POS al backend
 * (`GET /api/v1/orders/{id}/receipt-escpos`) y lo envía a una impresora
 * térmica vía WebUSB. En navegadores sin WebUSB cae al fallback de descarga
 * del .bin para que el usuario lo entregue a un agente LAN.
 *
 * Re-imprimir es idempotente y no muta payment_receipts (regla contable).
 */
export function PrintReceiptButton({ orderId, width, copy = false, label = 'Imprimir recibo', className }: Props) {
    const [isPrinting, setIsPrinting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function fetchBinary(): Promise<ArrayBuffer> {
        const params = new URLSearchParams();
        if (width) params.set('width', String(width));
        if (copy) params.set('copy', 'true');

        const url = `/api/v1/orders/${orderId}/receipt-escpos${params.toString() ? `?${params}` : ''}`;
        const res = await apiFetch(url, { headers: { Accept: 'application/octet-stream' } });

        if (!res.ok) {
            let msg = 'No se pudo generar el recibo';
            try {
                const data = await res.json();
                msg = data?.message ?? msg;
            } catch {
                /* ignore */
            }
            throw new Error(msg);
        }

        return await res.arrayBuffer();
    }

    async function handleClick() {
        setError(null);
        setIsPrinting(true);

        try {
            const binary = await fetchBinary();

            if (!isWebUsbSupported()) {
                downloadBinary(`receipt-${orderId}.bin`, binary);
                return;
            }

            try {
                const device = await pickThermalPrinter();
                await sendBinaryToPrinter(device, binary);
            } catch (usbErr) {
                // El usuario canceló la selección o la impresora rechazó: ofrecer descarga.
                downloadBinary(`receipt-${orderId}.bin`, binary);
                throw usbErr;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error desconocido');
        } finally {
            setIsPrinting(false);
        }
    }

    return (
        <div className={className}>
            <button
                type="button"
                onClick={handleClick}
                disabled={isPrinting}
                className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {isPrinting ? 'Imprimiendo…' : label}
            </button>
            {error && <p className="text-destructive mt-1 text-xs">{error}</p>}
        </div>
    );
}
