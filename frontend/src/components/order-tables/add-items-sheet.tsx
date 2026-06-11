import { Alert, AlertDescription } from '@/components/ui/alert';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { AddItemsCartLine } from '@/hooks/use-add-items';
import type { TableOrder } from '@/hooks/use-tables';
import type { MenuItem, RestaurantMenu } from '@/types';
import { AlertCircle, Plus, Utensils } from 'lucide-react';

interface AddItemsSheetProps {
    isOpen: boolean;
    order: TableOrder | null;
    cart: Record<string, AddItemsCartLine>;
    activeMenu: RestaurantMenu | null;
    menuLoading: boolean;
    submitting: boolean;
    submitError: string | null;
    addBreakdown: { subtotal: number; tax_amount: number; total: number };
    addCartTotal: number;
    formatCurrency: (value: number) => string;
    onClose: () => void;
    onIncrement: (item: MenuItem, category: string) => void;
    onDecrement: (itemId: string) => void;
    onSubmit: () => void;
}

/**
 * Sheet para agregar productos del menú activo a una orden de mesa abierta.
 * Muestra el menú por categorías, el carrito con cantidades y el preview
 * del total con desglose tributario. Extraído de la página de mesas —
 * comportamiento idéntico.
 */
export function AddItemsSheet({
    isOpen,
    order,
    cart,
    activeMenu,
    menuLoading,
    submitting,
    submitError,
    addBreakdown,
    addCartTotal,
    formatCurrency,
    onClose,
    onIncrement,
    onDecrement,
    onSubmit,
}: AddItemsSheetProps) {
    return (
        <BottomSheetDialog
            isOpen={isOpen}
            onClose={onClose}
            title={order ? `Agregar a mesa ${order.table_number}` : ''}
            className="max-w-lg"
        >
            <div className="flex max-h-[75vh] flex-col">
                <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-4">
                    {menuLoading && (
                        <div className="flex flex-col gap-2">
                            <Skeleton className="h-8 w-full" />
                            <Skeleton className="h-8 w-full" />
                            <Skeleton className="h-8 w-full" />
                        </div>
                    )}
                    {!menuLoading && !activeMenu && <p className="text-muted-foreground text-sm">No hay menú activo configurado.</p>}
                    {!menuLoading && activeMenu && (
                        <div className="flex flex-col gap-4">
                            {(activeMenu.structure?.categories ?? []).map((cat) => (
                                <div key={cat.id} className="flex flex-col gap-1.5">
                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px] font-semibold tracking-[0.15em] uppercase">
                                        <Utensils className="h-3 w-3" aria-hidden="true" />
                                        {cat.name}
                                    </div>
                                    {cat.items
                                        .filter((it) => it.available)
                                        .map((it) => {
                                            const inCart = cart[it.id]?.quantity ?? 0;
                                            return (
                                                <div
                                                    key={it.id}
                                                    className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <div className="truncate font-medium">{it.name}</div>
                                                        <div className="text-muted-foreground text-xs tabular-nums">
                                                            {formatCurrency(it.price)}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {inCart > 0 && (
                                                            <Button size="sm" variant="outline" onClick={() => onDecrement(it.id)}>
                                                                −
                                                            </Button>
                                                        )}
                                                        {inCart > 0 && <span className="w-6 text-center text-sm tabular-nums">{inCart}</span>}
                                                        <Button size="sm" variant="outline" onClick={() => onIncrement(it, cat.name)}>
                                                            <Plus className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="bg-background flex flex-col gap-2 border-t p-4">
                    {addBreakdown.tax_amount > 0 && (
                        <div className="space-y-0.5 text-xs">
                            <div className="text-muted-foreground flex items-center justify-between">
                                <span>Subtotal</span>
                                <span className="tabular-nums">{formatCurrency(addBreakdown.subtotal)}</span>
                            </div>
                            <div className="text-muted-foreground flex items-center justify-between">
                                <span>Impuesto</span>
                                <span className="tabular-nums">{formatCurrency(addBreakdown.tax_amount)}</span>
                            </div>
                        </div>
                    )}
                    <div className="flex items-center justify-between text-sm font-semibold">
                        <span>A agregar</span>
                        <span className="tabular-nums">{formatCurrency(addCartTotal)}</span>
                    </div>
                    {submitError && (
                        <Alert variant="destructive" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                            <AlertCircle className="h-3.5 w-3.5" />
                            <AlertDescription className="text-xs">{submitError}</AlertDescription>
                        </Alert>
                    )}
                    <div className="flex gap-2">
                        <Button variant="outline" className="flex-1" onClick={onClose} disabled={submitting}>
                            Cerrar
                        </Button>
                        <Button className="flex-1" disabled={submitting || Object.keys(cart).length === 0} onClick={onSubmit}>
                            {submitting ? 'Agregando…' : 'Agregar a la cuenta'}
                        </Button>
                    </div>
                </div>
            </div>
        </BottomSheetDialog>
    );
}
