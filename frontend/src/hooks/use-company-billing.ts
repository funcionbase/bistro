import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { type BillingSubscriptionData } from '@/types';
import { useEffect, useState } from 'react';

/** Contrato de servicio aceptado por el owner al crear la empresa. */
export interface AcceptedContract {
    version: string;
    accepted_at: string | null;
    accepter_name: string | null;
    content: string;
    ip_address: string | null;
    latest_published?: {
        version: string;
        published_at: string | null;
    };
}

interface UseCompanyBillingReturn {
    billingData: BillingSubscriptionData | null;
    billingLoading: boolean;
    billingError: string | null;
    acceptedContract: AcceptedContract | null;
}

/**
 * Carga los datos de facturación (suscripción, mora) y el contrato de
 * servicio aceptado para el tab "Facturación" de `company/settings.tsx`.
 * Comportamiento y endpoints idénticos a los que vivían inline.
 */
export function useCompanyBilling(): UseCompanyBillingReturn {
    const activeToken = useToken();

    const [billingData, setBillingData] = useState<BillingSubscriptionData | null>(null);
    const [billingLoading, setBillingLoading] = useState(false);
    const [billingError, setBillingError] = useState<string | null>(null);
    const [acceptedContract, setAcceptedContract] = useState<AcceptedContract | null>(null);

    useEffect(() => {
        apiFetch('/api/v1/company/accepted-contract')
            .then((r) => (r.ok ? r.json() : { data: null }))
            .then((d) => setAcceptedContract(d.data ?? null))
            .catch(() => setAcceptedContract(null));
    }, []);

    useEffect(() => {
        if (!activeToken) return;

        let isMounted = true;
        setBillingLoading(true);
        setBillingError(null);

        apiFetch('/api/v1/billing/subscription')
            .then(async (res) => {
                if (res.status === 403) {
                    if (isMounted) setBillingError('No tienes permiso para ver la facturación de esta empresa.');
                    return;
                }
                const data = await res.json();
                if (isMounted) setBillingData(data);
            })
            .catch(() => {
                if (isMounted) setBillingError('Error de conexión al cargar la facturación.');
            })
            .finally(() => {
                if (isMounted) setBillingLoading(false);
            });

        return () => {
            isMounted = false;
        };
    }, [activeToken]);

    return { billingData, billingLoading, billingError, acceptedContract };
}
