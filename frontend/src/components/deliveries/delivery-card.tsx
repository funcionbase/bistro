import { CourierAvatar } from '@/components/deliveries/courier-avatar';
import { Timer } from '@/components/deliveries/timer';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { Delivery } from '@/types';
import { CheckCircle, Loader2, Truck } from 'lucide-react';

interface DeliveryCardProps {
    delivery: Delivery;
    onComplete: (id: string) => void;
    completing?: boolean;
}

/**
 * Card del tablero admin de deliveries (#117). Para la vista mobile-first
 * del courier ver `MyDeliveryCard` (#119).
 *
 * Tokens DS (sin colores hardcoded, #192/#119 cleanup): `text-foreground`,
 * `text-muted-foreground`, y la variante `safe` del botón usa
 * `var(--color-status-success)` indirectamente.
 */
export function DeliveryCard({ delivery, onComplete, completing = false }: DeliveryCardProps) {
    return (
        <Card className="animate-fade-in rounded-xl shadow-sm transition-all duration-200">
            <CardContent className="p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1 space-y-2">
                        <div className="flex items-center gap-2">
                            <Truck className="text-muted-foreground h-4 w-4 shrink-0" />
                            <span className="text-sm font-semibold">Orden #{delivery.order_id}</span>
                        </div>

                        {delivery.deliverer && (
                            <div className="flex items-center gap-2">
                                <CourierAvatar name={delivery.deliverer.name} size="sm" />
                                <span className="text-foreground text-xs font-medium">{delivery.deliverer.name}</span>
                            </div>
                        )}

                        <div className="text-muted-foreground text-xs">
                            <Timer startTime={delivery.assigned_at} />
                        </div>
                    </div>

                    {delivery.status === 'pending' && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-8 shrink-0 border-[color:var(--color-status-success)]/40 text-[color:var(--color-status-success)] hover:bg-[color:var(--color-status-success)]/10"
                            onClick={() => onComplete(delivery.id)}
                            disabled={completing}
                        >
                            {completing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle className="h-3.5 w-3.5" />}
                            <span className="ml-1 hidden sm:inline">Completada</span>
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
