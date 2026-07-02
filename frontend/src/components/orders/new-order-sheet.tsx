import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useActiveAutoApply } from '@/hooks/use-active-auto-apply';
import { useCouponValidation } from '@/hooks/use-coupon-validation';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { aggregateTax, calculateTaxLine } from '@/lib/tax';
import type { MenuItem, RestaurantMenu } from '@/types';
import { AlertCircle, Check, Minus, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { sanitizePlainText } from '@/lib/input-sanitize';

interface CartLine {
    item: MenuItem;
    category: string;
    quantity: number;
    notes?: string;
}

type OrderType = 'table' | 'delivery' | 'pickup';

interface NewOrderSheetProps {
    isOpen: boolean;
    onClose: () => void;
    initialTableNumber?: string;
    onSuccess?: () => void;
}

/**
 * Sheet de nueva orden embebible en cualquier página (ej. Mesas).
 * Misma lógica que CajaPage sin la persistencia offline del carrito
 * ni el panel de mesas con cuentas abiertas — esos viven en /caja.
 */
export function NewOrderSheet({ isOpen, onClose, initialTableNumber = '', onSuccess }: NewOrderSheetProps) {
    const token = useToken();
    const formatCurrency = useCurrencyFormatter();
    const sharedData = useSharedData();
    const activeCompany = sharedData.activeCompany;
    const taxRate = (activeCompany as { default_tax_rate?: number } | null)?.default_tax_rate ?? 0;
    const taxLabel = (activeCompany as { default_tax_label?: string } | null)?.default_tax_label ?? 'Impuesto';
    const taxIncluded = (activeCompany as { tax_included_in_price?: boolean } | null)?.tax_included_in_price ?? true;

    const [menu, setMenu] = useState<RestaurantMenu | null>(null);
    const [menuLoading, setMenuLoading] = useState(false);
    const [menuError, setMenuError] = useState<string | null>(null);
    const [cart, setCart] = useState<Record<string, CartLine>>({});
    const [orderType, setOrderType] = useState<OrderType>('table');
    const [tableNumber, setTableNumber] = useState(initialTableNumber);
    const [tables, setTables] = useState<{ number: string }[]>([]);
    const [occupiedTables, setOccupiedTables] = useState<Set<string>>(new Set());
    const [clientPhone, setClientPhone] = useState('');
    const [deliveryAddress, setDeliveryAddress] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [couponInput, setCouponInput] = useState('');
    const { validating: validatingCoupon, error: couponError, appliedCoupon, validateCoupon, removeCoupon } = useCouponValidation();

    const isMounted = useRef(true);
    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    // Resetea el formulario y carga menú + mesas cuando se abre el sheet.
    const resetForm = useCallback(() => {
        setCart({});
        setOrderType('table');
        setClientPhone('');
        setDeliveryAddress('');
        setSubmitError(null);
        setCouponInput('');
        removeCoupon();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!isOpen) return;
        resetForm();
        setTableNumber(initialTableNumber);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, initialTableNumber]);

    useEffect(() => {
        if (!isOpen || !token) return;

        setMenuLoading(true);
        setMenuError(null);

        void (async () => {
            try {
                const res = await apiFetch('/api/v1/menus');
                const data = await res.json();
                if (!isMounted.current) return;
                if (!res.ok) {
                    setMenuError(data.message ?? 'Error al cargar el menú.');
                    return;
                }
                const all = (data.data ?? []) as RestaurantMenu[];
                const activeMenu = all.find((m) => m.status === 'active') ?? null;
                if (activeMenu?.active_days && activeMenu.active_days.length > 0) {
                    const today = new Date().getDay();
                    if (!activeMenu.active_days.includes(today)) {
                        setMenu(null);
                        setMenuError('El menú activo no aplica para hoy.');
                        return;
                    }
                }
                setMenu(activeMenu);
                if (!activeMenu) setMenuError('No hay un menú activo configurado.');
            } catch {
                if (isMounted.current) setMenuError('Error de conexión.');
            } finally {
                if (isMounted.current) setMenuLoading(false);
            }
        })();

        void (async () => {
            try {
                const [tablesRes, ordersRes] = await Promise.all([apiFetch('/api/v1/tables'), apiFetch('/api/v1/orders/tables')]);
                if (!isMounted.current) return;
                if (tablesRes.ok) {
                    const json = await tablesRes.json();
                    const list = (json.data ?? []) as { number: string; archived_at?: string | null }[];
                    setTables(
                        list
                            .filter((t) => !t.archived_at)
                            .sort((a, b) => (parseInt(a.number, 10) || 0) - (parseInt(b.number, 10) || 0))
                            .map((t) => ({ number: String(t.number) })),
                    );
                }
                if (ordersRes.ok) {
                    const json = await ordersRes.json();
                    setOccupiedTables(new Set((json.data ?? []).map((o: { table_number: string }) => o.table_number)));
                }
            } catch {
                // silent — no bloquea el flujo
            }
        })();
    }, [isOpen, token]);

    const addItem = (item: MenuItem, category: string) => {
        if (!item.available) return;
        setCart((prev) => {
            const existing = prev[item.id];
            return { ...prev, [item.id]: { item, category, quantity: existing ? existing.quantity + 1 : 1, notes: existing?.notes } };
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

    const removeItem = (itemId: string) =>
        setCart((prev) => {
            const next = { ...prev };
            delete next[itemId];
            return next;
        });

    const updateNotes = (itemId: string, notes: string) =>
        setCart((prev) => {
            const existing = prev[itemId];
            if (!existing) return prev;
            return { ...prev, [itemId]: { ...existing, notes: notes || undefined } };
        });

    const cartLines = useMemo(() => Object.values(cart), [cart]);

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

    const discountAmount =
        appliedCoupon?.valid && typeof appliedCoupon.discount_amount === 'number' ? Math.min(appliedCoupon.discount_amount, taxBreakdown.total) : 0;
    const total = Math.max(0, taxBreakdown.total - discountAmount);
    const activeAutoApply = useActiveAutoApply(appliedCoupon?.valid ? 0 : taxBreakdown.total, clientPhone || undefined);
    const tableCount = tables.length;

    const handleSubmit = async () => {
        setSubmitError(null);
        if (cartLines.length === 0) {
            setSubmitError('Agrega al menos un ítem a la orden.');
            return;
        }
        if (orderType === 'table' && (!tableNumber.trim() || !tables.some((t) => t.number === tableNumber))) {
            setSubmitError(tableCount === 0 ? 'No hay mesas configuradas. Crea mesas en Mesas físicas primero.' : 'Selecciona una mesa válida de la lista.');
            return;
        }
        if (orderType === 'delivery' && !deliveryAddress.trim()) {
            setSubmitError('Ingresa la dirección de entrega.');
            return;
        }

        setSubmitting(true);
        const payload = {
            order_type: orderType,
            client_phone: clientPhone.trim() || null,
            table_number: orderType === 'table' ? tableNumber.trim() : null,
            delivery_address: orderType === 'delivery' ? deliveryAddress.trim() : null,
            items: cartLines.map((l) => ({ id: l.item.id, quantity: l.quantity, notes: l.notes ?? null })),
            coupon_code: appliedCoupon?.coupon_code ?? null,
        };
        try {
            const res = await apiFetch('/api/v1/orders', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setSubmitError(data.message ?? 'Error al registrar la orden.');
                return;
            }
            onSuccess?.();
            onClose();
        } catch {
            setSubmitError('Error de conexión.');
        } finally {
            if (isMounted.current) setSubmitting(false);
        }
    };

    return (
        <Sheet open={isOpen} onOpenChange={(v) => !v && onClose()}>
            <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-3xl">
                <SheetHeader className="sticky top-0 z-10 border-b bg-background p-4">
                    <SheetTitle>Nueva orden{tableNumber ? ` — Mesa ${tableNumber}` : ''}</SheetTitle>
                </SheetHeader>

                {menuLoading ? (
                    <div className="p-6 text-sm text-muted-foreground">Cargando menú…</div>
                ) : (
                    <div className="grid gap-4 p-4 md:grid-cols-[1fr_280px]">
                        {/* Catálogo */}
                        <div className="min-w-0 space-y-4">
                            {menuError && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{menuError}</AlertDescription>
                                </Alert>
                            )}
                            {menu?.structure.categories.map((category) => (
                                <div key={category.id} className="space-y-2">
                                    <h2 className="text-sm font-semibold text-muted-foreground">{category.name}</h2>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {category.items.map((item) => {
                                            const inCart = cart[item.id]?.quantity ?? 0;
                                            const disabled = !item.available;
                                            return (
                                                <Card
                                                    key={item.id}
                                                    onClick={() => !disabled && addItem(item, category.name)}
                                                    className={`rounded-lg shadow-sm transition ${disabled ? 'cursor-not-allowed opacity-50' : 'hover:ring-primary/60 cursor-pointer hover:ring-2'}`}
                                                >
                                                    <CardContent className="space-y-1 p-3">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <span className="text-sm font-medium">{item.name}</span>
                                                            {inCart > 0 && <Badge className="shrink-0">{inCart}</Badge>}
                                                        </div>
                                                        <div className="text-xs tabular-nums text-muted-foreground">{formatCurrency(item.price)}</div>
                                                        {!item.available && <span className="text-xs text-destructive">No disponible</span>}
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
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
                                        <Label htmlFor="new-order-table">Número de mesa</Label>
                                        <Select value={tableNumber} onValueChange={setTableNumber} disabled={submitting || tableCount === 0}>
                                            <SelectTrigger id="new-order-table">
                                                <SelectValue placeholder={tableCount === 0 ? 'Sin mesas configuradas' : 'Selecciona una mesa'} />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {tables.map(({ number: n }) => (
                                                    <SelectItem key={n} value={n}>
                                                        Mesa {n}
                                                        {occupiedTables.has(n) && (
                                                            <span className="ml-1 text-xs text-[color:var(--color-status-warning)]">
                                                                · ocupada (sumar a la cuenta)
                                                            </span>
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            {tableCount} {tableCount === 1 ? 'mesa configurada' : 'mesas configuradas'}.
                                        </p>
                                    </div>
                                )}

                                {orderType === 'delivery' && (
                                    <div className="space-y-1">
                                        <Label htmlFor="new-order-address">Dirección de entrega</Label>
                                        <Input
                                            id="new-order-address"
                                            value={deliveryAddress}
                                            onChange={(e) => setDeliveryAddress(sanitizePlainText(e.target.value, 500, true, false))}
                                            maxLength={500}
                                            placeholder="Calle 123 #45-67, apto 802"
                                            disabled={submitting}
                                        />
                                    </div>
                                )}

                                <div className="space-y-1">
                                    <Label htmlFor="new-order-phone">
                                        Teléfono del cliente <span className="text-xs text-muted-foreground">(opcional)</span>
                                    </Label>
                                    <Input
                                        id="new-order-phone"
                                        value={clientPhone}
                                        onChange={(e) => setClientPhone(e.target.value.replace(/[^\d+\s()-]/g, '').slice(0, 30))}
                                        placeholder="+57 300 000 0000"
                                        inputMode="tel"
                                        maxLength={30}
                                        disabled={submitting}
                                    />
                                </div>

                                <div className="max-h-[35vh] space-y-2 overflow-y-auto">
                                    {cartLines.length === 0 ? (
                                        <p className="py-6 text-center text-sm text-muted-foreground">Sin ítems aún</p>
                                    ) : (
                                        cartLines.map((line) => (
                                            <div key={line.item.id} className="rounded-md border p-2 text-sm">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <div className="truncate font-medium">{line.item.name}</div>
                                                        <div className="text-xs tabular-nums text-muted-foreground">
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
                                                    onChange={(e) => updateNotes(line.item.id, sanitizePlainText(e.target.value, 500, true, false))}
                                                    maxLength={500}
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

                                <div className="space-y-1 border-t pt-2 text-xs">
                                    {activeAutoApply && !appliedCoupon?.valid && (
                                        <div className="flex items-center justify-between rounded-md border border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 px-2 py-1.5 text-[color:var(--color-status-warning)]">
                                            <span className="flex items-center gap-1.5">
                                                <span aria-hidden>🎉</span>
                                                <span className="font-medium">{activeAutoApply.label ?? 'Promo activa'}</span>
                                            </span>
                                            {typeof activeAutoApply.discount_amount === 'number' && (
                                                <span className="text-[11px] tabular-nums">−{formatCurrency(activeAutoApply.discount_amount)}</span>
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
                                                onChange={(e) => setCouponInput(sanitizePlainText(e.target.value.toUpperCase(), 60, false, false))}
                                                    maxLength={60}
                                                placeholder="Código de cupón"
                                                className="h-7 text-xs uppercase"
                                                disabled={submitting || validatingCoupon || cartLines.length === 0}
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-7 text-xs"
                                                disabled={submitting || validatingCoupon || cartLines.length === 0 || couponInput.trim().length === 0}
                                                onClick={() => void validateCoupon(couponInput.trim(), taxBreakdown.total, clientPhone.trim() || undefined)}
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
                                    <Alert variant="destructive" className="p-2 [&>svg]:left-2 [&>svg]:top-2 [&>svg~*]:pl-5">
                                        <AlertCircle className="h-3.5 w-3.5" />
                                        <AlertDescription className="text-xs">{submitError}</AlertDescription>
                                    </Alert>
                                )}

                                <Button className="w-full" onClick={() => void handleSubmit()} disabled={submitting || !menu || cartLines.length === 0}>
                                    {submitting ? 'Registrando…' : 'Registrar orden'}
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
