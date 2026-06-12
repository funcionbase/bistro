import type { ClosePaymentInput, PaymentMethod, TableOrder } from '@/hooks/use-tables';
import { emitDocument, lookupRecipient } from '@/lib/dian-api';
import type { DianRecipientLookup } from '@/types/dian';
import { useCallback, useMemo, useState } from 'react';

interface PaymentState {
    open: boolean;
    orderId: string | null;
    method: PaymentMethod;
    amountReceived: string;
    reference: string;
    tipAmount: string;
    submitting: boolean;
    error: string | null;
    // HU #235 — integración DIAN al flujo de cobro.
    dianRequested: boolean;
    dianPrint: boolean;
    dianClientPhone: string;
    dianLookup: DianRecipientLookup | null;
    dianLookupLoading: boolean;
    dianLookupError: string | null;
    dianEmissionError: string | null;
}

interface UseTablePaymentArgs {
    /** Orden seleccionada actualmente (fuente de los totales). */
    selectedOrder: TableOrder | null;
    /** Cierra la orden con el pago (delegado a `useTables`). */
    closeWithPayment: (orderId: string, payload: ClosePaymentInput) => Promise<{ queued: boolean }>;
    /** Limpia la selección tras un cobro exitoso. */
    onPaid: () => void;
}

interface UseTablePaymentReturn {
    paymentState: PaymentState;
    setPaymentState: React.Dispatch<React.SetStateAction<PaymentState>>;
    tipParsed: number;
    expectedTotal: number;
    cashChange: number | null;
    openPayment: (order: TableOrder) => void;
    closePaymentModal: () => void;
    submitPayment: () => Promise<void>;
    dianLookupClient: (overridePhone?: string) => Promise<void>;
}

const INITIAL_PAYMENT_STATE: PaymentState = {
    open: false,
    orderId: null,
    method: 'cash',
    amountReceived: '',
    reference: '',
    tipAmount: '',
    submitting: false,
    error: null,
    dianRequested: false,
    dianPrint: false,
    dianClientPhone: '',
    dianLookup: null,
    dianLookupLoading: false,
    dianLookupError: null,
    dianEmissionError: null,
};

/**
 * Maneja el flujo de "cerrar y cobrar" una orden de mesa: método de pago,
 * propina voluntaria, monto recibido y devuelta en efectivo. El
 * comportamiento es idéntico al que vivía inline en la página de mesas.
 *
 * HU #235: además, opcionalmente dispara la emisión DIAN (`emitDocument`)
 * cuando el cajero marca "Cliente solicita factura DIAN" en el sheet.
 * Si la emisión falla, NO se hace rollback del cobro (ya está confirmado);
 * el error se expone como `paymentState.dianEmissionError` y el cajero
 * puede reintentar desde el detalle de la orden ("Emitir documento DIAN").
 */
