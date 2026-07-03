import LiveIndicator from '@/components/dashboard/live-indicator';
import PendingApprovalsBanner from '@/components/orders/pending-approvals-banner';
import { AssignCourierModal } from '@/components/deliveries/assign-courier-modal';
import { ReassignModal } from '@/components/deliveries/reassign-modal';
import { OrderDetailModal } from '@/components/orders/order-detail-modal';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { OrderTypeChip } from '@/components/orders/order-type-chip';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { KanbanBoardSkeleton } from '@/components/ui/kanban-board-skeleton';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { useCourierAssignment } from '@/hooks/use-courier-assignment';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useIsMobile } from '@/hooks/use-mobile';
import { useOrders, type KanbanOrder } from '@/hooks/use-orders';
import { usePermissions } from '@/hooks/use-permissions';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { shortOrderCode } from '@/lib/order-code';
import type { OrderType } from '@/lib/order-type';
import {
    DndContext,
    DragEndEvent,
    DragOverlay,
    DragStartEvent,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';

import { useToast } from '@/components/ui/toast';
import { AlertCircle, RefreshCw, Search, Truck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Aplica una inclinacion estilo "swing" a la card en drag, modulada por la
 * velocidad horizontal del puntero. Se aplica el transform directamente al
 * nodo DOM via ref para evitar re-renderizar el board completo a 60fps.
 */
function useDragSwayRef(active: boolean) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!active) {
            if (ref.current) {
                ref.current.style.transform = 'rotate(0deg)';
            }
            return;
        }

        let target = 0;
        let current = 0;
        let velocity = 0;
        let lastX: number | null = null;
        let lastT = performance.now();
        let raf = 0;

        const onMove = (e: PointerEvent) => {
            const now = performance.now();
            if (lastX !== null) {
                const dx = e.clientX - lastX;
                const dt = Math.max(now - lastT, 1);
                const vx = dx / dt;
                target = Math.max(-22, Math.min(22, vx * 90));
            }
            lastX = e.clientX;
            lastT = now;
        };

        const tick = () => {
            const stiffness = 0.18;
            const damping = 0.62;
            const accel = (target - current) * stiffness;
            velocity = (velocity + accel) * damping;
            current += velocity;
            target *= 0.82;
            if (ref.current) {
                ref.current.style.transform = `rotate(${current}deg)`;
            }
            raf = requestAnimationFrame(tick);
        };

        window.addEventListener('pointermove', onMove, { passive: true });
        raf = requestAnimationFrame(tick);

        return () => {
            window.removeEventListener('pointermove', onMove);
            cancelAnimationFrame(raf);
        };
    }, [active]);

    return ref;
}

// Columnas del kanban. Tonos derivados de los tokens semanticos del design
// system v3.1 — funcionan en light y dark mode sin paleta plana.
//
//  pending     -> secondary (espera, sin energia)
//  in_kitchen  -> warning soft (operacion activa)
//  ready       -> primary soft (lista para accion)
//  in_transit  -> primary medio (en movimiento)
//  completed   -> safe soft (logro; sin lime — la guia reserva lime para
//                 momentos puntuales, no estados recurrentes del tablero)
//
// `rank` define el orden ordinal de avance: una orden solo puede moverse a una
// columna con rank >= rank actual (regla forward-only). Se mantiene en sincronía
// con `config('orders.kanban_rank')` del backend.
const ESTADOS = [
    {
        key: 'pending',
        label: 'Pendiente',
        color: 'bg-secondary text-secondary-foreground',
        rank: 1,
    },
    {
        key: 'in_kitchen',
        label: 'En cocina',
        color: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]',
        rank: 2,
    },
    {
        key: 'ready',
        label: 'Para entrega',
        color: 'bg-primary/15 text-primary',
        rank: 3,
    },
    {
        key: 'in_transit',
        label: 'En tránsito',
        color: 'bg-primary/25 text-primary',
        rank: 4,
    },
    {
        key: 'completed',
        label: 'Completado',
        color: 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]',
        rank: 5,
    },
] as const;

function rankOf(status: string): number {
    return ESTADOS.find((e) => e.key === status)?.rank ?? 0;
}

