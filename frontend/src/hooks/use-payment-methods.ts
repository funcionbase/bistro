import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod, PaymentMethodsConfig } from '@/types';

/**
 * Fallback embebido si el contexto global de la SPA no está disponible aún
 * (e.g., primer render antes del bootstrap o pantallas públicas sin JWT).
 *
 * Debe coincidir con `config/payments.php`. Cualquier cambio aquí debe
 * replicarse allá. Ver `bistro/backend/constants/PAYMENT_METHODS.md`.
 */
export const PAYMENT_METHODS_FALLBACK: PaymentMethodsConfig = {
    methods: ['cash', 'card', 'transfer', 'nequi', 'daviplata'],
    receipt_methods: ['cash', 'card', 'transfer', 'refund'],
    labels: {
        cash: 'Efectivo',
        card: 'Tarjeta',
        transfer: 'Transferencia',
        nequi: 'Nequi',
        daviplata: 'Daviplata',
        refund: 'Devolución',
    },
    requires_reference: ['card', 'transfer', 'nequi', 'daviplata'],
};

/**
 * Hook para consumir el catálogo canónico de métodos de pago.
 * Lee desde el contexto global de la SPA con fallback embebido.
 */
export function usePaymentMethods(): PaymentMethodsConfig {
    const page = { props: useSharedData() };
    return page.props.paymentMethods ?? PAYMENT_METHODS_FALLBACK;
}

export function paymentMethodLabel(config: PaymentMethodsConfig | undefined, method: PaymentMethod | 'refund'): string {
    const cfg = config ?? PAYMENT_METHODS_FALLBACK;
    return cfg.labels[method] ?? method;
}

export function paymentRequiresReference(config: PaymentMethodsConfig | undefined, method: PaymentMethod | 'refund'): boolean {
    const cfg = config ?? PAYMENT_METHODS_FALLBACK;
    return cfg.requires_reference.includes(method);
}
