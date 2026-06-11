import { useEffect, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppLink } from '@/components/app-link';
import { listResolutions } from '@/lib/dian-api';
import { route } from '@/lib/route-compat';
import type { DianResolution } from '@/types/dian';

/**
 * Banner que aparece en el dashboard cuando alguna resolución DIAN está
 * próxima a vencer (<30 días) o ya está agotada. Acción: ir a
 * /company/dian → tab Resoluciones.
 *
 * Silencioso si no hay resoluciones registradas o todas están sanas.
 */
export function ResolutionExpirationAlert() {
    const [expiring, setExpiring] = useState<DianResolution[]>([]);

    useEffect(() => {
        listResolutions()
            .then(({ data }) =>
                setExpiring(data.filter((r) => r.is_active && (r.is_expiring_soon || r.is_exhausted))),
            )
            .catch(() => setExpiring([]));
    }, []);

    if (expiring.length === 0) {
        return null;
    }

    return (
        <Alert variant="warning">
            <AlertTitle>Resolución DIAN próxima a vencer</AlertTitle>
            <AlertDescription>
                Tenés {expiring.length} resolución{expiring.length === 1 ? '' : 'es'} por vencer o agotada.{' '}
                <AppLink href={route('company.dian')} className="underline font-medium">
                    Configurar
                </AppLink>
            </AlertDescription>
        </Alert>
    );
}