/**
 * Siguiente estado alcanzable con un tap (patrón KDS): forward-only y salta
 * `in_transit` para órdenes que no son domicilio. `completed` SÍ se ofrece —
 * misma regla que el drag desktop (abc270d3/b39f3320: completed = entrega
 * operativa, el cobro es independiente vía closeWithPayment). Excluirlo era
 * un remanente del gate BUG-020 ya revertido y dejaba a las órdenes `ready`
 * sin ningún camino a Completado en mobile (sin drag).
 */
function nextAdvance(order: KanbanOrder): (typeof ESTADOS)[number] | null {
    const current = rankOf(order.status);
    return (
        ESTADOS.find(
            (e) => e.rank > current && (e.key !== 'in_transit' || inferOrderType(order) === 'delivery'),
        ) ?? null
    );
}

function timeAgo(isoDate: string | null): string {
    if (!isoDate) return '';
    const diffMs = Date.now() - new Date(isoDate).getTime();
    const diffMin = Math.floor(diffMs / 60_000);
    if (diffMin < 1) return 'Hace un momento';
    if (diffMin < 60) return `Hace ${diffMin} min`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `Hace ${diffH}h`;
    return `Hace ${Math.floor(diffH / 24)}d`;
}

interface OrderCardProps {
    order: KanbanOrder;
    formatCurrency: (v: number) => string;
    onSelect?: () => void;
    onAssign?: (e: React.MouseEvent) => void;
    /** Avance directo al siguiente estado (mobile, patrón KDS): label + handler. */
    advanceLabel?: string;
    onAdvance?: (e: React.MouseEvent) => void;
    justDropped?: boolean;
}

function inferOrderType(order: KanbanOrder): OrderType | null {
    if (order.order_type) return order.order_type as OrderType;
    if (order.table_number) return 'table';
    if (order.delivery_address || order.delivery) return 'delivery';
    return null;
}

function OrderCard({ order, formatCurrency, onSelect, onAssign, advanceLabel, onAdvance, justDropped }: OrderCardProps) {
    const inferredType = inferOrderType(order);

    return (
        <Card
            className={`hover:ring-primary/60 cursor-pointer rounded-lg shadow-sm transition hover:ring-2 ${justDropped ? 'animate-drop-bounce' : ''}`}
            onClick={onSelect}
        >
            <CardHeader className="flex flex-row items-center justify-between p-3 pb-0">
                <CardTitle className="text-sm font-semibold" title={order.id}>
                    Orden #{shortOrderCode(order.id)}
                </CardTitle>
                <span className="text-muted-foreground text-xs">{timeAgo(order.ordered_at)}</span>
            </CardHeader>
            <CardContent className="space-y-2 p-3 pt-1.5">
                <OrderTypeChip type={inferredType} tableNumber={order.table_number} />
                {inferredType !== 'table' && order.client_phone && <div className="text-sm font-medium">{order.client_phone}</div>}
                <div className="flex items-center justify-between gap-2">
                    <span className="text-muted-foreground text-xs tabular-nums">{formatCurrency(order.total)}</span>
                    <OrderStatusBadge status={order.status} size="sm" />
                </div>
                <div className="text-muted-foreground text-xs">
                    {(() => { const n = order.line_items?.length ?? order.items.length; return `${n} ${n === 1 ? 'ítem' : 'ítems'}`; })()}
                </div>
                {order.delivery?.deliverer && (
                    <div className="text-primary flex items-center gap-1 text-xs">
                        <Truck className="h-3 w-3" />
                        <span>{order.delivery.deliverer.name}</span>
                    </div>
                )}
                {inferredType === 'delivery' && order.status === 'ready' && !order.delivery?.deliverer && onAssign && (
                    <Button size="sm" variant="outline" className="mt-1 h-8 w-full px-2 text-xs" onClick={onAssign}>
                        <Truck className="mr-1 h-3 w-3" />
                        Asignar repartidor
                    </Button>
                )}
                {onAdvance && advanceLabel && (
                    <Button size="sm" className="mt-1 h-9 w-full px-2 text-xs" onClick={onAdvance}>
                        → {advanceLabel}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function DraggableOrderCard(props: OrderCardProps) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: props.order.id,
        data: { status: props.order.status },
    });

    return (
        <div
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            style={{
                transform: isDragging ? undefined : CSS.Translate.toString(transform),
                visibility: isDragging ? 'hidden' : 'visible',
                touchAction: 'none',
            }}
        >
            <OrderCard {...props} />
        </div>
    );
}

function ColumnSkeleton() {
    return (
        <div className="space-y-2">
            {[1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-24 w-full rounded-lg" />
            ))}
        </div>
    );
}

