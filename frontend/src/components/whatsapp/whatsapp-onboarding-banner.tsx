import { AppLink } from '@/components/app-link';
import { Button } from '@/components/ui/button';
import { useSetupGuide } from '@/hooks/use-setup-guide';
import { useSharedData } from '@/lib/shared-data';

import { MessageCircle, X } from 'lucide-react';
import { useState } from 'react';

/** Clave de descarte por empresa: descartar en una no descarta en otra. */
function dismissKey(nit: string): string {
    return `whatsapp_onboarding_dismissed_${nit}`;
}

/**
 * Banner descartable de "conectá tu WhatsApp" en el dashboard (§8.5).
 *
 * Reutiliza la query de la guía de configuración (misma clave de react-query, sin
 * fetch extra) y solo aparece cuando la guía ya NO está mostrando el paso: guía
 * oculta o pasos esenciales completos. Así no duplica la guía cuando está visible,
 * pero rescata el caso de quien la cerró sin conectar el número.
 *
 * El descarte se recuerda en `localStorage` por empresa: una vez descartado NO
 * reaparece al recargar. Vuelve si el número se conecta y luego se desconecta
 * (el paso vuelve a estar incompleto) — pero eso es otra situación, no el mismo
 * aviso reapareciendo.
 */
export function WhatsappOnboardingBanner() {
    const { data } = useSetupGuide();
    const { activeCompany } = useSharedData();
    const nit = activeCompany?.nit ?? '';
    const [dismissed, setDismissed] = useState(() => {
        if (typeof window === 'undefined' || nit === '') return false;
        return window.localStorage.getItem(dismissKey(nit)) === '1';
    });

    const dismiss = () => {
        if (nit !== '') {
            window.localStorage.setItem(dismissKey(nit), '1');
        }
        setDismissed(true);
    };

    if (!data || dismissed) {
        return null;
    }

    const step = data.steps.find((s) => s.id === 'whatsapp');
    if (!step || step.completed) {
        return null;
    }

    // Si la guía está a la vista y sin terminar, ya muestra este paso: no lo
    // repetimos. El banner es para cuando la guía está oculta o ya se completó.
    if (!data.dismissed && !data.allDone) {
        return null;
    }

    return (
        <div className="border-border bg-card flex items-center gap-3 rounded-lg border p-4">
            <MessageCircle className="h-5 w-5 shrink-0 text-[color:var(--color-status-safe)]" />
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">Conectá tu WhatsApp</p>
                <p className="text-muted-foreground text-xs">
                    Atendé a tus clientes desde el panel, sin usar el celular del dueño. Toma menos de dos minutos.
                </p>
            </div>
            <Button asChild size="sm" variant="outline" className="shrink-0">
                <AppLink href="/company/whatsapp">Conectar</AppLink>
            </Button>
            <button
                type="button"
                onClick={dismiss}
                className="hover:bg-muted flex h-8 w-8 shrink-0 items-center justify-center rounded"
                aria-label="Ocultar este aviso"
            >
                <X className="h-4 w-4" />
            </button>
        </div>
    );
}
