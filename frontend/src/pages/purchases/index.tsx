import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { PurchasesSkeleton } from '@/components/ui/purchases-skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/components/ui/toast';
import { useInventory } from '@/hooks/use-inventory';
import { usePermissions } from '@/hooks/use-permissions';
import { usePurchases } from '@/hooks/use-purchases';
import { useSuppliers } from '@/hooks/use-suppliers';
import { useToken } from '@/hooks/use-token';
import type { PurchaseOrderCreatePayload, PurchaseOrderDetail, PurchaseOrderSummary, PurchaseStatus } from '@/types/purchases';
import { STATUS_LABELS } from '@/types/purchases';

import { RefreshButton } from '@/components/ui/refresh-button';
import { AlertCircle, AlertTriangle, Plus, ShoppingBag } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PurchaseOrderDetailDrawer } from './components/purchase-order-detail-drawer';
import { PurchaseOrderEditor } from './components/purchase-order-editor';
import { SuppliersPanel } from './components/suppliers-panel';
import { formatCurrency } from '@/lib/formatters';


const STATUSES: PurchaseStatus[] = ['draft', 'pending', 'received', 'paid', 'cancelled', 'voided'];

const STATUS_VARIANT: Record<PurchaseStatus, NonNullable<BadgeProps['variant']>> = {
    draft: 'secondary',
    pending: 'warning',
    received: 'default',
    paid: 'safe',
    cancelled: 'outline',
    voided: 'critical',
};

function fmt(v: string | number): string {
    return formatCurrency(Number(v));
}

/**
 * Página unificada de Compras: pestaña "Órdenes" (antigua /purchases) +
 * pestaña "Proveedores" (antigua /suppliers). Un solo ítem en el sidebar;
 * `/suppliers` redirige aquí con `?tab=proveedores`. Los catálogos se cargan
 * una sola vez y se comparten entre pestañas y el editor de órdenes.
 */
