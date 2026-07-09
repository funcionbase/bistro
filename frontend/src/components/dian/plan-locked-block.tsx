import { Card } from '@/components/ui/card';
import { Lock } from 'lucide-react';

/**
 * Bloqueo por plan (#facturación-dian): la empresa puede entrar a cualquier
 * pantalla de facturación DIAN (quedan visibles en el sidebar para todos),
 * pero sin Plan Plus no ven tabs, filtros ni data — solo este aviso.
 */
export function PlanLockedBlock() {
    return (
        <Card className="space-y-3 p-6 text-center">
            <Lock className="text-muted-foreground mx-auto h-8 w-8" />
            <div className="space-y-1">
                <p className="text-foreground font-semibold">Opción no incluida en tu plan actual</p>
                <p className="text-muted-foreground mx-auto max-w-md text-sm">
                    La facturación electrónica DIAN hace parte del Plan Plus: $300.000 COP/mes más $10 COP por cada factura electrónica
                    generada. Contactá a soporte para actualizar tu plan.
                </p>
            </div>
        </Card>
    );
}
