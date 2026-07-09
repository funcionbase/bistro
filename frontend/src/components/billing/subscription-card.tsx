import { StatBlock } from '@/components/billing/stat-block';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatCOP, nextBillingDate } from '@/lib/formatters';
import type { Subscription } from '@/types';
import { Receipt } from 'lucide-react';

interface Props {
    subscription: Subscription | null;
}

export default function SubscriptionCard({ subscription }: Props) {
    if (!subscription) {
        return (
            <Card className="rounded-2xl shadow-sm">
                <CardContent className="flex flex-col items-center justify-center gap-3 py-10 text-center">
                    <Receipt className="text-muted-foreground/40 h-10 w-10" />
                    <p className="text-foreground text-sm font-medium">Aún no tienes un plan asignado</p>
                    <p className="text-muted-foreground text-xs">Contacta al soporte para activar tu suscripción.</p>
                </CardContent>
            </Card>
        );
    }

    const { plan, status } = subscription;

    return (
        <Card className="rounded-2xl shadow-sm">
            <CardContent className="flex flex-wrap items-start gap-8 p-6">
                <StatBlock label="Plan">
                    <p className="text-foreground text-lg font-semibold">{plan.name}</p>
                    <p className="text-muted-foreground text-xs capitalize">{plan.billing_cycle === 'monthly' ? 'Mensual' : plan.billing_cycle}</p>
                </StatBlock>
                <StatBlock label="Precio">
                    <p className="text-primary text-xl font-bold tabular-nums">
                        $ {formatCOP(plan.price)} <span className="text-muted-foreground text-sm font-normal">{plan.currency}/mes</span>
                    </p>
                </StatBlock>
                <StatBlock label="Próxima factura">
                    {/* Plan gratuito ($0): no se generan facturas — mostrar una fecha sería mentir. */}
                    <p className="text-foreground text-sm font-medium">{Number(plan.price) > 0 ? nextBillingDate() : 'No aplica'}</p>
                </StatBlock>
                <StatBlock label="Estado">
                    {status === 'active' ? <Badge variant="safe">Activa</Badge> : <Badge variant="secondary">Cancelada</Badge>}
                </StatBlock>
            </CardContent>
        </Card>
    );
}
