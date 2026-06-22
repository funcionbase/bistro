import CashRegisterPanel from '@/components/cash-register/cash-register-panel';
import BillableTablesPanel from '@/components/orders/billable-tables-panel';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CashierSkeleton } from '@/components/ui/cashier-skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useActiveAutoApply } from '@/hooks/use-active-auto-apply';
import { useCashRegister } from '@/hooks/use-cash-register';
import { useCouponValidation } from '@/hooks/use-coupon-validation';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { aggregateTax, calculateTaxLine } from '@/lib/tax';
import type { MenuItem, RestaurantMenu } from '@/types';

import { AlertCircle, Check, Minus, Plus, Store, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface CartLine {
    item: MenuItem;
    category: string;
    quantity: number;
    notes?: string;
}

export default function CajaPage() {
    const token = useToken();
    const formatCurrency = useCurrencyFormatter();
    const { selectedRegister } = useCashRegister(token);
    const sharedData = useSharedData();
    const activeCompany = sharedData.activeCompany;
    const activeCompanyNit = activeCompany?.nit ?? null;
    const activeBranchId = sharedData.activeBranch?.id ?? null;
    const taxRate = (activeCompany as { default_tax_rate?: number } | null)?.default_tax_rate ?? 0;
    const taxLabel = (activeCompany as { default_tax_label?: string } | null)?.default_tax_label ?? 'Impuesto';
    const taxIncluded = (activeCompany as { tax_included_in_price?: boolean } | null)?.tax_included_in_price ?? true;
    const isMounted = useRef(true);

    type OrderType = 'table' | 'delivery' | 'pickup';

    const [menu, setMenu] = useState<RestaurantMenu | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [cart, setCart] = useState<Record<string, CartLine>>({});
    const [orderType, setOrderType] = useState<OrderType>('table');
    const [clientPhone, setClientPhone] = useState('');
    const [tableNumber, setTableNumber] = useState(() => {
        if (typeof window === 'undefined') return '';
        return new URLSearchParams(window.location.search).get('table') ?? '';
    });
    // Mesas configuradas — fuente de verdad: el backend (`GET /api/v1/tables`),
    // las mismas que se ven en `/company/tables`. Antes caja leía un conteo de
    // `localStorage[tables.grid_size]` que se desincronizaba de las mesas reales
    // (mostraba más/menos de las creadas). Ahora el selector lista exactamente
    // las mesas activas existentes por su número real.
    const [tables, setTables] = useState<{ number: string }[]>([]);
    const tableCount = tables.length;
    const [occupiedTables, setOccupiedTables] = useState<Set<string>>(new Set());
    const [deliveryAddress, setDeliveryAddress] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [couponInput, setCouponInput] = useState('');
    const { validating: validatingCoupon, error: couponError, appliedCoupon, validateCoupon, removeCoupon } = useCouponValidation();

    const fetchMenu = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/menus');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar el menú.');
                return;
            }
            const all = (data.data ?? []) as RestaurantMenu[];
            const activeMenu = all.find((m) => m.status === 'active') ?? null;

            if (activeMenu && activeMenu.active_days && activeMenu.active_days.length > 0) {
                const today = new Date().getDay();
                if (!activeMenu.active_days.includes(today)) {
                    setMenu(null);
                    setError('El menú activo no aplica para hoy.');
                    return;
                }
            }
            setMenu(activeMenu);
            setError(activeMenu ? null : 'No hay un menú activo configurado.');
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    const fetchTables = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/tables');
            if (!res.ok) return;
            const json = await res.json();
            const list = (json.data ?? []) as { number: string; archived_at?: string | null }[];
            if (!isMounted.current) return;
            setTables(
                list
                    .filter((t) => !t.archived_at)
                    .sort((a, b) => (parseInt(a.number, 10) || 0) - (parseInt(b.number, 10) || 0))
                    .map((t) => ({ number: String(t.number) })),
            );
        } catch {
            // silent — no bloquea el flujo de caja
        }
    }, [token]);

    const fetchOccupiedTables = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/orders/tables');
            if (!res.ok) return;
            const json = await res.json();
            const list = (json.data ?? []) as { table_number: string }[];
            if (!isMounted.current) return;
            setOccupiedTables(new Set(list.map((o) => o.table_number)));
        } catch {
            // silent — no bloquea el flujo de caja
        }
    }, [token]);

    useEffect(() => {
        isMounted.current = true;
        void fetchMenu();
        void fetchTables();
        void fetchOccupiedTables();
        return () => {
            isMounted.current = false;
        };
    }, [fetchMenu, fetchTables, fetchOccupiedTables]);

    // Refresca mesas y ocupación al volver a la pestaña (pueden haber creado/archivado
    // mesas en /company/tables o abierto cuentas mientras tanto).
    useEffect(() => {
        const onFocus = () => {
            void fetchTables();
            void fetchOccupiedTables();
        };
        window.addEventListener('focus', onFocus);
        return () => window.removeEventListener('focus', onFocus);
    }, [fetchTables, fetchOccupiedTables]);

    // ── Persistencia del carrito (caja offline-first, plan §7.2) ──────────
    // Hidrata el carrito guardado al montar y lo persiste (debounced) en cada
    // cambio, para que sobreviva una recarga offline sin perder la venta en curso.
    const cartHydratedRef = useRef(false);
    const cartPersistTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!activeBranchId) return;
        let cancelled = false;
        void (async () => {
            try {
                const { getCart } = await import('@/lib/offline/db');
                const snap = await getCart(activeBranchId);
                if (cancelled) return;
                if (snap?.payload && typeof snap.payload === 'object') {
                    setCart(snap.payload as Record<string, CartLine>);
                }
            } catch {
                // best-effort; sin carrito persistido se arranca vacío
            } finally {
                cartHydratedRef.current = true;
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [activeBranchId]);

    useEffect(() => {
        if (!activeBranchId || !activeCompanyNit || !cartHydratedRef.current) return;
        if (cartPersistTimer.current) clearTimeout(cartPersistTimer.current);
        cartPersistTimer.current = setTimeout(() => {
            void (async () => {
                try {
                    const { putCart, deleteCart } = await import('@/lib/offline/db');
                    if (Object.keys(cart).length === 0) {
                        await deleteCart(activeBranchId);
                    } else {
                        await putCart(activeBranchId, activeCompanyNit, cart);
                    }
                } catch {
                    // best-effort
                }
            })();
        }, 400);
        return () => {
            if (cartPersistTimer.current) clearTimeout(cartPersistTimer.current);
        };
    }, [cart, activeBranchId, activeCompanyNit]);

    const addItem = (item: MenuItem, category: string) => {
        if (!item.available) return;
        setCart((prev) => {
            const existing = prev[item.id];
            return {
                ...prev,
                [item.id]: {
                    item,
                    category,
                    quantity: existing ? existing.quantity + 1 : 1,
                    notes: existing?.notes,
                },
            };
        });
    };

    const decrementItem = (itemId: string) => {
        setCart((prev) => {
            const existing = prev[itemId];
            if (!existing) return prev;
            if (existing.quantity <= 1) {
                const next = { ...prev };
                delete next[itemId];
                return next;
            }
            return { ...prev, [itemId]: { ...existing, quantity: existing.quantity - 1 } };
        });
    };

    const removeItem = (itemId: string) => {
        setCart((prev) => {
            const next = { ...prev };
            delete next[itemId];
            return next;
        });
    };

    const updateNotes = (itemId: string, notes: string) => {
        setCart((prev) => {
            const existing = prev[itemId];
            if (!existing) return prev;
            return { ...prev, [itemId]: { ...existing, notes: notes || undefined } };
        });
    };

    const cartLines = useMemo(() => Object.values(cart), [cart]);
    // Preview client-side del desglose tributario; el backend recalcula al persistir.
    // Cadena de resolución de tasa por ítem: item.tax_rate (override en menú) > companyDefault.
    const taxBreakdown = useMemo(
        () =>
            aggregateTax(
                cartLines.map((line) => {
                    const lineRate = typeof line.item.tax_rate === 'number' && line.item.tax_rate >= 0 ? line.item.tax_rate : taxRate;
                    return calculateTaxLine(line.item.price, line.quantity, lineRate, taxIncluded);
                }),
            ),
        [cartLines, taxRate, taxIncluded],
    );
    // Aplicar descuento del cupón (si fue validado) sobre el total bruto. El
    // backend recalcula con la misma política (descuento sobre base gravable).
    const discountAmount =
        appliedCoupon?.valid && typeof appliedCoupon.discount_amount === 'number' ? Math.min(appliedCoupon.discount_amount, taxBreakdown.total) : 0;
    const total = Math.max(0, taxBreakdown.total - discountAmount);

    // Happy hour activo: badge informativo. Si no hay cupón manual aplicado,
    // el backend hará el auto-apply al cerrar la orden (#125).
    const activeAutoApply = useActiveAutoApply(appliedCoupon?.valid ? 0 : taxBreakdown.total, clientPhone || undefined);

    const handleSubmit = async () => {
        setSubmitError(null);
        setSuccessMessage(null);

        if (cartLines.length === 0) {
            setSubmitError('Agrega al menos un ítem a la orden.');
            return;
        }
        if (orderType === 'table') {
            if (!tableNumber.trim() || !tables.some((t) => t.number === tableNumber)) {
                setSubmitError(
                    tableCount === 0
                        ? 'No hay mesas configuradas. Crea mesas en /company/tables primero.'
                        : 'Selecciona una mesa válida de la lista.',
                );
                return;
            }
            // Mesas ocupadas SÍ pueden recibir nuevas órdenes: el backend
            // vincula la orden a la sesión activa de la mesa para que al
            // final se cobre todo junto. Ya no se bloquea aquí.
        }
        if (orderType === 'delivery' && !deliveryAddress.trim()) {
            setSubmitError('Ingresa la dirección de entrega.');
            return;
        }

        setSubmitting(true);
        const orderPayload = {
            order_type: orderType,
            client_phone: clientPhone.trim() || null,
            table_number: orderType === 'table' ? tableNumber.trim() : null,
            delivery_address: orderType === 'delivery' ? deliveryAddress.trim() : null,
            items: cartLines.map((line) => ({
                id: line.item.id,
                quantity: line.quantity,
                notes: line.notes ?? null,
            })),
            coupon_code: appliedCoupon?.coupon_code ?? null,
        };
        try {
            const res = await apiFetch('/api/v1/orders', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(orderPayload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setSubmitError(data.message ?? 'Error al registrar la orden.');
                return;
            }
            setSuccessMessage(`Orden #${data.data?.id ?? ''} registrada. Aparecerá en el tablero.`);
            setCart({});
            setClientPhone('');
            setTableNumber('');
            setDeliveryAddress('');
            removeCoupon();
            setCouponInput('');
        } catch {
            // Modo offline (#140): si la red falló, encolar localmente con
            // client_uuid para idempotencia. El sync engine la enviará en cuanto
            // vuelva la conexión. NO se aplica cupón offline (requiere validación
            // server-side); el cajero deberá aplicarlo cuando vuelva online.
            if (!navigator.onLine && activeCompanyNit) {
                try {
                    const { putPendingOrder } = await import('@/lib/offline/db');
                    const { uuidv4 } = await import('@/lib/offline/uuid');
                    const { refreshPendingCount } = await import('@/lib/offline/sync-engine');
                    const clientUuid = uuidv4();
                    await putPendingOrder({
                        client_uuid: clientUuid,
                        company_nit: activeCompanyNit,
                        order_type: orderPayload.order_type,
                        client_phone: orderPayload.client_phone,
                        table_number: orderPayload.table_number,
                        delivery_address: orderPayload.delivery_address,
                        items: orderPayload.items.map((it) => ({ id: it.id, quantity: it.quantity, notes: it.notes ?? undefined })),
                        created_at: new Date().toISOString(),
                        attempts: 0,
                    });
                    await refreshPendingCount();
                    setSuccessMessage(`Orden encolada offline. Se sincronizará al recuperar conexión (uuid: ${clientUuid.slice(0, 8)}…).`);
                    setCart({});
                    setClientPhone('');
                    setTableNumber('');
                    setDeliveryAddress('');
                    removeCoupon();
                    setCouponInput('');
                    return;
                } catch (queueErr) {
                    setSubmitError(queueErr instanceof Error ? queueErr.message : 'No se pudo encolar la orden offline.');
                    return;
                }
            }
            setSubmitError('Error de conexión.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <PageShell
            title="Caja"
        >
            <div className="p-4 sm:p-6">
                {loading ? (
                    <CashierSkeleton />
                ) : (
                    <CashRegisterPanel>
                        {/* Mesas con cuentas pendientes de cobro. Polling 12s para
                        que el cajero vea aparecer mesas a medida que el mesero
                        aprueba tandas. Cada item enlaza a /caja/table-session/{id}
                        donde se hace el cobro (todo de una o por comensal). */}
                        <div className="mb-4">
                            <BillableTablesPanel />
                        </div>
                        <div className="grid gap-4 md:grid-cols-[1fr_360px] xl:grid-cols-[1fr_400px]">
                            {/* Catálogo */}
                            <div className="min-w-0 space-y-4">
                                <PageHeader
                                    title={menu ? menu.name : 'Caja'}
                                    eyebrow="POS"
                                    actions={
                                        <div className="flex flex-wrap items-center gap-2">
                                            {selectedRegister && (
                                                <Badge variant="secondary" className="gap-1">
                                                    <Store className="h-3 w-3" />
                                                    {selectedRegister.name}
                                                </Badge>
                                            )}
                                            {menu && <Badge variant="secondary">Menú activo</Badge>}
                                        </div>
                                    }
                                />

                                {error && (
                                    <Alert variant="destructive">
                                        <AlertCircle className="h-4 w-4" />
                                        <AlertDescription>{error}</AlertDescription>
                                    </Alert>
                                )}

                                {menu
                                    ? menu.structure.categories.map((category) => (
                                          <div key={category.id} className="space-y-2">
                                              <h2 className="text-muted-foreground text-sm font-semibold">{category.name}</h2>
                                              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                  {category.items.map((item) => {
                                                      const inCart = cart[item.id]?.quantity ?? 0;
                                                      const disabled = !item.available;
                                                      return (
                                                          <Card
                                                              key={item.id}
                                                              onClick={() => !disabled && addItem(item, category.name)}
                                                              className={`rounded-lg shadow-sm transition ${
                                                                  disabled
                                                                      ? 'cursor-not-allowed opacity-50'
                                                                      : 'hover:ring-primary/60 cursor-pointer hover:ring-2'
                                                              }`}
                                                          >
                                                              <CardContent className="space-y-1 p-3">
                                                                  <div className="flex items-start justify-between gap-2">
                                                                      <span className="text-sm font-medium">{item.name}</span>
                                                                      {inCart > 0 && <Badge className="shrink-0">{inCart}</Badge>}
                                                                  </div>
                                                                  <div className="text-muted-foreground text-xs tabular-nums">
                                                                      {formatCurrency(item.price)}
                                                                  </div>
                                                                  {!item.available && <span className="text-destructive text-xs">No disponible</span>}
                                                              </CardContent>
                                                          </Card>
                                                      );
                                                  })}
                                              </div>
                                          </div>
                                      ))
                                    : null}
                            </div>

                            {/* Carrito */}
                            <Card className="h-fit rounded-lg shadow-sm md:sticky md:top-4">
                                <CardHeader className="p-4 pb-2">
                                    <CardTitle className="text-base">Nueva orden</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 p-4 pt-0">
                                    <div className="space-y-1">
                                        <Label>Tipo de orden</Label>
                                        <ToggleGroup
                                            type="single"
                                            value={orderType}
                                            onValueChange={(v) => v && setOrderType(v as OrderType)}
                                            className="grid w-full grid-cols-3 gap-1"
                                            disabled={submitting}
                                        >
                                            {(
                                                [
                                                    { key: 'table', label: 'En sitio' },
                                                    { key: 'delivery', label: 'Domicilio' },
                                                    { key: 'pickup', label: 'Para llevar' },
                                                ] as { key: OrderType; label: string }[]
                                            ).map((opt) => (
                                                <ToggleGroupItem
                                                    key={opt.key}
                                                    value={opt.key}
                                                    size="sm"
                                                    variant="outline"
                                                    className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground h-8 text-xs"
                                                >
                                                    {opt.label}
                                                </ToggleGroupItem>
                                            ))}
                                        </ToggleGroup>
                                    </div>

                                    {orderType === 'table' && (
                                        <div className="space-y-1">
                                            <Label htmlFor="table-number">Número de mesa</Label>
                                            <Select value={tableNumber} onValueChange={setTableNumber} disabled={submitting || tableCount === 0}>
                                                <SelectTrigger id="table-number">
                                                    <SelectValue placeholder={tableCount === 0 ? 'Sin mesas configuradas' : 'Selecciona una mesa'} />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {tables.map(({ number: n }) => {
                                                        const occupied = occupiedTables.has(n);
                                                        return (
                                                            <SelectItem key={n} value={n}>
                                                                Mesa {n}
                                                                {occupied && (
                                                                    <span className="ml-1 text-xs text-[color:var(--color-status-warning)]">
                                                                        · ocupada (sumar a la cuenta)
                                                                    </span>
                                                                )}
                                                            </SelectItem>
                                                        );
                                                    })}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-muted-foreground text-xs">
                                                {tableCount} {tableCount === 1 ? 'mesa configurada' : 'mesas configuradas'}. Gestiónalas en{' '}
                                                <a href="/company/tables" className="underline">
                                                    Mesas
                                                </a>
                                                .
                                            </p>
                                        </div>
                                    )}

                                    {orderType === 'delivery' && (
                                        <div className="space-y-1">
                                            <Label htmlFor="delivery-address">Dirección de entrega</Label>
                                            <Input
                                                id="delivery-address"
                                                value={deliveryAddress}
                                                onChange={(e) => setDeliveryAddress(e.target.value)}
                                                placeholder="Calle 123 #45-67, apto 802"
                                                disabled={submitting}
                                            />
                                        </div>
                                    )}

                                    <div className="space-y-1">
                                        <Label htmlFor="client-phone">
                                            Teléfono del cliente <span className="text-muted-foreground text-xs">(opcional)</span>
                                        </Label>
                                        <Input
                                            id="client-phone"
                                            value={clientPhone}
                                            // Saneo cliente (§5): solo dígitos, +, espacios y separadores.
                                            // La normalización a E.164 vive en backend (fuente de verdad).
                                            onChange={(e) => setClientPhone(e.target.value.replace(/[^\d+\s()-]/g, '').slice(0, 30))}
                                            placeholder="+57 300 000 0000"
                                            inputMode="tel"
                                            maxLength={30}
                                            disabled={submitting}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Si no incluyes prefijo, asumimos +57 (Colombia). Si lo dejas, le enviamos
                                            avisos por SMS del estado de su pedido.
                                        </p>
                                    </div>

                                    <div className="max-h-[40vh] space-y-2 overflow-y-auto">
                                        {cartLines.length === 0 ? (
                                            <p className="text-muted-foreground py-6 text-center text-sm">Sin ítems aún</p>
                                        ) : (
                                            cartLines.map((line) => (
                                                <div key={line.item.id} className="rounded-md border p-2 text-sm">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div className="min-w-0">
                                                            <div className="truncate font-medium">{line.item.name}</div>
                                                            <div className="text-muted-foreground text-xs tabular-nums">
                                                                {formatCurrency(line.item.price * line.quantity)}
                                                            </div>
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-1">
                                                            <Button
                                                                variant="outline"
                                                                size="icon"
                                                                className="h-9 w-9 sm:h-8 sm:w-8"
                                                                onClick={() => decrementItem(line.item.id)}
                                                                disabled={submitting}
                                                                aria-label="Quitar uno"
                                                            >
                                                                <Minus className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <span className="w-6 text-center text-sm tabular-nums">{line.quantity}</span>
                                                            <Button
                                                                variant="outline"
                                                                size="icon"
                                                                className="h-9 w-9 sm:h-8 sm:w-8"
                                                                onClick={() => addItem(line.item, line.category)}
                                                                disabled={submitting}
                                                                aria-label="Agregar uno"
                                                            >
                                                                <Plus className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-9 w-9 sm:h-8 sm:w-8"
                                                                onClick={() => removeItem(line.item.id)}
                                                                disabled={submitting}
                                                                aria-label="Eliminar"
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                    <Input
                                                        value={line.notes ?? ''}
                                                        onChange={(e) => updateNotes(line.item.id, e.target.value)}
                                                        placeholder="Notas (opcional)"
                                                        className="mt-2 h-7 text-xs"
                                                        disabled={submitting}
                                                    />
                                                </div>
                                            ))
                                        )}
                                    </div>

                                    {taxRate > 0 && (
                                        <div className="space-y-1 border-t pt-2 text-xs">
                                            <div className="flex items-center justify-between">
                                                <span className="text-muted-foreground">Subtotal</span>
                                                <span className="tabular-nums">{formatCurrency(taxBreakdown.subtotal)}</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-muted-foreground">{taxLabel}</span>
                                                <span className="tabular-nums">{formatCurrency(taxBreakdown.tax_amount)}</span>
                                            </div>
                                        </div>
                                    )}

                                    {/* Cupón */}
                                    <div className="space-y-1 border-t pt-2 text-xs">
                                        {activeAutoApply && !appliedCoupon?.valid && (
                                            <div className="flex items-center justify-between rounded-md border border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 px-2 py-1.5 text-[color:var(--color-status-warning)]">
                                                <span className="flex items-center gap-1.5">
                                                    <span aria-hidden>🎉</span>
                                                    <span className="font-medium">{activeAutoApply.label ?? 'Promo activa'}</span>
                                                </span>
                                                {typeof activeAutoApply.discount_amount === 'number' && (
                                                    <span className="text-[11px] tabular-nums">
                                                        −{formatCurrency(activeAutoApply.discount_amount)}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                        {appliedCoupon?.valid ? (
                                            <div className="flex items-center justify-between rounded-md border border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 px-2 py-1.5 text-[color:var(--color-status-safe)]">
                                                <span className="flex items-center gap-1.5">
                                                    <Check className="h-3 w-3" />
                                                    <span className="font-medium">{appliedCoupon.coupon_code}</span>
                                                    <span className="tabular-nums opacity-80">−{formatCurrency(discountAmount)}</span>
                                                </span>
                                                <button
                                                    type="button"
                                                    className="text-xs underline opacity-80 hover:opacity-100"
                                                    onClick={() => {
                                                        removeCoupon();
                                                        setCouponInput('');
                                                    }}
                                                    disabled={submitting}
                                                >
                                                    quitar
                                                </button>
                                            </div>
                                        ) : (
                                            <div className="flex gap-1">
                                                <Input
                                                    value={couponInput}
                                                    onChange={(e) => setCouponInput(e.target.value.toUpperCase())}
                                                    placeholder="Código de cupón"
                                                    className="h-7 text-xs uppercase"
                                                    disabled={submitting || validatingCoupon || cartLines.length === 0}
                                                    maxLength={60}
                                                />
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 text-xs"
                                                    disabled={
                                                        submitting || validatingCoupon || cartLines.length === 0 || couponInput.trim().length === 0
                                                    }
                                                    onClick={() =>
                                                        void validateCoupon(couponInput.trim(), taxBreakdown.total, clientPhone.trim() || undefined)
                                                    }
                                                >
                                                    {validatingCoupon ? '…' : 'Aplicar'}
                                                </Button>
                                            </div>
                                        )}
                                        {couponError && !appliedCoupon?.valid && <div className="text-destructive">{couponError}</div>}
                                    </div>

                                    <div className="flex items-center justify-between border-t pt-2">
                                        <span className="text-sm font-semibold">Total</span>
                                        <span className="text-base font-semibold tabular-nums">{formatCurrency(total)}</span>
                                    </div>

                                    {submitError && (
                                        <Alert variant="destructive" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                                            <AlertCircle className="h-3.5 w-3.5" />
                                            <AlertDescription className="text-xs">{submitError}</AlertDescription>
                                        </Alert>
                                    )}
                                    {successMessage && (
                                        <Alert variant="safe" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                                            <Check className="h-3.5 w-3.5" />
                                            <AlertDescription className="text-xs">{successMessage}</AlertDescription>
                                        </Alert>
                                    )}

                                    <Button className="w-full" onClick={handleSubmit} disabled={submitting || !menu || cartLines.length === 0}>
                                        {submitting ? 'Registrando...' : 'Registrar orden'}
                                    </Button>
                                </CardContent>
                            </Card>
                        </div>
                    </CashRegisterPanel>
                )}
            </div>
        </PageShell>
    );
}