export default function PurchasesIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const purchases = usePurchases(token);
    const suppliers = useSuppliers(token);
    const inventory = useInventory(token);
    const permissions = usePermissions();

    const canOrders = permissions.has('purchases.read');
    const canSuppliers = permissions.has('suppliers.read');
    const canCreateSupplier = permissions.has('suppliers.create');

    // Pestaña activa en la URL (deep-link + redirect desde /suppliers).
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedTab = searchParams.get('tab');
    const tab = requestedTab === 'proveedores' && canSuppliers ? 'proveedores' : canOrders ? 'ordenes' : 'proveedores';
    function setTab(next: string) {
        setSearchParams(next === 'ordenes' ? {} : { tab: next }, { replace: true });
    }

    // Refrescar recarga TODO lo que alimenta la pantalla: órdenes + catálogos
    // (insumos y proveedores), no solo el listado de órdenes.
    const [refreshing, setRefreshing] = useState(false);
    async function refreshAll() {
        setRefreshing(true);
        try {
            await Promise.all([purchases.fetchOrders(), inventory.fetchIngredients(), suppliers.fetchSuppliers()]);
        } finally {
            setRefreshing(false);
        }
    }

    const [showEditor, setShowEditor] = useState(false);
    const [editing, setEditing] = useState<PurchaseOrderDetail | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const [drawerPO, setDrawerPO] = useState<PurchaseOrderDetail | null>(null);
    const [drawerOpen, setDrawerOpen] = useState(false);

    const kpis = useMemo(() => {
        const orders = purchases.orders;
        let total = 0;
        let drafts = 0;
        let pendingPayment = 0;
        let pendingPaymentAmount = 0;
        let pendingRefunds = 0;
        for (const o of orders) {
            total += 1;
            if (o.status === 'draft') drafts += 1;
            if (o.status === 'pending' || o.status === 'received') {
                pendingPayment += 1;
                pendingPaymentAmount += Number(o.total ?? 0);
            }
            if (o.pending_supplier_refund) pendingRefunds += 1;
        }
        return { total, drafts, pendingPayment, pendingPaymentAmount, pendingRefunds };
    }, [purchases.orders]);

    function handleErr(err: unknown, fallback: string) {
        const apiErr = err as { errors?: Record<string, string[]>; message?: string };
        if (apiErr?.errors) setErrors(apiErr.errors);
        else showToast('error', apiErr?.message ?? fallback);
    }

    async function openDetail(row: PurchaseOrderSummary) {
        try {
            const detail = await purchases.fetchOrder(row.id);
            setDrawerPO(detail);
            setDrawerOpen(true);
        } catch (err) {
            handleErr(err, 'No se pudo cargar la orden.');
        }
    }

    async function submitOrder(payload: PurchaseOrderCreatePayload, isNew: boolean) {
        setSubmitting(true);
        setErrors({});
        try {
            const result = isNew || !editing ? await purchases.createOrder(payload) : await purchases.updateOrder(editing.id, payload);
            showToast('success', isNew ? `Orden ${result.code} creada.` : 'Borrador actualizado.');
            setShowEditor(false);
            setEditing(null);
            await purchases.fetchOrders();
            setDrawerPO(result);
            setDrawerOpen(true);
        } catch (err) {
            handleErr(err, 'No se pudo guardar la orden.');
        } finally {
            setSubmitting(false);
        }
    }

    function onPOChanged(po: PurchaseOrderDetail) {
        setDrawerPO(po);
        purchases.fetchOrders();
    }

    // Bloqueo SOLO cuando falta el catálogo Y el usuario no puede crearlo
    // desde el propio editor (quick-create de proveedor e insumo inline).
    // Antes bloqueaba siempre y obligaba a peregrinar a otras páginas.
    const missingSupplier = suppliers.suppliers.filter((s) => !s.archived_at).length === 0 && !canCreateSupplier;
    const missingIngredient = inventory.ingredients.filter((i) => !i.archived_at).length === 0 && !permissions.has('inventory.create');
    const blocked = missingSupplier || missingIngredient;

    const hasActiveFilters =
        purchases.filters.q.length > 0 ||
        purchases.filters.status !== '' ||
        purchases.filters.supplier_id !== null ||
        purchases.filters.pending_refund;
    const initialLoading = purchases.loading && purchases.orders.length === 0 && !purchases.error;

    if (initialLoading && tab === 'ordenes') {
        return (
            <PageShell title="Compras">
                <div className="p-4 lg:p-6">
                    <PurchasesSkeleton />
                </div>
            </PageShell>
        );
    }

    return (
        <PageShell title="Compras">
            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <PageHeader
                    eyebrow="OPERACIÓN"
                    title="Compras y proveedores"
                    description="Órdenes de compra (borrador → confirmada → recibida → pagada) y catálogo de proveedores en un solo lugar."
                    actions={
                        <>
                            <RefreshButton size="default" onRefresh={refreshAll} refreshing={refreshing || purchases.loading} disabled={refreshing || purchases.loading} />
                            {tab === 'ordenes' && canOrders && (
                                <Button
                                    onClick={() => {
                                        setEditing(null);
                                        setErrors({});
                                        setShowEditor(true);
                                    }}
                                    disabled={blocked}
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Nueva orden
                                </Button>
                            )}
                        </>
                    }
                />

                <Tabs defaultValue="ordenes" value={tab} onValueChange={setTab}>
                    {canOrders && canSuppliers && (
                        <TabsList>
                            <TabsTrigger value="ordenes">Órdenes</TabsTrigger>
                            <TabsTrigger value="proveedores">Proveedores</TabsTrigger>
                        </TabsList>
                    )}

                    {canOrders && (
                        <TabsContent value="ordenes" className="mt-4 flex flex-col gap-6">
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <StatTile size="lg" value={kpis.total} label="Órdenes totales" />
                                <StatTile size="lg" value={kpis.drafts} label="Borradores" />
                                <StatTile
                                    size="lg"
                                    value={kpis.pendingPayment}
                                    label={`Pendientes de pago · ${fmt(kpis.pendingPaymentAmount)}`}
                                    tone={kpis.pendingPayment > 0 ? 'warning' : 'default'}
                                />
                                <StatTile
                                    size="lg"
                                    value={kpis.pendingRefunds}
                                    label="Reintegros pendientes"
                                    tone={kpis.pendingRefunds > 0 ? 'critical' : 'default'}
                                />
                            </div>

                            {blocked && (
                                <Alert variant="warning">
                                    <AlertTriangle className="h-4 w-4" />
                                    <AlertDescription>
                                        Para crear órdenes necesitas {missingSupplier ? 'al menos un proveedor activo' : ''}
                                        {missingSupplier && missingIngredient ? ' y ' : ''}
                                        {missingIngredient ? 'al menos un insumo activo' : ''} — pídele a un administrador que los
                                        cree.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <FilterBar
                                variant="card"
                                searchValue={purchases.filters.q}
                                onSearchChange={(v) => purchases.setFilters({ q: v })}
                                searchPlaceholder="Buscar por código (PO-)…"
                            >
                                <div className="space-y-1">
                                    <label className="text-muted-foreground text-xs">Estado</label>
                                    <select
                                        value={purchases.filters.status}
                                        onChange={(e) => purchases.setFilters({ status: e.target.value as PurchaseStatus | '' })}
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm shadow-sm"
                                    >
                                        <option value="">Todos</option>
                                        {STATUSES.map((s) => (
                                            <option key={s} value={s}>
                                                {STATUS_LABELS[s]}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="space-y-1">
                                    <label className="text-muted-foreground text-xs">Proveedor</label>
                                    <select
                                        value={purchases.filters.supplier_id ?? ''}
                                        onChange={(e) => purchases.setFilters({ supplier_id: e.target.value ? e.target.value : null })}
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm shadow-sm"
                                    >
                                        <option value="">Todos</option>
                                        {suppliers.suppliers.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={purchases.filters.pending_refund}
                                        onChange={(e) => purchases.setFilters({ pending_refund: e.target.checked })}
                                    />
                                    Solo con reintegro pendiente
                                </label>
                            </FilterBar>

                            {purchases.error && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{purchases.error}</AlertDescription>
                                </Alert>
                            )}

                            {/* Empty state compartido mobile + desktop */}
                            {purchases.orders.length === 0 && (
                                <div className="bg-card rounded-lg border shadow-sm">
                                    <EmptyState
                                        icon={ShoppingBag}
                                        title={hasActiveFilters ? 'Sin órdenes con los filtros actuales' : 'Aún no hay órdenes de compra'}
                                        description={
                                            hasActiveFilters
                                                ? 'Ajusta los filtros para ver más resultados.'
                                                : 'Crea una orden para empezar a registrar compras a proveedores; al recibirla se actualiza el inventario. Si te falta el proveedor o el insumo, puedes crearlos sin salir del formulario.'
                                        }
                                        action={
                                            !hasActiveFilters &&
                                            !blocked && (
                                                <Button
                                                    onClick={() => {
                                                        setEditing(null);
                                                        setErrors({});
                                                        setShowEditor(true);
                                                    }}
                                                >
                                                    <Plus className="mr-1 h-4 w-4" /> Nueva orden
                                                </Button>
                                            )
                                        }
                                    />
                                </div>
                            )}

                            {/* Mobile: card-stack */}
                            {purchases.orders.length > 0 && (
                                <DataCardList
                                    items={purchases.orders}
                                    getKey={(p) => p.id}
                                    className="sm:hidden"
                                    renderCard={(p) => (
                                        <DataCard
                                            title={<span className="font-mono">{p.code}</span>}
                                            subtitle={p.supplier?.name ?? 'Sin proveedor'}
                                            onClick={() => openDetail(p)}
                                            fields={[
                                                { label: 'Total', value: <span className="font-semibold tabular-nums">{fmt(p.total)}</span> },
                                                { label: 'Esperada', value: p.expected_date ?? '—' },
                                                {
                                                    label: 'Estado',
                                                    value: (
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            <Badge variant={STATUS_VARIANT[p.status]}>{STATUS_LABELS[p.status]}</Badge>
                                                            {p.pending_supplier_refund && (
                                                                <Badge variant="critical">
                                                                    <AlertTriangle className="mr-1 h-3 w-3" /> Reintegro
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    ),
                                                },
                                                { label: 'Pago', value: p.payment_method ?? '—' },
                                            ]}
                                        />
                                    )}
                                />
                            )}

                            {/* Desktop: tabla densa */}
                            {purchases.orders.length > 0 && (
                                <div className="hidden sm:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Código</TableHead>
                                                <TableHead>Estado</TableHead>
                                                <TableHead>Proveedor</TableHead>
                                                <TableHead>Esperada</TableHead>
                                                <TableHead className="text-right">Total</TableHead>
                                                <TableHead>Pago</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {purchases.orders.map((p) => (
                                                <TableRow
                                                    key={p.id}
                                                    className="hover:bg-muted/40 cursor-pointer transition-colors"
                                                    onClick={() => openDetail(p)}
                                                >
                                                    <TableCell className="font-mono">{p.code}</TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            <Badge variant={STATUS_VARIANT[p.status]}>{STATUS_LABELS[p.status]}</Badge>
                                                            {p.pending_supplier_refund && (
                                                                <Badge variant="critical">
                                                                    <AlertTriangle className="mr-1 h-3 w-3" /> Reintegro
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{p.supplier?.name ?? '—'}</TableCell>
                                                    <TableCell>{p.expected_date ?? '—'}</TableCell>
                                                    <TableCell className="text-right tabular-nums">{fmt(p.total)}</TableCell>
                                                    <TableCell>{p.payment_method ?? '—'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </TabsContent>
                    )}

                    {canSuppliers && (
                        <TabsContent value="proveedores" className="mt-4">
                            <SuppliersPanel sup={suppliers} canCreate={canCreateSupplier} />
                        </TabsContent>
                    )}
                </Tabs>
            </div>

            <PurchaseOrderEditor
                open={showEditor}
                onClose={() => {
                    setShowEditor(false);
                    setEditing(null);
                }}
                onSubmit={submitOrder}
                editing={editing}
                suppliers={suppliers.suppliers.filter((s) => !s.archived_at)}
                ingredients={inventory.ingredients.filter((i) => !i.archived_at)}
                submitting={submitting}
                errors={errors}
                canCreateIngredient={permissions.has('inventory.create')}
                onCreateIngredient={inventory.createIngredient}
                onIngredientCreated={inventory.fetchIngredients}
                canCreateSupplier={canCreateSupplier}
                onCreateSupplier={suppliers.createSupplier}
                onSupplierCreated={suppliers.fetchSuppliers}
            />

            <PurchaseOrderDetailDrawer
                po={drawerPO}
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                onEdit={(po) => {
                    setDrawerOpen(false);
                    setEditing(po);
                    setErrors({});
                    setShowEditor(true);
                }}
                onSubmit={purchases.submitOrder}
                onReceive={purchases.receiveOrder}
                onPay={(id, body) => purchases.payOrder(id, body)}
                onCancel={purchases.cancelOrder}
                onVoid={purchases.voidOrder}
                onSettleRefund={purchases.settleRefund}
                onUpload={purchases.uploadAttachment}
                onDelete={purchases.deleteAttachment}
                getAttachmentUrl={purchases.attachmentUrl}
                onRefetch={purchases.fetchOrder}
                onChanged={onPOChanged}
            />
        </PageShell>
    );
}