interface DroppableColumnProps {
    estado: (typeof ESTADOS)[number];
    children: React.ReactNode;
    count: number;
    /** Si está definido, indica si esta columna acepta el drop actual. */
    isValidTarget?: boolean | null;
}

function DroppableColumn({ estado, children, count, isValidTarget }: DroppableColumnProps) {
    const { setNodeRef, isOver } = useDroppable({ id: estado.key, disabled: isValidTarget === false });
    const blocked = isValidTarget === false;

    return (
        <div className="flex min-w-[220px] flex-1 flex-col">
            <div
                className={`flex items-center justify-between rounded-t-lg px-3 py-2 text-sm font-semibold ${estado.color} ${
                    blocked ? 'opacity-50' : ''
                }`}
            >
                <span>{estado.label}</span>
                <Badge variant="outline" className="bg-background/80 border-transparent text-xs tabular-nums">
                    {count}
                </Badge>
            </div>
            <div
                ref={setNodeRef}
                className={`bg-muted/30 min-h-[120px] flex-1 space-y-2 overflow-y-auto rounded-b-lg p-2 transition-colors ${
                    blocked ? 'bg-muted/10 cursor-not-allowed' : isOver ? 'bg-primary/10 ring-primary ring-2 ring-inset' : ''
                }`}
                title={blocked ? 'Las órdenes solo avanzan en el tablero' : undefined}
            >
                {children}
            </div>
        </div>
    );
}