export function useTablePayment({ selectedOrder, closeWithPayment, onPaid }: UseTablePaymentArgs): UseTablePaymentReturn {
    const [paymentState, setPaymentState] = useState<PaymentState>(INITIAL_PAYMENT_STATE);

    const openPayment = useCallback((order: TableOrder) => {
        setPaymentState({
            ...INITIAL_PAYMENT_STATE,
            open: true,
            orderId: order.id,
        });
    }, []);

    const closePaymentModal = useCallback(() => {
        setPaymentState((p) => ({ ...p, open: false }));
    }, []);

    const tipParsed = useMemo(() => {
        const v = parseFloat(paymentState.tipAmount);
        return Number.isFinite(v) && v > 0 ? v : 0;
    }, [paymentState.tipAmount]);

    const expectedTotal = useMemo(() => (selectedOrder ? selectedOrder.total + tipParsed : 0), [selectedOrder, tipParsed]);

    const submitPayment = useCallback(async () => {
        if (!paymentState.orderId || !selectedOrder) return;

        const payload: ClosePaymentInput = { payment_method: paymentState.method };
        if (paymentState.reference.trim()) payload.reference = paymentState.reference.trim();
        if (tipParsed > 0) payload.tip_amount = tipParsed;

        if (paymentState.method === 'cash') {
            const received = parseFloat(paymentState.amountReceived);
            if (!Number.isFinite(received) || received < expectedTotal) {
                setPaymentState((p) => ({
                    ...p,
                    error: 'El monto recibido debe ser mayor o igual al total a cobrar (incluyendo propina).',
                }));
                return;
            }
            payload.amount_received = received;
        }

        setPaymentState((p) => ({ ...p, submitting: true, error: null, dianEmissionError: null }));
        try {
            const result = await closeWithPayment(paymentState.orderId, payload);

            // Cobro encolado offline (sin red): tiquete provisional, NO fiscal.
            // DIAN es online-only (plan §1/§10) — se difiere al sync. Cerramos el
            // sheet; el banner offline refleja la operación en cola.
            if (result.queued) {
                setPaymentState((p) => ({ ...p, open: false, submitting: false }));
                onPaid();
                return;
            }

            // HU #235 — emisión DIAN opt-in disparada por el cajero. FEV si
            // hay cliente identificado vía lookup (1er match completo); DEE
            // POS si no. El backend re-valida permisos + estado de orden +
            // idempotencia. Si el lookup retornó múltiples matches y ninguno
            // fue elegido explícitamente, cae a POS — la UI debió pedir al
            // cajero que eligiera con un selector.
            if (paymentState.dianRequested) {
                const firstCompleteMatch = paymentState.dianLookup?.data?.find((m) => m.dian_complete);
                const docType = firstCompleteMatch ? 'invoice' : 'pos_equivalent';
                try {
                    await emitDocument({
                        order_id: paymentState.orderId,
                        document_type: docType,
                        force_print: paymentState.dianPrint,
                    });
                } catch (emissionError) {
                    setPaymentState((p) => ({
                        ...p,
                        submitting: false,
                        dianEmissionError: (emissionError as Error).message,
                    }));
                    onPaid();
                    return;
                }
            }

            setPaymentState((p) => ({ ...p, open: false, submitting: false }));
            onPaid();
        } catch (e) {
            setPaymentState((p) => ({ ...p, submitting: false, error: (e as Error).message }));
        }
    }, [
        paymentState.orderId,
        paymentState.method,
        paymentState.reference,
        paymentState.amountReceived,
        paymentState.dianRequested,
        paymentState.dianPrint,
        paymentState.dianLookup,
        selectedOrder,
        tipParsed,
        expectedTotal,
        closeWithPayment,
        onPaid,
    ]);

    /**
     * Lookup explícito del cliente DIAN por teléfono. Lo dispara el cajero
     * con un botón en el sheet ("Buscar cliente"); evita queries por cada
     * tecla. Si encuentra Contact con perfil incompleto, el sheet muestra
     * alert con CTA "Completar datos" que abre el modal
     * `RecipientNeedsDataDialog` (a cargo del caller).
     */
    const dianLookupClient = useCallback(async (overridePhone?: string) => {
        const phone = (overridePhone ?? paymentState.dianClientPhone).trim();
        // Cuando viene de registrar un cliente nuevo, sincronizamos el teléfono
        // tecleado en el sheet con el del contacto recién creado.
        if (overridePhone !== undefined) {
            setPaymentState((p) => ({ ...p, dianClientPhone: phone }));
        }
        if (!phone) {
            setPaymentState((p) => ({ ...p, dianLookup: null, dianLookupError: 'Ingresa un teléfono primero.' }));
            return;
        }

        setPaymentState((p) => ({ ...p, dianLookupLoading: true, dianLookupError: null }));
        try {
            const result = await lookupRecipient(phone);
            setPaymentState((p) => ({ ...p, dianLookup: result, dianLookupLoading: false }));
        } catch (e) {
            setPaymentState((p) => ({
                ...p,
                dianLookupLoading: false,
                dianLookupError: (e as Error).message,
                dianLookup: null,
            }));
        }
    }, [paymentState.dianClientPhone]);

    const cashChange = useMemo(() => {
        if (paymentState.method !== 'cash' || !selectedOrder) return null;
        const received = parseFloat(paymentState.amountReceived);
        if (!Number.isFinite(received)) return null;
        return Math.max(0, received - expectedTotal);
    }, [paymentState.method, paymentState.amountReceived, selectedOrder, expectedTotal]);

    return {
        paymentState,
        setPaymentState,
        tipParsed,
        expectedTotal,
        cashChange,
        openPayment,
        closePaymentModal,
        submitPayment,
        dianLookupClient,
    };
}
