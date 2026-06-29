import CashRegisterPanel from '@/components/cash-register/cash-register-panel';
import { MenuQrPoster } from '@/components/company/menu-qr-poster';
import LiveIndicator from '@/components/dashboard/live-indicator';
import { NewClientDialog } from '@/components/clients/new-client-dialog';
import { RecipientNeedsDataDialog } from '@/components/dian/recipient-needs-data-dialog';
import { AddItemsSheet } from '@/components/order-tables/add-items-sheet';
import { TableDetailSheet } from '@/components/order-tables/table-detail-sheet';
import { TablePaymentSheet } from '@/components/order-tables/table-payment-sheet';
import { TablesGrid } from '@/components/order-tables/tables-grid';
import { NewOrderSheet } from '@/components/orders/new-order-sheet';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TablesGridSkeleton } from '@/components/ui/tables-grid-skeleton';
import { useActiveBranch } from '@/hooks/use-active-branch';
import { useAddItems } from '@/hooks/use-add-items';
import { useCashRegister } from '@/hooks/use-cash-register';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { usePermissions } from '@/hooks/use-permissions';
import { useTableGrid, type ActiveSession } from '@/hooks/use-table-grid';
import { useTablePayment } from '@/hooks/use-table-payment';
import { useTables, type TableOrder } from '@/hooks/use-tables';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { AlertCircle, Pencil, Plus, QrCode, RefreshCw, Settings2, Store, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

const DIAN_DOC_TYPE_CATALOG: Record<string, string> = {
    CC: 'Cédula de ciudadanía',
    CE: 'Cédula de extranjería',
    NIT: 'NIT',
    NIT_EXT: 'NIT extranjero',
    TI: 'Tarjeta de identidad',
    PA: 'Pasaporte',
    RC: 'Registro civil',
};
const DIAN_FISCAL_RESPONSIBILITIES_CATALOG: Record<string, string> = {
    'O-13': 'Gran contribuyente',
    'O-15': 'Autorretenedor',
    'O-23': 'Agente de retención IVA',
    'O-47': 'Régimen simple de tributación',
    'R-99-PN': 'No responsable',
};

const TABLE_GRID_DEFAULT = 12;

interface TableRow {
    id: string;
    number: string;
    capacity: number;
    qr_token: string;
    status: 'available' | 'occupied' | 'reserved' | 'blocked';
    archived_at: string | null;
}

export default function TablesPage() {
    const token = useToken();
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();
    const { tableOrders, loading, error, lastUpdated, refresh, appendItems, closeWithPayment } = useTables(token);
    const { session: cashSession, selectedRegister } = useCashRegister(token);
    const activeCompany = useSharedData().activeCompany;
    const { activeBranch } = useActiveBranch();
    const { has: hasPermission } = usePermissions();
    const canManage = hasPermission('company.update');

    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab = searchParams.get('tab') === 'config' && canManage ? 'config' : 'operativo';

    // ── Operational state ─────────────────────────────────────────────────

    const [companyQrUrl, setCompanyQrUrl] = useState<string | null>(null);
    const [recipientCompletionContactId, setRecipientCompletionContactId] = useState<string | null>(null);
    const [registerClientOpen, setRegisterClientOpen] = useState(false);

    useEffect(() => {
        if (!token) return;
        let cancelled = false;
        void (async () => {
            try {
                const res = await apiFetch('/api/v1/company');
                if (!res.ok) return;
                const json = await res.json();
                const path = json?.company?.qr_code_path as string | null | undefined;
                if (!cancelled) setCompanyQrUrl(path ? `/storage/${path}` : null);
            } catch {
                // silent
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [token]);

    const { definedTables, tablesLoaded, tablesEndpointFailed } = useTableGrid({ token, refreshOrders: refresh });

    const [selectedOrderId, setSelectedOrderId] = useState<string | null>(null);
    const selectedOrder = useMemo(
        () => (selectedOrderId === null ? null : (tableOrders.find((o) => o.id === selectedOrderId) ?? null)),
        [selectedOrderId, tableOrders],
    );

    const addItems = useAddItems({ token, appendItems });
    const payment = useTablePayment({
        selectedOrder,
        closeWithPayment,
        onPaid: () => setSelectedOrderId(null),
        cashSessionId: cashSession?.id ?? null,
    });

    const ordersByTable = useMemo(() => {
        const map = new Map<string, TableOrder>();
        for (const o of tableOrders) {
            if (!map.has(o.table_number)) map.set(o.table_number, o);
        }
        return map;
    }, [tableOrders]);

    const activeSessionByTable = useMemo(() => {
        const map = new Map<string, ActiveSession>();
        for (const t of definedTables) {
            if (t.active_session) map.set(t.number, t.active_session);
        }
        return map;
    }, [definedTables]);

    const tableNumbers = useMemo(() => {
        const baseNumbers =
            definedTables.length > 0
                ? definedTables.map((t) => t.number)
                : tablesEndpointFailed
                  ? Array.from({ length: TABLE_GRID_DEFAULT }, (_, i) => String(i + 1))
                  : [];
        const result: string[] = [...baseNumbers];
        for (const o of tableOrders) {
            if (!result.includes(o.table_number)) result.push(o.table_number);
        }
        return result;
    }, [definedTables, tablesEndpointFailed, tableOrders]);

    const [newOrderTable, setNewOrderTable] = useState<string | null>(null);

    // ── Management (config tab) state ──────────────────────────────────────

    const [adminTables, setAdminTables] = useState<TableRow[]>([]);
    const [adminLoading, setAdminLoading] = useState(false);
    const [adminError, setAdminError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [editing, setEditing] = useState<{ id: string | null; number: string | null; capacity: number } | null>(null);
    const [confirmArchive, setConfirmArchive] = useState<TableRow | null>(null);
    const [posterFor, setPosterFor] = useState<TableRow | null>(null);
    const [menuQrOpen, setMenuQrOpen] = useState(false);
    const adminFetchedRef = useRef(false);

    const fetchAdminTables = useCallback(async () => {
        setAdminLoading(true);
        setAdminError(null);
        try {
            const resp = await apiFetch('/api/v1/tables?include_archived=true');
            if (!resp.ok) throw new Error('No pudimos cargar las mesas.');
            const json = (await resp.json()) as { data: TableRow[] };
            setAdminTables(json.data);
        } catch (err) {
            setAdminError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setAdminLoading(false);
        }
    }, []);

    // Lazy load: fetchea mesas de admin solo cuando entra al tab por primera vez.
    useEffect(() => {
        if (activeTab === 'config' && !adminFetchedRef.current) {
            adminFetchedRef.current = true;
            void fetchAdminTables();
        }
    }, [activeTab, fetchAdminTables]);

    const submitEdit = async () => {
        if (!editing) return;
        setBusy(true);
        setActionError(null);
        try {
            const isCreate = editing.id === null;
            const url = isCreate ? '/api/v1/tables' : `/api/v1/tables/${editing.id}`;
            const method = isCreate ? 'POST' : 'PATCH';
            const resp = await apiFetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ capacity: editing.capacity }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Acción rechazada.');
            }
            await fetchAdminTables();
            void refresh();
            setEditing(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    const archiveTable = async (row: TableRow) => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/tables/${row.id}`, { method: 'DELETE' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo desactivar.');
            }
            await fetchAdminTables();
            void refresh();
            setConfirmArchive(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    const restoreTable = async (row: TableRow) => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/tables/${row.id}/restore`, { method: 'POST' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo reactivar.');
            }
            await fetchAdminTables();
            void refresh();
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    // ── Render ─────────────────────────────────────────────────────────────

    const operativeActions = (
        <div className="flex flex-wrap items-center gap-2">
            {selectedRegister && (
                <Badge variant="secondary" className="gap-1">
                    <Store className="h-3 w-3" />
                    {selectedRegister.name}
                </Badge>
            )}
            <LiveIndicator timestamp={lastUpdated} isLive={!loading && !error} />
            <Button variant="outline" size="sm" onClick={() => void refresh()}>
                <RefreshCw className="mr-1 h-4 w-4" /> Refrescar
            </Button>
        </div>
    );

    const operativeGrid = (
        <>
            {error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            <TablesGrid
                tableNumbers={tableNumbers}
                ordersByTable={ordersByTable}
                activeSessionByTable={activeSessionByTable}
                orderStatuses={orderStatuses}
                formatCurrency={formatCurrency}
                onOpenOrder={(id) => setSelectedOrderId(id)}
                onOpenCashier={(n) => setNewOrderTable(n)}
            />
        </>
    );

    return (
        <PageShell title="Mesas">
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {loading || !tablesLoaded ? (
                    <TablesGridSkeleton />
                ) : canManage ? (
                    /* Admin: tabs operativo + configuración */
                    <Tabs
                        defaultValue="operativo"
                        value={activeTab}
                        onValueChange={(v) => setSearchParams(v === 'config' ? { tab: 'config' } : {}, { replace: true })}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <TabsList>
                                <TabsTrigger value="operativo">Operativo</TabsTrigger>
                                <TabsTrigger value="config">
                                    <Settings2 className="mr-1.5 h-3.5 w-3.5" />
                                    Configuración
                                </TabsTrigger>
                            </TabsList>
                            {activeTab === 'operativo' && operativeActions}
                            {activeTab === 'config' && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setMenuQrOpen(true)}
                                        disabled={!activeBranch?.menu_qr_token}
                                    >
                                        <QrCode className="mr-1.5 h-3.5 w-3.5" /> QR del menú
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            adminFetchedRef.current = true;
                                            void fetchAdminTables();
                                        }}
                                        disabled={adminLoading || busy}
                                    >
                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => setEditing({ id: null, number: '', capacity: 4 })}
                                        disabled={busy}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" /> Nueva mesa
                                    </Button>
                                </div>
                            )}
                        </div>

                        <TabsContent value="operativo">
                            <CashRegisterPanel><></></CashRegisterPanel>
                            {operativeGrid}
                        </TabsContent>

                        <TabsContent value="config">
                            {adminLoading ? (
                                <ListCardSkeleton rows={6} actions={3} variant="card" gridClassName="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" />
                            ) : (
                                <div className="space-y-4">
                                    {adminError && (
                                        <Alert variant="destructive">
                                            <AlertCircle className="h-4 w-4" />
                                            <AlertDescription>{adminError}</AlertDescription>
                                        </Alert>
                                    )}
                                    {actionError && (
                                        <Alert variant="destructive">
                                            <AlertCircle className="h-4 w-4" />
                                            <AlertDescription>{actionError}</AlertDescription>
                                        </Alert>
                                    )}
                                    {adminTables.length === 0 ? (
                                        <EmptyState
                                            icon={QrCode}
                                            title="Sin mesas"
                                            description="Crea la primera mesa para imprimir su QR e iniciar el flujo de pedido por mesa."
                                        />
                                    ) : (
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {adminTables.map((row) => (
                                                <article key={row.id} className="border-border bg-card text-card-foreground space-y-2 rounded-2xl border p-4">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <h3 className="text-foreground text-lg font-semibold">Mesa {row.number}</h3>
                                                        <div className="flex items-center gap-1.5">
                                                            {row.archived_at ? (
                                                                <Badge
                                                                    variant="secondary"
                                                                    className="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                                                                >
                                                                    Desactivada
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant="secondary">
                                                                    {row.status === 'occupied' ? 'Ocupada' : row.status === 'available' ? 'Libre' : row.status}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <p className="text-muted-foreground text-xs">Capacidad: {row.capacity} comensales</p>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {!row.archived_at && (
                                                            <>
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    onClick={() => setPosterFor(row)}
                                                                    disabled={busy}
                                                                    className="h-9 px-3 text-sm"
                                                                >
                                                                    <QrCode className="mr-1 h-4 w-4" /> QR
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    onClick={() => setEditing({ id: row.id, number: row.number, capacity: row.capacity })}
                                                                    disabled={busy}
                                                                    className="h-9 px-3 text-sm"
                                                                >
                                                                    <Pencil className="mr-1 h-4 w-4" /> Editar
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    onClick={() => setConfirmArchive(row)}
                                                                    disabled={busy}
                                                                    className="text-destructive hover:text-destructive h-9 px-3 text-sm"
                                                                >
                                                                    <Trash2 className="mr-1 h-4 w-4" /> Desactivar
                                                                </Button>
                                                            </>
                                                        )}
                                                        {row.archived_at && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() => void restoreTable(row)}
                                                                disabled={busy}
                                                                className="h-9 px-3 text-sm"
                                                            >
                                                                <RefreshCw className="mr-1 h-4 w-4" /> Reactivar
                                                            </Button>
                                                        )}
                                                    </div>
                                                </article>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </TabsContent>
                    </Tabs>
                ) : (
                    /* Sin permiso de gestión — solo vista operativa */
                    <>
                        <CashRegisterPanel><></></CashRegisterPanel>
                        <PageHeader
                            eyebrow="Órdenes"
                            title="Mesas"
                            description="Visualiza el estado de cada mesa, agrega productos a la cuenta abierta y ciérrala al cobrar."
                            actions={operativeActions}
                        />
                        {operativeGrid}
                    </>
                )}
            </div>

            {/* ── Operational sheets ──────────────────────────────────────── */}
            <TableDetailSheet
                order={selectedOrder}
                formatCurrency={formatCurrency}
                onClose={() => setSelectedOrderId(null)}
                onAddItems={addItems.openAddItems}
                onCharge={payment.openPayment}
            />
            <AddItemsSheet
                isOpen={addItems.addState.open}
                order={addItems.addState.order}
                cart={addItems.addState.cart}
                activeMenu={addItems.activeMenu}
                menuLoading={addItems.menuLoading}
                submitting={addItems.submitting}
                submitError={addItems.submitError}
                addBreakdown={addItems.addBreakdown}
                addCartTotal={addItems.addCartTotal}
                formatCurrency={formatCurrency}
                onClose={addItems.closeAddItems}
                onIncrement={addItems.incrementCart}
                onDecrement={addItems.decrementCart}
                onSubmit={() => void addItems.submitAppendItems()}
            />
            <TablePaymentSheet
                paymentState={payment.paymentState}
                selectedOrder={selectedOrder}
                companyQrUrl={companyQrUrl}
                tipParsed={payment.tipParsed}
                expectedTotal={payment.expectedTotal}
                cashChange={payment.cashChange}
                formatCurrency={formatCurrency}
                setPaymentState={payment.setPaymentState}
                onClose={payment.closePaymentModal}
                onSubmit={() => void payment.submitPayment()}
                onLookupDianClient={() => void payment.dianLookupClient()}
                onRegisterClient={() => setRegisterClientOpen(true)}
                onRequestRecipientDataCompletion={(contactId) => setRecipientCompletionContactId(contactId)}
            />
            <NewClientDialog
                open={registerClientOpen}
                onOpenChange={setRegisterClientOpen}
                initialPhone={payment.paymentState.dianClientPhone}
                onCreated={(client) => {
                    setRegisterClientOpen(false);
                    if (client.phone) void payment.dianLookupClient(client.phone);
                }}
            />
            {recipientCompletionContactId !== null &&
                (() => {
                    const match = payment.paymentState.dianLookup?.data.find((m) => m.id === recipientCompletionContactId);
                    if (!match) return null;
                    return (
                        <RecipientNeedsDataDialog
                            open={recipientCompletionContactId !== null}
                            onOpenChange={(open) => !open && setRecipientCompletionContactId(null)}
                            contactId={recipientCompletionContactId}
                            initial={{
                                name: match.name,
                                doc_type: match.doc_type,
                                doc_number: match.doc_number,
                                dv: match.dv,
                                legal_name: match.legal_name,
                                email: match.email,
                                address: match.address,
                            }}
                            docTypeCatalog={DIAN_DOC_TYPE_CATALOG}
                            fiscalResponsibilitiesCatalog={DIAN_FISCAL_RESPONSIBILITIES_CATALOG}
                            onSaved={() => {
                                setRecipientCompletionContactId(null);
                                void payment.dianLookupClient();
                            }}
                        />
                    );
                })()}
            <NewOrderSheet
                isOpen={newOrderTable !== null}
                onClose={() => setNewOrderTable(null)}
                initialTableNumber={newOrderTable ?? ''}
                onSuccess={() => void refresh()}
            />

            {/* ── Config dialogs ───────────────────────────────────────────── */}
            <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{editing?.id === null ? 'Nueva mesa' : `Editar mesa ${editing?.number ?? ''}`}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        {editing?.id === null ? (
                            <p className="text-muted-foreground bg-muted/40 border-border rounded-md border px-3 py-2 text-xs">
                                El número se asigna automáticamente como el siguiente disponible en esta sede. Si desactivas una mesa, la secuencia se compacta sola.
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">El número de mesa no se edita manualmente — se mantiene el de la secuencia.</p>
                        )}
                        <div>
                            <Label htmlFor="capacity">Capacidad</Label>
                            <Input
                                id="capacity"
                                type="number"
                                min={1}
                                max={30}
                                value={editing?.capacity ?? 4}
                                onChange={(e) =>
                                    setEditing((prev) => (prev ? { ...prev, capacity: Number.parseInt(e.target.value, 10) || 1 } : prev))
                                }
                            />
                        </div>
                        <Button type="button" className="w-full" onClick={() => void submitEdit()} disabled={busy}>
                            {editing?.id === null ? 'Crear mesa' : 'Guardar cambios'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={!!posterFor} onOpenChange={(o) => !o && setPosterFor(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>QR de Mesa {posterFor?.number}</DialogTitle>
                    </DialogHeader>
                    {posterFor && activeCompany?.nit && (
                        <MenuQrPoster
                            nit={activeCompany.nit}
                            commercialName={activeCompany.name ?? 'Mesa'}
                            logoUrl={activeCompany.logo_url ?? null}
                            primaryColor={activeCompany.brand_color ?? '#0F172A'}
                            mode="menu"
                            tableNumber={posterFor.number}
                            qrToken={posterFor.qr_token}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={menuQrOpen} onOpenChange={setMenuQrOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>QR del menú{activeBranch?.name ? ` — ${activeBranch.name}` : ''}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground -mt-2 mb-2 text-xs">
                        QR del menú de esta sede. Sirve para imprimir en barras, entrada o cualquier punto sin mesa específica.
                    </p>
                    {activeCompany?.nit && (
                        <MenuQrPoster
                            nit={activeCompany.nit}
                            commercialName={activeCompany.name ?? 'Empresa'}
                            logoUrl={activeCompany.logo_url ?? null}
                            primaryColor={activeCompany.brand_color ?? '#0F172A'}
                            mode="menu"
                            tableNumber={null}
                            menuQrToken={activeBranch?.menu_qr_token}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!confirmArchive}
                title={`¿Desactivar Mesa ${confirmArchive?.number ?? ''}?`}
                message="La mesa queda fuera del flujo operativo y las demás se renumeran. Los registros históricos se conservan y puedes reactivarla cuando quieras."
                confirmLabel="Desactivar"
                onConfirm={() => confirmArchive && void archiveTable(confirmArchive)}
                onCancel={() => setConfirmArchive(null)}
                loading={busy}
            />
        </PageShell>
    );
}
