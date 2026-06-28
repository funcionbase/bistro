import CashRegisterPanel from '@/components/cash-register/cash-register-panel';
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
import { PageHeader } from '@/components/ui/page-header';
import { TablesGridSkeleton } from '@/components/ui/tables-grid-skeleton';
import { useAddItems } from '@/hooks/use-add-items';
import { useCashRegister } from '@/hooks/use-cash-register';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { useTableGrid, type ActiveSession } from '@/hooks/use-table-grid';
import { useTablePayment } from '@/hooks/use-table-payment';
import { useTables, type TableOrder } from '@/hooks/use-tables';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';

import { AlertCircle, Cog, RefreshCw, Store } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

// Mesas solo muestra órdenes operativas pre-entrega; usamos las etiquetas canónicas.
// Las labels finales se resuelven con `statusLabel(orderStatuses, ...)` para evitar drift.

const TABLE_GRID_DEFAULT = 12;

/**
 * Catálogos DIAN espejo de `config/dian.php`. Hardcoded en el frontend
 * (sin endpoint catálogo dedicado todavía); si el backend cambia, actualizar
 * acá.
 */
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

export default function TablesPage() {
    const token = useToken();
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();
    const { tableOrders, loading, error, lastUpdated, refresh, appendItems, closeWithPayment } = useTables(token);
    const { session: cashSession, selectedRegister } = useCashRegister(token);

    const [companyQrUrl, setCompanyQrUrl] = useState<string | null>(null);
    // HU #235 — contactId pendiente de completar perfil DIAN (modal).
    const [recipientCompletionContactId, setRecipientCompletionContactId] = useState<string | null>(null);
    // Modal "Nuevo contacto" abierto desde el cobro cuando el lookup DIAN no
    // encuentra al cliente.
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

    // Lista de mesas configurada por el admin en /company/tables (fuente de
    // verdad). Hasta que la primera carga termina mostramos skeleton — sin
    // flash de placeholders. Si el endpoint falla, fallback degradado a 12
    // mesas numéricas para no bloquear la operación.
    const { definedTables, tablesLoaded, tablesEndpointFailed } = useTableGrid({ token, refreshOrders: refresh });

    // Guardamos el id en lugar del objeto para que el detalle siempre refleje
    // los datos más recientes de `tableOrders` (tras append/refresh el modal se actualiza solo).
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

    // Combina las mesas definidas en admin (fuente de verdad) con cualquier
    // table_number presente en órdenes activas — eso cubre órdenes creadas
    // antes de que la mesa fuera registrada en admin, o casos donde la mesa
    // se archivó pero quedó una orden viva. El fallback de 1..12 solo se
    // aplica si el endpoint /api/v1/tables falló — nunca durante la carga
    // inicial (eso causaba flash de placeholders).
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

    const openCashierForTable = (tableNumber: string) => {
        setNewOrderTable(tableNumber);
    };

    return (
        <PageShell title="Mesas">
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {loading || !tablesLoaded ? (
                    <TablesGridSkeleton />
                ) : (
                    <>
                        <CashRegisterPanel>
                            <></>
                        </CashRegisterPanel>
                        <PageHeader
                            eyebrow="Órdenes"
                            title="Mesas"
                            description="Visualiza el estado de cada mesa, agrega productos a la cuenta abierta y ciérrala al cobrar."
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
                                    {selectedRegister && (
                                        <Badge variant="secondary" className="gap-1">
                                            <Store className="h-3 w-3" />
                                            {selectedRegister.name}
                                        </Badge>
                                    )}
                                    <LiveIndicator timestamp={lastUpdated} isLive={!loading && !error} />
                                    <Button variant="outline" size="sm" onClick={() => void refresh()} className="w-full sm:w-auto">
                                        <RefreshCw className="mr-1 h-4 w-4" /> Refrescar
                                    </Button>
                                    <Button variant="outline" size="sm" asChild className="w-full sm:w-auto">
                                        <a href="/company/tables">
                                            <Cog className="mr-1 h-4 w-4" /> Gestionar mesas
                                        </a>
                                    </Button>
                                </div>
                            }
                        />

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
                            onOpenOrder={(orderId) => setSelectedOrderId(orderId)}
                            onOpenCashier={openCashierForTable}
                            />
                    </>
                )}
            </div>

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
                    // El contacto ya quedó persistido (aparece en /clients). Si
                    // tiene teléfono, re-lanzamos el lookup para que el cobro lo
                    // identifique y pueda emitir FEV al cliente recién creado.
                    setRegisterClientOpen(false);
                    if (client.phone) {
                        void payment.dianLookupClient(client.phone);
                    }
                }}
            />

            {recipientCompletionContactId !== null &&
                (() => {
                    // Refactor #235: lookup retorna array. Buscamos el match
                    // exacto por contactId para pre-llenar el modal.
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

        </PageShell>
    );
}
