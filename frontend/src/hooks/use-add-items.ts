import type { TableOrder } from '@/hooks/use-tables';
import { apiFetch } from '@/lib/api';
import { aggregateTax, calculateTaxLine } from '@/lib/tax';
import type { MenuItem, RestaurantMenu } from '@/types';
import { useCallback, useMemo, useState } from 'react';

/** Línea del carrito de "agregar productos" a una orden de mesa. */
export interface AddItemsCartLine {
    item: MenuItem;
    category: string;
    quantity: number;
}

interface AddItemsState {
    open: boolean;
    order: TableOrder | null;
    cart: Record<string, AddItemsCartLine>;
}

interface UseAddItemsArgs {
    token: string | null;
    /** Persiste los ítems en la orden (delegado a `useTables`). */
    appendItems: (orderId: string, items: { id: string; quantity: number }[]) => Promise<void>;
}

interface UseAddItemsReturn {
    addState: AddItemsState;
    activeMenu: RestaurantMenu | null;
    menuLoading: boolean;
    submitting: boolean;
    submitError: string | null;
    addBreakdown: { subtotal: number; tax_amount: number; total: number };
    addCartTotal: number;
    openAddItems: (order: TableOrder) => void;
    closeAddItems: () => void;
    incrementCart: (item: MenuItem, category: string) => void;
    decrementCart: (itemId: string) => void;
    submitAppendItems: () => Promise<void>;
}

/**
 * Maneja el flujo de "agregar productos" a una orden de mesa abierta:
 * carga perezosa del menú activo, carrito local con cantidades y preview
 * del total con desglose tributario. El comportamiento es idéntico al que
 * vivía inline en la página de mesas.
 */
export function useAddItems({ token, appendItems }: UseAddItemsArgs): UseAddItemsReturn {
    const [addState, setAddState] = useState<AddItemsState>({ open: false, order: null, cart: {} });
    const [activeMenu, setActiveMenu] = useState<RestaurantMenu | null>(null);
    const [menuLoading, setMenuLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const fetchActiveMenu = useCallback(async () => {
        if (!token) return;
        setMenuLoading(true);
        try {
            const res = await apiFetch('/api/v1/menus');
            const data = await res.json();
            if (!res.ok) {
                setActiveMenu(null);
                return;
            }
            const all = (data.data ?? []) as RestaurantMenu[];
            const active = all.find((m) => m.status === 'active') ?? null;
            setActiveMenu(active);
        } catch {
            setActiveMenu(null);
        } finally {
            setMenuLoading(false);
        }
    }, [token]);

    const openAddItems = useCallback(
        (order: TableOrder) => {
            setAddState({ open: true, order, cart: {} });
            setSubmitError(null);
            if (!activeMenu) void fetchActiveMenu();
        },
        [activeMenu, fetchActiveMenu],
    );

    const closeAddItems = useCallback(() => setAddState({ open: false, order: null, cart: {} }), []);

    const incrementCart = useCallback((item: MenuItem, category: string) => {
        setAddState((prev) => {
            const existing = prev.cart[item.id];
            return {
                ...prev,
                cart: {
                    ...prev.cart,
                    [item.id]: {
                        item,
                        category,
                        quantity: (existing?.quantity ?? 0) + 1,
                    },
                },
            };
        });
    }, []);

    const decrementCart = useCallback((itemId: string) => {
        setAddState((prev) => {
            const existing = prev.cart[itemId];
            if (!existing) return prev;
            const next = { ...prev.cart };
            if (existing.quantity <= 1) delete next[itemId];
            else next[itemId] = { ...existing, quantity: existing.quantity - 1 };
            return { ...prev, cart: next };
        });
    }, []);

    // Preview del total a agregar (con desglose tributario alineado al snapshot
    // de la orden — el backend usa el mismo fallback chain en appendItems).
    const addBreakdown = useMemo(() => {
        const order = addState.order;
        if (!order) return { subtotal: 0, tax_amount: 0, total: 0 };
        const orderRate = order.tax_rate ?? 0;
        const included = order.tax_included_in_price ?? true;
        return aggregateTax(
            Object.values(addState.cart).map((line) => {
                const lineRate = typeof line.item.tax_rate === 'number' && line.item.tax_rate >= 0 ? line.item.tax_rate : orderRate;
                return calculateTaxLine(line.item.price, line.quantity, lineRate, included);
            }),
        );
    }, [addState.cart, addState.order]);

    const addCartTotal = addBreakdown.total;

    const submitAppendItems = useCallback(async () => {
        if (!addState.order) return;
        const lines = Object.values(addState.cart);
        if (lines.length === 0) return;

        setSubmitting(true);
        setSubmitError(null);
        try {
            await appendItems(
                addState.order.id,
                lines.map((l) => ({ id: l.item.id, quantity: l.quantity })),
            );
            closeAddItems();
        } catch (e) {
            setSubmitError((e as Error).message);
        } finally {
            setSubmitting(false);
        }
    }, [addState.order, addState.cart, appendItems, closeAddItems]);

    return {
        addState,
        activeMenu,
        menuLoading,
        submitting,
        submitError,
        addBreakdown,
        addCartTotal,
        openAddItems,
        closeAddItems,
        incrementCart,
        decrementCart,
        submitAppendItems,
    };
}
