import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import type { Company } from '@/types';
import { Check, Copy, Wallet } from 'lucide-react';
import { useState } from 'react';

interface FuncionbasePaymentInfoProps {
    payment: NonNullable<Company['funcionbase_payment']>;
}

/**
 * Sección informativa visible en `/company/settings → Facturación` con los
 * datos de transferencia interbancaria hacia bistro.
 *
 * Visible SIEMPRE (no solo en past_due/suspended) para que el cliente pueda
 * pagar proactivamente cada mes. Renderiza identificación fiscal (NIT/DV/
 * razón social) + datos bancarios (Bre-B, banco, cuenta, titular) + contacto
 * facturación. Cada campo copiable tiene botón "Copiar" con feedback visual.
 *
 * Reusable: la prop `payment` viene del bootstrap (shared-data). El caller
 * decide si renderizar (oculta cuando todos los campos son null).
 */
export function FuncionbasePaymentInfo({ payment }: FuncionbasePaymentInfoProps) {
    const hasAnyBankData = payment.breb_key !== null || payment.account_number !== null;

    if (!hasAnyBankData && payment.nit === null) {
        return null;
    }

    const accountTypeLabel = payment.account_type === 'ahorros' ? 'ahorros' : payment.account_type === 'corriente' ? 'corriente' : (payment.account_type ?? 'cuenta');
    const fullNit = payment.nit !== null ? (payment.dv !== null ? `${payment.nit}-${payment.dv}` : payment.nit) : null;

    return (
        <DashboardPanel title="Datos para transferir a bistro" icon={Wallet}>
            <div className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    Realiza la transferencia mensual a la siguiente cuenta. Apenas recibamos el pago, la suscripción queda al día.
                </p>

                {/* Sección Bre-B destacada (la forma más rápida en CO). */}
                {payment.breb_key !== null && payment.breb_key !== '' && (
                    <div className="border-primary/30 bg-primary/5 rounded-2xl border p-4">
                        <p className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Bre-B (pago inmediato)</p>
                        <div className="mt-1 flex items-center justify-between gap-3">
                            <p className="text-primary font-brand text-2xl tabular-nums">{payment.breb_key}</p>
                            <CopyButton value={payment.breb_key} label="Copiar llave Bre-B" />
                        </div>
                    </div>
                )}

                {/* Grid con datos del titular + cuenta tradicional. */}
                <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    {fullNit !== null && (
                        <PaymentField label="NIT" value={fullNit} mono copyable />
                    )}
                    {payment.legal_name !== null && payment.legal_name !== '' && (
                        <PaymentField label="Razón social" value={payment.legal_name} />
                    )}
                    {payment.account_holder !== null && payment.account_holder !== '' && (
                        <PaymentField label="Titular cuenta" value={payment.account_holder} />
                    )}
                    {payment.bank_name !== null && payment.bank_name !== '' && (
                        <PaymentField label="Banco" value={payment.bank_name} />
                    )}
                    {payment.account_number !== null && payment.account_number !== '' && (
                        <PaymentField
                            label={`Cuenta ${accountTypeLabel}`}
                            value={payment.account_number}
                            mono
                            copyable
                        />
                    )}
                    {payment.billing_email !== null && payment.billing_email !== '' && (
                        <PaymentField label="Email facturación" value={payment.billing_email} copyable />
                    )}
                    {payment.billing_phone !== null && payment.billing_phone !== '' && (
                        <PaymentField label="Soporte facturación" value={payment.billing_phone} />
                    )}
                </dl>

                <p className="text-muted-foreground border-border border-t pt-3 text-xs">
                    Tras la transferencia, la conciliación se actualiza en máximo 24 horas hábiles. Si necesitas adjuntar comprobante de pago,
                    contáctanos al email de facturación.
                </p>
            </div>
        </DashboardPanel>
    );
}

interface PaymentFieldProps {
    label: string;
    value: string;
    mono?: boolean;
    copyable?: boolean;
}

function PaymentField({ label, value, mono = false, copyable = false }: PaymentFieldProps) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">{label}</dt>
            <dd className="mt-1 flex items-center gap-2">
                <span className={`text-foreground truncate font-semibold ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</span>
                {copyable && <CopyButton value={value} label={`Copiar ${label.toLowerCase()}`} />}
            </dd>
        </div>
    );
}

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // navigator.clipboard puede no estar disponible (http inseguro o sandbox).
            // Fallback silent — el usuario puede seleccionar el texto manualmente.
        }
    };

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={handleCopy}
            aria-label={label}
            title={copied ? 'Copiado' : label}
            className="h-7 w-7 shrink-0"
        >
            {copied ? <Check className="text-success-foreground h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
        </Button>
    );
}