export default function KanbanBoard() {
    const token = useToken();
    const isMobile = useIsMobile();
    const formatCurrency = useCurrencyFormatter();
    const { showToast } = useToast();
    const { has } = usePermissions();
    const canAssignCourier = has('deliveries.create');
    const canReassignCourier = has('deliveries.update');

    const { orders, loading, error, lastUpdated, refresh, updateStatus } = useOrders(token);
    const { couriers, loading: couriersLoading, fetchCouriers, assignCourier } = useCourierAssignment(token);

    const [selectedOrder, setSelectedOrder] = useState<KanbanOrder | null>(null);
    const [assignOrderId, setAssignOrderId] = useState<string | null>(null);
    const [reassignContext, setReassignContext] = useState<{ orderId: string; deliveryId: string; currentUserId: string } | null>(null);
    const [mobileColumn, setMobileColumn] = useState<string>(ESTADOS[0].key);
    const [assignError, setAssignError] = useState<string | null>(null);
    const [activeDragOrder, setActiveDragOrder] = useState<KanbanOrder | null>(null);
    const [droppedOrderId, setDroppedOrderId] = useState<string | null>(null);
    const [searchInput, setSearchInput] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');

    const [searchParams, setSearchParams] = useSearchParams();

    // Auto-abrir orden si llega desde ?order=<id> (ej. desde "Ver detalle" en Ventas del día)
    useEffect(() => {
        const orderId = searchParams.get('order');
        if (!orderId || loading || orders.length === 0) return;
        const found = orders.find((o) => o.id.toLowerCase() === orderId.toLowerCase());
        if (found) {
            setSelectedOrder(found);
            setSearchParams({}, { replace: true });
        }
    }, [searchParams, orders, loading, setSearchParams]);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
    );

    // Cada columna del kanban se ordena por fecha de modificación DESC: los
    // pedidos recién movidos a esta columna (o creados) aparecen arriba; los
    // que llevan más tiempo sin tocar bajan al final. Fallback a ordered_at
    // para órdenes que (por algún motivo legacy) no traigan updated_at.
    const searchLower = appliedSearch.trim().toLowerCase();
    const visibleOrders = searchLower
        ? orders.filter(
              (o) =>
                  o.id.toLowerCase().includes(searchLower) ||
                  (o.client_phone ?? '').toLowerCase().includes(searchLower) ||
                  (o.delivery_address ?? '').toLowerCase().includes(searchLower) ||
                  (o.table_number ?? '').toLowerCase().includes(searchLower),
          )
        : orders;

    const grouped = ESTADOS.reduce<Record<string, KanbanOrder[]>>((acc, estado) => {
        const filtered = visibleOrders.filter((o) => o.status === estado.key);
        filtered.sort((a, b) => {
            const at = new Date(a.updated_at ?? a.ordered_at ?? 0).getTime();
            const bt = new Date(b.updated_at ?? b.ordered_at ?? 0).getTime();
            return bt - at; // desc — recientes primero
        });
        acc[estado.key] = filtered;
        return acc;
    }, {});

    const handleOpenAssign = (e: React.MouseEvent, orderId: string) => {
        e.stopPropagation();
        setAssignError(null);
        setAssignOrderId(orderId);
        void fetchCouriers();
    };

    const handleAssign = async (courierId: string) => {
        if (!assignOrderId) return;
        try {
            await assignCourier(assignOrderId, courierId);
            await updateStatus(assignOrderId, 'in_transit');
            setAssignOrderId(null);
            setSelectedOrder(null);
            void refresh();
        } catch (err) {
            setAssignError(err instanceof Error ? err.message : 'Error al asignar repartidor.');
        }
    };

    const handleAssignFromDetail = (orderId: string) => {
        setAssignError(null);
        setAssignOrderId(orderId);
        void fetchCouriers();
    };

    const handleAdvanceStatusFromDetail = (orderId: string, newStatus: string) => {
        setSelectedOrder(null);
        void updateStatus(orderId, newStatus)
            .then((warnings) => {
                if (warnings.length > 0) {
                    showToast('info', `Stock insuficiente al pasar a cocina: ${warnings.join(', ')} quedó en negativo.`, 8000);
                }
            })
            .catch((err) => {
                setAssignError(err instanceof Error ? err.message : 'Error al actualizar estado.');
                window.setTimeout(() => setAssignError(null), 4000);
            });
    };

    const handleReassignFromDetail = (orderId: string, deliveryId: string) => {
        const order = orders.find((o) => o.id === orderId);
        const currentUserId = order?.delivery?.deliverer?.id ?? '';
        setAssignError(null);
        setReassignContext({ orderId, deliveryId, currentUserId });
        void fetchCouriers();
    };

    const handleReassignConfirm = async (newCourierId: string, reason: string) => {
        if (!reassignContext) return;
        const res = await apiFetch(`/api/v1/deliveries/${reassignContext.deliveryId}/reassign`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: newCourierId, reason }),
        });
        if (!res.ok) {
            const body = await res.json().catch(() => ({}) as { message?: string });
            throw new Error(body.message ?? 'Error al reasignar repartidor.');
        }
        setReassignContext(null);
        setSelectedOrder(null);
        void refresh();
    };

    const handleDragStart = (event: DragStartEvent) => {
        const order = orders.find((o) => o.id === event.active.id);
        setActiveDragOrder(order ?? null);
    };

    const handleDragEnd = (event: DragEndEvent) => {
        setActiveDragOrder(null);
        const { active, over } = event;
        if (!over) return;

        const newStatus = String(over.id);
        const order = orders.find((o) => o.id === active.id);
        if (!order || order.status === newStatus) return;
        if (!ESTADOS.some((e) => e.key === newStatus)) return;

        // Forward-only: backend rechaza con 422 si target rank < current rank,
        // pero validamos en cliente para evitar el round-trip y dar feedback
        // inmediato. Coherente con `config('orders.kanban_rank')`.
        if (rankOf(newStatus) < rankOf(order.status)) {
            setAssignError('Las órdenes solo avanzan en el tablero; no se puede regresar a un estado anterior.');
            window.setTimeout(() => setAssignError(null), 4000);
            return;
        }

        if (newStatus === 'in_transit' && inferOrderType(order) !== 'delivery') {
            setAssignError('Solo las órdenes de domicilio pueden pasar a "En tránsito".');
            window.setTimeout(() => setAssignError(null), 4000);
            return;
        }


        const orderId = order.id;
        setDroppedOrderId(orderId);
        window.setTimeout(() => {
            setDroppedOrderId((current) => (current === orderId ? null : current));
        }, 650);

        void updateStatus(orderId, newStatus)
            .then((warnings) => {
                if (warnings.length > 0) {
                    showToast('info', `Stock insuficiente al pasar a cocina: ${warnings.join(', ')} quedó en negativo.`, 8000);
                }
            })
            .catch((err) => {
                setAssignError(err instanceof Error ? err.message : 'Error al actualizar estado.');
            });
    };

    const activeEstado = ESTADOS.find((e) => e.key === mobileColumn) ?? ESTADOS[0];
    const swayRef = useDragSwayRef(!!activeDragOrder);

    return (
        <PageShell
            title="Tablero"
        >
            {loading && orders.length === 0 ? (
                <KanbanBoardSkeleton />
            ) : (
                <>
                    <div className="px-4 pt-4">
                        <PageHeader
                            eyebrow="Órdenes"
                            title="Tablero"
                            description="Arrastra cada orden para avanzar de estado. Solo se permite mover hacia adelante."
                            actions={<LiveIndicator timestamp={lastUpdated} isLive={!loading && !error} />}
                        />
                    </div>

                    <div className="mx-4 mt-3">
                        <PendingApprovalsBanner />
                    </div>

                    {/* Buscador */}
                    <div className="mx-4 mt-3 flex gap-2">
                        <Input
                            type="search"
                            placeholder="Buscar por #orden, teléfono, dirección o mesa…"
                            value={searchInput}
                            onChange={(e) => setSearchInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') setAppliedSearch(searchInput);
                            }}
                            className="h-9 max-w-sm"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAppliedSearch(searchInput)}
                            className="h-9 shrink-0"
                        >
                            <Search className="mr-1.5 h-3.5 w-3.5" />
                            Buscar
                        </Button>
                        {appliedSearch && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => { setSearchInput(''); setAppliedSearch(''); }}
                                className="h-9 shrink-0"
                            >
                                Limpiar
                            </Button>
                        )}
                    </div>

                    {error && (
                        <div className="mx-4 mt-4">
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription className="flex items-center justify-between gap-2">
                                    <span>{error}</span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 shrink-0 px-2"
                                        onClick={() => void refresh()}
                                        aria-label="Reintentar"
                                    >
                                        <RefreshCw className="h-3 w-3" />
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}

                    {/* Mobile layout — patrón KDS: chips de estado siempre visibles
                        (un tap para cambiar, contador por columna) y botón de
                        avance directo en cada card sin abrir el detalle. */}
                    {isMobile ? (
                        <div className="space-y-3 p-4">
                            <div className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1">
                                {ESTADOS.map((e) => (
                                    <button
                                        key={e.key}
                                        type="button"
                                        onClick={() => setMobileColumn(e.key)}
                                        className={`flex min-h-[44px] shrink-0 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium transition ${
                                            mobileColumn === e.key
                                                ? `${e.color} border-transparent`
                                                : 'border-border bg-card text-muted-foreground'
                                        }`}
                                    >
                                        {e.label}
                                        <Badge variant="outline" className="bg-background/80 border-transparent text-xs tabular-nums">
                                            {grouped[e.key]?.length ?? 0}
                                        </Badge>
                                    </button>
                                ))}
                            </div>

                            <div>
                                <div
                                    className={`flex items-center justify-between rounded-t-lg px-3 py-2 text-sm font-semibold ${activeEstado.color}`}
                                >
                                    <span>{activeEstado.label}</span>
                                    <Badge variant="outline" className="bg-background/80 border-transparent text-xs tabular-nums">
                                        {grouped[mobileColumn]?.length ?? 0}
                                    </Badge>
                                </div>

                                <div className="bg-muted/30 min-h-[120px] space-y-2 rounded-b-lg p-2">
                                    {loading ? (
                                        <ColumnSkeleton />
                                    ) : grouped[mobileColumn]?.length ? (
                                        grouped[mobileColumn].map((order) => {
                                            const advance = nextAdvance(order);
                                            return (
                                                <OrderCard
                                                    key={order.id}
                                                    order={order}
                                                    formatCurrency={formatCurrency}
                                                    onSelect={() => setSelectedOrder(order)}
                                                    onAssign={canAssignCourier ? (e) => handleOpenAssign(e, order.id) : undefined}
                                                    advanceLabel={advance?.label}
                                                    onAdvance={
                                                        advance
                                                            ? (e) => {
                                                                  e.stopPropagation();
                                                                  handleAdvanceStatusFromDetail(order.id, advance.key);
                                                              }
                                                            : undefined
                                                    }
                                                />
                                            );
                                        })
                                    ) : (
                                        <p className="text-muted-foreground py-8 text-center text-sm">Sin órdenes en esta columna.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    ) : (
                        /* Desktop layout */
                        <DndContext sensors={sensors} onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
                            <div className="flex h-[calc(100dvh-9rem)] gap-3 overflow-x-auto p-3 sm:gap-4 sm:p-4 lg:h-[80vh]">
                                {ESTADOS.map((estado) => {
                                    const columnOrders = grouped[estado.key] ?? [];
                                    const isValidTarget = activeDragOrder ? estado.rank >= rankOf(activeDragOrder.status) : null;
                                    return (
                                        <DroppableColumn key={estado.key} estado={estado} count={columnOrders.length} isValidTarget={isValidTarget}>
                                            {loading ? (
                                                <ColumnSkeleton />
                                            ) : columnOrders.length > 0 ? (
                                                columnOrders.map((order) => (
                                                    <DraggableOrderCard
                                                        key={order.id}
                                                        order={order}
                                                        formatCurrency={formatCurrency}
                                                        onSelect={() => setSelectedOrder(order)}
                                                        onAssign={canAssignCourier ? (e) => handleOpenAssign(e, order.id) : undefined}
                                                        justDropped={droppedOrderId === order.id}
                                                    />
                                                ))
                                            ) : (
                                                <p className="text-muted-foreground py-8 text-center text-xs">Sin órdenes.</p>
                                            )}
                                        </DroppableColumn>
                                    );
                                })}
                            </div>

                            <DragOverlay dropAnimation={null}>
                                {activeDragOrder ? (
                                    <div className="relative cursor-grabbing">
                                        <div className="bg-foreground text-background animate-fade-in pointer-events-none absolute -top-9 left-1/2 z-10 -translate-x-1/2 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap shadow-lg">
                                            Avisaremos al cliente al soltar
                                            <span className="bg-foreground absolute -bottom-1 left-1/2 h-2 w-2 -translate-x-1/2 rotate-45" />
                                        </div>
                                        <div
                                            ref={swayRef}
                                            style={{
                                                transformOrigin: 'top center',
                                                willChange: 'transform',
                                            }}
                                        >
                                            <OrderCard order={activeDragOrder} formatCurrency={formatCurrency} />
                                        </div>
                                    </div>
                                ) : null}
                            </DragOverlay>
                        </DndContext>
                    )}
                </>
            )}

            <OrderDetailModal
                order={selectedOrder}
                isOpen={!!selectedOrder}
                onClose={() => setSelectedOrder(null)}
                onAssignCourier={canAssignCourier ? handleAssignFromDetail : undefined}
                onReassignCourier={canReassignCourier ? handleReassignFromDetail : undefined}
                onAdvanceStatus={handleAdvanceStatusFromDetail}
                advanceOptions={
                    selectedOrder
                        ? ESTADOS.filter(
                              (e) =>
                                  e.rank > rankOf(selectedOrder.status) &&
                                  (e.key !== 'in_transit' || inferOrderType(selectedOrder) === 'delivery'),
                          ).map((e) => ({ key: e.key, label: e.label }))
                        : []
                }
            />

            <AssignCourierModal
                orderId={assignOrderId ?? ''}
                isOpen={!!assignOrderId}
                onClose={() => {
                    setAssignOrderId(null);
                    setAssignError(null);
                }}
                onAssign={handleAssign}
                couriers={couriers}
                loading={couriersLoading}
            />

            {reassignContext && (
                <ReassignModal
                    orderId={reassignContext.orderId}
                    currentUserId={reassignContext.currentUserId}
                    isOpen={true}
                    onClose={() => setReassignContext(null)}
                    onConfirm={handleReassignConfirm}
                    couriers={couriers as unknown as import('@/types').AvailableDeliverer[]}
                    loading={couriersLoading}
                />
            )}

            {assignError && (
                <div className="fixed bottom-4 left-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 shadow-lg">
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{assignError}</AlertDescription>
                    </Alert>
                </div>
            )}
        </PageShell>
    );
}
