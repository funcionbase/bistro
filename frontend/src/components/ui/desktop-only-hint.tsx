import { Monitor } from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * Hint mobile-first para pantallas operativas pensadas para tablet/desktop
 * (KDS de cocina, planificadores semanales/mensuales). No bloquea — solo
 * advierte y deja al usuario continuar.
 *
 * Se renderiza solo en `<sm` (640px) via `sm:hidden`; en sm+ no aparece.
 *
 * Uso:
 * ```tsx
 * <DesktopOnlyHint
 *   title="Mejor en pantalla grande"
 *   description="Este tablero está pensado para monitor o tablet horizontal."
 * />
 * ```
 */
interface DesktopOnlyHintProps {
    title?: string;
    description?: string;
    className?: string;
}

export function DesktopOnlyHint({
    title = 'Mejor en pantalla grande',
    description = 'Esta vista está optimizada para tablet o desktop. Podés seguir usándola, pero algunos elementos pueden quedar ajustados.',
    className,
}: DesktopOnlyHintProps) {
    return (
        <Alert className={`sm:hidden ${className ?? ''}`}>
            <Monitor className="h-4 w-4" />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>{description}</AlertDescription>
        </Alert>
    );
}
