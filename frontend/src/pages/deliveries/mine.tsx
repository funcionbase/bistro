import { AvailableOrderCard, type AvailableOrder } from '@/components/deliveries/available-order-card';
import { DeliveryActionSheet, type DeliveryActionId } from '@/components/deliveries/delivery-action-sheet';
import { MyDeliveryCard, type MyDeliveryAction } from '@/components/deliveries/my-delivery-card';
import { RejectReasonSheet } from '@/components/deliveries/reject-reason-sheet';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { EmptyState } from '@/components/ui/empty-state';
import { MyDeliveriesSkeleton } from '@/components/ui/my-deliveries-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePermissions } from '@/hooks/use-permissions';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import type { Delivery } from '@/types';

import { AlertCircle, Bike, Inbox, PackageCheck } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';


const POLL_AVAILABLE_MS = 30_000;

type Tab = 'assigned' | 'available' | 'today';

interface ConfirmFeedback {
    kind: 'success' | 'error';
    title: string;
    message: string;
}

export default function MyDeliveriesPage() {
    const token = useToken();
    const { has } = usePermissions();
    const canSelfAssign = has('deliveries.self_assign');

    const [tab, setTab] = useState<Tab>('assigned');
    const [myDeliveries, setMyDeliveries] = useState<Delivery[]>([]);
    const [available, setAvailable] = useState<AvailableOrder[]>([]);
    const [todayHistory, setTodayHistory] = useState<Delivery[]>([]);

    const [loadingMine, setLoadingMine] = useState(false);
    const [loadingAvailable, setLoadingAvailable] = useState(false);
    const [loadingToday, setLoadingToday] = useState(false);

    const [error, setError] = useState<string | null>(null);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [feedback, setFeedback] = useState<ConfirmFeedback | null>(null);

    const [actionSheetDelivery, setActionSheetDelivery] = useState<Delivery | null>(null);
    const [rejectSheetDelivery, setRejectSheetDelivery] = useState<Delivery | null>(null);
    const [rejectError, setRejectError] = useState<string | null>(null);
    const [rejectSubmitting, setRejectSubmitting] = useState(false);

    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const todayFrom = useMemo(() => new Date().toISOString().slice(0, 10), []);

    const fetchMine = useCallback(async () => {
        if (!token) return;
        setLoadingMine(true);
        try {
            const res = await apiFetch('/api/v1/deliveries/mine?status=pending');
            if (!res.ok) {
                if (mounted.current) {
                    setError('No fue posible cargar tus entregas.');
                }
                return;
            }
            const body = (await res.json()) as { data: Delivery[] };
            if (mounted.current) {
                setMyDeliveries(body.data ?? []);
                setError(null);
            }
        } catch {
            if (mounted.current) {
                setError('Error de conexión.');
            }
        } finally {
            if (mounted.current) {
                setLoadingMine(false);
            }
        }
    }, [token]);

    const fetchAvailable = useCallback(async () => {
        if (!token || !canSelfAssign) return;
        setLoadingAvailable(true);
        try {
            const res = await apiFetch('/api/v1/deliveries/available');
            if (!res.ok) {
                if (mounted.current && res.status !== 403) {
                    setError('No fue posible cargar las órdenes disponibles.');
                }
                return;
            }
            const body = (await res.json()) as { data: AvailableOrder[] };
            if (mounted.current) {
                setAvailable(body.data ?? []);
                setError(null);
            }
        } catch {
            // intermitencia — el próximo tick reintenta.
        } finally {
            if (mounted.current) {
                setLoadingAvailable(false);
            }
        }
    }, [token, canSelfAssign]);

    const fetchToday = useCallback(async () => {
        if (!token) return;
        setLoadingToday(true);
        try {
            const res = await apiFetch(`/api/v1/deliveries/mine?date_from=${todayFrom}`);
            if (!res.ok) return;
            const body = (await res.json()) as { data: Delivery[] };
            if (mounted.current) {
                setTodayHistory((body.data ?? []).filter((d) => d.status !== 'pending'));
            }
        } catch {
            // ignore
        } finally {
            if (mounted.current) {
                setLoadingToday(false);
            }
        }
    }, [token, todayFrom]);

    // Carga inicial de las pestañas.
    useEffect(() => {
        void fetchMine();
        void fetchToday();
    }, [fetchMine, fetchToday]);

    // Polling cada 30s en pestaña Disponibles. Pausa cuando el usuario
    // cambia de pestaña para no quemar requests.
    useEffect(() => {
        if (tab !== 'available') return;
        void fetchAvailable();
        const id = window.setInterval(() => void fetchAvailable(), POLL_AVAILABLE_MS);
        return () => window.clearInterval(id);
    }, [tab, fetchAvailable]);

    const refreshAll = useCallback(() => {
        void fetchMine();
        void fetchToday();
        if (tab === 'available') {
            void fetchAvailable();
        }
    }, [fetchMine, fetchToday, fetchAvailable, tab]);

    async function handleSelfAssign(orderId: string) {
        if (busyId !== null) return;
        setBusyId(orderId);
        try {
            const res = await apiFetch(`/api/v1/deliveries/orders/${orderId}/self-assign`, { method: 'POST' });
            if (res.ok) {
                setFeedback({
                    kind: 'success',
                    title: 'Entrega tomada',
                    message: 'La orden quedó asignada a ti. Revísala en "Asignadas".',
                });
                refreshAll();
                setTab('assigned');
                return;
            }
            const body = await safeJson(res);
            setFeedback({
                kind: 'error',
                title: 'No se pudo tomar la entrega',
                message: body?.message ?? 'Otra persona pudo haberla tomado primero.',
            });
        } catch {
            setFeedback({
                kind: 'error',
                title: 'Sin conexión',
                message: 'No se pudo tomar la entrega. Revisa tu conexión e intenta de nuevo.',
            });
        } finally {
            setBusyId(null);
        }
    }

    async function handleComplete(deliveryId: string) {
        if (busyId !== null) return;
        setBusyId(deliveryId);
        try {
            const res = await apiFetch(`/api/v1/deliveries/${deliveryId}/complete`, { method: 'PATCH' });
            if (res.ok) {
                setFeedback({
                    kind: 'success',
                    title: 'Entregada',
                    message: 'Buen trabajo. La orden quedó como completada.',
                });
                refreshAll();
                return;
            }
            const body = await safeJson(res);
            setFeedback({
                kind: 'error',
                title: 'No se pudo completar',
                message: body?.message ?? 'Intenta de nuevo en un momento.',
            });
        } catch {
            setFeedback({
                kind: 'error',
                title: 'Sin conexión',
                message: 'No se pudo completar la entrega. Revisa tu conexión e intenta de nuevo.',
            });
        } finally {
            setBusyId(null);
        }
    }

    async function handleRevert(deliveryId: string) {
        if (busyId !== null) return;
        setBusyId(deliveryId);
        setActionSheetDelivery(null);
        try {
            const res = await apiFetch(`/api/v1/deliveries/${deliveryId}/revert`, { method: 'PUT' });
            if (res.ok) {
                setFeedback({
                    kind: 'success',
                    title: 'Revertida',
                    message: 'La entrega volvió a estar en tránsito.',
                });
                refreshAll();
                return;
            }
            const body = await safeJson(res);
            const blockedByReceipt = body?.code === 'DELIVERY_HAS_RECEIPT';
            setFeedback({
                kind: 'error',
                title: blockedByReceipt ? 'Cobro ya registrado' : 'No se pudo revertir',
                message: body?.message ?? 'Intenta de nuevo o pídele ayuda a un admin.',
            });
        } catch {
            setFeedback({
                kind: 'error',
                title: 'Sin conexión',
                message: 'No se pudo revertir la entrega. Revisa tu conexión e intenta de nuevo.',
            });
        } finally {
            setBusyId(null);
        }
    }

    async function handleRejectSubmit(reason: string, delivery: Delivery) {
        setRejectSubmitting(true);
        setRejectError(null);
        try {
            const res = await apiFetch(`/api/v1/deliveries/${delivery.id}/reject`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason }),
            });
            if (res.ok) {
                setRejectSheetDelivery(null);
                setFeedback({
                    kind: 'success',
                    title: 'Rechazo registrado',
                    message: 'La orden quedó cancelada y se notificó al cliente.',
                });
                refreshAll();
                return;
            }
            const body = await safeJson(res);
            setRejectError(body?.message ?? 'No se pudo registrar el rechazo.');
        } catch {
            setRejectError('Sin conexión. No se pudo registrar el rechazo, intenta de nuevo.');
        } finally {
            setRejectSubmitting(false);
        }
    }

    function handleCardAction(action: MyDeliveryAction, delivery: Delivery) {
        if (action === 'complete') {
            void handleComplete(delivery.id);
            return;
        }
        if (action === 'open_actions') {
            setActionSheetDelivery(delivery);
        }
    }

    function handleSheetAction(action: DeliveryActionId, delivery: Delivery) {
        if (action === 'revert') {
            void handleRevert(delivery.id);
            return;
        }
        if (action === 'reject') {
            setActionSheetDelivery(null);
            setRejectError(null);
            setRejectSheetDelivery(delivery);
        }
    }

    return (
        <PageShell title="Mis entregas">
            <div className="flex flex-col gap-4 p-4 pb-24 sm:p-6">
                {loadingMine && myDeliveries.length === 0 && !error ? (
                    <MyDeliveriesSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="Domicilios"
                            title="Mis entregas"
                            description="Tomá órdenes disponibles, marcá entregas y resolvé rechazos desde aquí."
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>Algo no cargó</AlertTitle>
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {feedback && (
                            <Alert variant={feedback.kind === 'success' ? 'safe' : 'destructive'}>
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>{feedback.title}</AlertTitle>
                                <AlertDescription>{feedback.message}</AlertDescription>
                            </Alert>
                        )}

                        <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)} defaultValue="assigned">
                            <TabsList className="w-full sm:w-auto">
                                <TabsTrigger value="assigned">Asignadas ({myDeliveries.length})</TabsTrigger>
                                {canSelfAssign && <TabsTrigger value="available">Disponibles ({available.length})</TabsTrigger>}
                                <TabsTrigger value="today">Historial hoy</TabsTrigger>
                            </TabsList>

                            <TabsContent value="assigned" className="space-y-3">
                                {loadingMine && myDeliveries.length === 0 ? (
                                    <SkeletonList />
                                ) : myDeliveries.length === 0 ? (
                                    <EmptyState
                                        icon={Inbox}
                                        title="Sin entregas asignadas"
                                        description={
                                            canSelfAssign
                                                ? "Revisa la pestaña 'Disponibles' para tomar una."
                                                : 'Cuando un admin te asigne una entrega, aparecerá acá.'
                                        }
                                    />
                                ) : (
                                    myDeliveries.map((delivery) => (
                                        <MyDeliveryCard
                                            key={delivery.id}
                                            delivery={delivery}
                                            onAction={handleCardAction}
                                            busy={busyId === delivery.id}
                                        />
                                    ))
                                )}
                            </TabsContent>

                            {canSelfAssign && (
                                <TabsContent value="available" className="space-y-3">
                                    {loadingAvailable && available.length === 0 ? (
                                        <SkeletonList />
                                    ) : available.length === 0 ? (
                                        <EmptyState
                                            icon={Bike}
                                            title="No hay órdenes disponibles"
                                            description="Cuando una orden esté lista para salir aparecerá aquí. Refrescamos cada 30s."
                                        />
                                    ) : (
                                        available.map((order) => (
                                            <AvailableOrderCard key={order.id} order={order} onTake={handleSelfAssign} busy={busyId === order.id} />
                                        ))
                                    )}
                                </TabsContent>
                            )}

                            <TabsContent value="today" className="space-y-3">
                                {loadingToday && todayHistory.length === 0 ? (
                                    <SkeletonList />
                                ) : todayHistory.length === 0 ? (
                                    <EmptyState
                                        icon={PackageCheck}
                                        title="Aún no completaste entregas hoy"
                                        description="Las entregas marcadas como completadas o canceladas aparecerán aquí."
                                    />
                                ) : (
                                    todayHistory.map((delivery) => (
                                        <MyDeliveryCard
                                            key={delivery.id}
                                            delivery={delivery}
                                            onAction={handleCardAction}
                                            busy={busyId === delivery.id}
                                        />
                                    ))
                                )}
                            </TabsContent>
                        </Tabs>
                    </>
                )}
            </div>

            <DeliveryActionSheet delivery={actionSheetDelivery} onClose={() => setActionSheetDelivery(null)} onAction={handleSheetAction} />

            <RejectReasonSheet
                delivery={rejectSheetDelivery}
                onClose={() => setRejectSheetDelivery(null)}
                onSubmit={handleRejectSubmit}
                submitting={rejectSubmitting}
                error={rejectError}
            />
        </PageShell>
    );
}

function SkeletonList() {
    return (
        <div className="space-y-3">
            <Skeleton className="h-40 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
        </div>
    );
}

async function safeJson(res: Response): Promise<{ message?: string; code?: string } | null> {
    try {
        return (await res.clone().json()) as { message?: string; code?: string };
    } catch {
        return null;
    }
}
