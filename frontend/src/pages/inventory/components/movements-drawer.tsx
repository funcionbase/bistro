import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import type { Ingredient, IngredientMovement } from '@/types/inventory';
import { ArrowRight, History, Warehouse as WarehouseIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    ingredient: Ingredient | null;
    fetchMovements: (id: string, page?: number) => Promise<{ data: IngredientMovement[]; pagination: { current_page: number; last_page: number } }>;
}

const TYPE_LABEL: Record<string, string> = {
    entry: 'Entrada',
    waste: 'Merma',
    adjustment: 'Ajuste',
    sale_consumption: 'Venta',
    transfer: 'Traslado',
};

const TYPE_BADGE_VARIANT: Record<string, NonNullable<BadgeProps['variant']>> = {
    entry: 'safe',
    waste: 'critical',
    adjustment: 'default',
    sale_consumption: 'warning',
    transfer: 'secondary',
};

export function MovementsDrawer({ open, onClose, ingredient, fetchMovements }: Props) {
    const [movements, setMovements] = useState<IngredientMovement[]>([]);
    const [loading, setLoading] = useState(false);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);

    useEffect(() => {
        if (!open || !ingredient) return;
        setLoading(true);
        setPage(1);
        fetchMovements(ingredient.id, 1)
            .then((res) => {
                setMovements(res.data);
                setLastPage(res.pagination.last_page);
            })
            .catch(() => setMovements([]))
            .finally(() => setLoading(false));
    }, [open, ingredient, fetchMovements]);

    async function loadPage(next: number) {
        if (!ingredient) return;
        setLoading(true);
        try {
            const res = await fetchMovements(ingredient.id, next);
            setMovements(res.data);
            setPage(res.pagination.current_page);
            setLastPage(res.pagination.last_page);
        } finally {
            setLoading(false);
        }
    }

    return (
        <Sheet open={open} onOpenChange={(v) => !v && onClose()}>
            <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Historial de movimientos</SheetTitle>
                    <SheetDescription>
                        {ingredient ? (
                            <>
                                {ingredient.name} · existencias totales {ingredient.total_stock} {ingredient.unit}
                            </>
                        ) : null}
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-4 space-y-2">
                    {loading ? (
                        <>
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-16 w-full" />
                        </>
                    ) : movements.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title="Sin movimientos registrados"
                            description="Cuando registres entradas, mermas o ajustes para este insumo, aparecerán aquí."
                        />
                    ) : (
                        movements.map((m) => {
                            const variant = TYPE_BADGE_VARIANT[m.type] ?? 'default';
                            const qty = Number(m.quantity);
                            const positive = qty >= 0;
                            // En transfers se muestra "origen → destino": la pata de
                            // salida (qty<0) es warehouse→dest; la de entrada (qty>0)
                            // es dest→warehouse. Así ambas patas leen origen→destino.
                            const isTransfer = m.type === 'transfer';
                            return (
                                <div key={m.id} className="bg-card rounded-lg border p-3">
                                    <div className="flex items-center justify-between">
                                        <Badge variant={variant}>{TYPE_LABEL[m.type] ?? m.type}</Badge>
                                        <span className="text-muted-foreground text-xs">
                                            {m.created_at ? new Date(m.created_at).toLocaleString('es-CO') : '—'}
                                        </span>
                                    </div>
                                    <div className="mt-2 flex items-baseline justify-between">
                                        <span
                                            className={`font-semibold tabular-nums ${
                                                positive ? 'text-[color:var(--color-status-safe)]' : 'text-destructive'
                                            }`}
                                        >
                                            {qty > 0 ? '+' : ''}
                                            {m.quantity} {ingredient?.unit}
                                        </span>
                                        {m.unit_cost !== null && (
                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                @ ${Number(m.unit_cost).toLocaleString('es-CO')}
                                            </span>
                                        )}
                                    </div>
                                    {isTransfer ? (
                                        <p className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                                            <span>{positive ? (m.dest_warehouse_name ?? '—') : (m.warehouse_name ?? '—')}</span>
                                            <ArrowRight className="h-3 w-3 shrink-0" />
                                            <span>{positive ? (m.warehouse_name ?? '—') : (m.dest_warehouse_name ?? '—')}</span>
                                        </p>
                                    ) : (
                                        m.warehouse_name && (
                                            <p className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                                                <WarehouseIcon className="h-3 w-3 shrink-0" />
                                                {m.warehouse_name}
                                            </p>
                                        )
                                    )}
                                    {m.reference && <p className="mt-1 text-sm">{m.reference}</p>}
                                    {m.actor && <p className="text-muted-foreground mt-0.5 text-xs">por {m.actor.name}</p>}
                                </div>
                            );
                        })
                    )}
                </div>

                {lastPage > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        <Button variant="outline" size="sm" onClick={() => loadPage(page - 1)} disabled={page <= 1 || loading}>
                            ← Anterior
                        </Button>
                        <span className="text-muted-foreground text-xs">
                            Página {page} de {lastPage}
                        </span>
                        <Button variant="outline" size="sm" onClick={() => loadPage(page + 1)} disabled={page >= lastPage || loading}>
                            Siguiente →
                        </Button>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
