import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { cn } from '@/lib/utils';
import type { PaymentMethod } from '@/types';
import { Banknote, CreditCard, QrCode, Smartphone, type LucideIcon } from 'lucide-react';

export type PaymentMethodPickerValue = PaymentMethod;

/**
 * Iconos asociados al método. NO viaja por shared props (Lucide es bundle-only).
 * Si se introduce un método nuevo en `config/payments.php`, agregar acá su icono.
 */
const METHOD_ICONS: Record<PaymentMethod, LucideIcon> = {
    cash: Banknote,
    card: CreditCard,
    transfer: QrCode,
    nequi: Smartphone,
    daviplata: Smartphone,
};

interface PaymentMethodPickerProps {
    value: PaymentMethodPickerValue;
    onChange: (method: PaymentMethodPickerValue) => void;
    disabled?: boolean;
    /** Override del subset de métodos disponibles. Por defecto consume `paymentMethods.methods`. */
    methods?: PaymentMethod[];
    className?: string;
}

/**
 * Selector visual de método de pago para cierre/cobro de orden. Renderiza una
 * grilla clickeable con icono + label leído del catálogo canónico
 * (`config/payments.php` vía el endpoint de bootstrap de la SPA).
 *
 * El activo se marca con tonos de `primary` para mantener el sistema de
 * tokens semánticos (v3.1). Los inactivos hacen hover muted sutil.
 *
 * No incluye `refund` como opción — las devoluciones se manejan desde
 * `OrderDetailModal` con un flujo aparte (signed `payment_receipts`).
 */
export function PaymentMethodPicker({ value, onChange, disabled, methods, className }: PaymentMethodPickerProps) {
    const catalog = usePaymentMethods();
    const visibleMethods = methods ?? catalog.methods;

    return (
        <div
            className={cn('grid gap-2', className)}
            style={{ gridTemplateColumns: `repeat(${visibleMethods.length}, minmax(0, 1fr))` }}
            role="radiogroup"
            aria-label="Método de pago"
        >
            {visibleMethods.map((key) => {
                const Icon = METHOD_ICONS[key];
                const active = value === key;
                return (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(key)}
                        disabled={disabled}
                        className={cn(
                            'flex flex-col items-center gap-1 rounded-md border p-3 text-xs transition',
                            'focus:ring-ring focus:ring-2 focus:outline-none',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                            active ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted',
                        )}
                    >
                        <Icon className="h-5 w-5" aria-hidden="true" />
                        {catalog.labels[key]}
                    </button>
                );
            })}
        </div>
    );
}
