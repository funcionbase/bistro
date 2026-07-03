import MenuItemDetailDialog, { type MenuItemDetailDialogItem } from '@/components/menu/menu-item-detail-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { EmptyState } from '@/components/ui/empty-state';
import { HeroHeadline } from '@/components/ui/hero-headline';
import { MenuItemRow } from '@/components/ui/menu-item-row';
import { Skeleton } from '@/components/ui/skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { resolveBackendUrl } from '@/lib/api';
import { formatCurrency } from '@/lib/coupon-helpers';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { type MenuItem, type MenuStructure } from '@/types';
import { Clock, Info, MapPin, Minus, Plus, QrCode, ShoppingBag, Users, UtensilsCrossed, WifiOff } from 'lucide-react';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { useEffect, useRef, useState } from 'react';

interface PublicMenuProps {
    nit: string | null;
    table: string | null;
    /** Sede resuelta server-side desde el QR de menú de sede (?table={menu_qr_token}). */
    branch_id?: string | null;
    /** Token del QR de menú de sede (?branch=CWP). Se resuelve client-side. */
    branchToken?: string | null;
}

interface RestaurantBranding {
    commercial_name: string;
    branch_name?: string | null;
    /** Ciudad de la sede — los domicilios solo cubren esta ciudad. */
    branch_city?: string | null;
    logo_url: string | null;
    primary_color: string;
    header_image_url?: string | null;
    footer_image_url?: string | null;
    tagline?: string | null;
    card_style?: 'default' | 'compact' | 'card';
    show_branding?: boolean;
    /** Precio del envío a domicilio (COP) configurado por la sede. */
    delivery_fee?: number | null;
}

interface CartLine {
    id: string;
    name: string;
    price: number;
    quantity: number;
}

interface PublicMenuPayload {
    data: {
        id: string;
        company_nit: string;
        name: string;
        description: string | null;
        structure: MenuStructure;
    } | null;
    restaurant?: RestaurantBranding;
    restaurant_status?: {
        is_open: boolean;
        reason?: string;
        next_opening?: string | null;
        message?: string;
    };
}

type LoadState =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'menu'; payload: NonNullable<PublicMenuPayload['data']> }
    | { kind: 'closed'; reason: string; nextOpening: string | null; message?: string }
    | { kind: 'no-menu' }
    | { kind: 'no-restaurant' }
    | { kind: 'error'; message: string };

const LAST_NIT_KEY = 'menu_last_nit';
const SCAN_SESSION_KEY = (nit: string) => `menu_scan_session:${nit}`;
const TABLE_PRESELECT_KEY = (nit: string) => `cart:preselected_table:${nit}`;

function readLastNit(): string | null {
    if (typeof window === 'undefined') return null;
    try {
        return localStorage.getItem(LAST_NIT_KEY);
    } catch {
        return null;
    }
}

function generateUuid(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const r = () => Math.floor(Math.random() * 16).toString(16);
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const n = parseInt(r(), 16);
        return (c === 'x' ? n : (n & 0x3) | 0x8).toString(16);
    });
}

export default function PublicMenu({ nit, table, branch_id, branchToken }: PublicMenuProps) {
    const appName = 'bistro';
    const initialEffective = nit ?? readLastNit();
    const [effectiveNit, setEffectiveNit] = useState<string | null>(initialEffective);
    const [restaurant, setRestaurant] = useState<RestaurantBranding | null>(null);
    const [state, setState] = useState<LoadState>(initialEffective ? { kind: 'loading' } : { kind: 'no-restaurant' });
    const scanSentRef = useRef(false);
    const branchResolvedRef = useRef(false);

    const [tableStatus, setTableStatus] = useState<{
        qr_token: string;
        active_session: { id: string; guests_count: number; accepts_new_guests: boolean } | null;
        waiter_order_active: boolean;
    } | null>(null);
    // Sede resuelta a partir del `?table=N`. Se usa para refetch del menú
    // con el filtro de branch correcto cuando una empresa tiene un menú
    // activo por sede.
    const [effectiveBranchId, setEffectiveBranchId] = useState<string | null>(branch_id ?? null);
    // Número legible de la mesa (e.g. "3"). Con el QR antiguo coincide con `table`;
    // con el nuevo formato opaco se resuelve desde la API después de decodificar el token.
    const [effectiveTableNumber, setEffectiveTableNumber] = useState<string | null>(
        table !== null && /^\d+$/.test(table) ? table : null,
    );
    const [askJoin, setAskJoin] = useState<'new' | 'join' | null>(null);
    const askedRef = useRef(false);

    // Pedido sin mesa desde el QR de sede (?branch=): carrito local + checkout
    // (para llevar / domicilio). La orden nace pending_approval y la aprueba
    // caja. Solo disponible en el flujo de branch-token, sin mesa asociada.
    const [cart, setCart] = useState<Record<string, CartLine>>({});
    const [checkoutOpen, setCheckoutOpen] = useState(false);
    const [orderType, setOrderType] = useState<'pickup' | 'delivery'>('pickup');
    const [custName, setCustName] = useState('');
    const [custPhone, setCustPhone] = useState('');
    const [custAddress, setCustAddress] = useState('');
    const [custNeighborhood, setCustNeighborhood] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [placedOrder, setPlacedOrder] = useState<{ order_id: string; total: string; order_type: string } | null>(null);

    const canOrder = !!branchToken && !!effectiveBranchId && !tableStatus && state.kind === 'menu';
    const cartLines = Object.values(cart);
    const cartCount = cartLines.reduce((acc, l) => acc + l.quantity, 0);
    const cartItemsTotal = cartLines.reduce((acc, l) => acc + l.price * l.quantity, 0);
    const deliveryFee = orderType === 'delivery' ? (restaurant?.delivery_fee ?? 0) : 0;
    const cartTotal = cartItemsTotal + deliveryFee;

    const addToCart = (item: { id: string; name: string; price: number }) => {
        setCart((prev) => {
            const current = prev[item.id]?.quantity ?? 0;
            return { ...prev, [item.id]: { id: item.id, name: item.name, price: item.price, quantity: Math.min(99, current + 1) } };
        });
    };

    const changeQuantity = (id: string, delta: number) => {
        setCart((prev) => {
            const line = prev[id];
            if (!line) return prev;
            const quantity = line.quantity + delta;
            if (quantity < 1) {
                const next = { ...prev };
                delete next[id];
                return next;
            }
            return { ...prev, [id]: { ...line, quantity: Math.min(99, quantity) } };
        });
    };

    const phoneValid = /^3\d{9}$/.test(custPhone.trim());
    const checkoutValid =
        cartLines.length > 0 &&
        custName.trim().length >= 2 &&
        phoneValid &&
        (orderType === 'pickup' || (custAddress.trim().length >= 5 && custNeighborhood.trim().length >= 2));

    const submitOrder = async () => {
        if (!branchToken || !checkoutValid || submitting) return;
        setSubmitting(true);
        setSubmitError(null);
        try {
            const res = await fetch(resolveBackendUrl(`/api/v1/public/branch/${encodeURIComponent(branchToken)}/orders`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'omit',
                body: JSON.stringify({
                    type: orderType,
                    customer_name: custName.trim(),
                    customer_phone: custPhone.trim(),
                    address: orderType === 'delivery' ? custAddress.trim() : null,
                    neighborhood: orderType === 'delivery' ? custNeighborhood.trim() : null,
                    items: cartLines.map((l) => ({ id: l.id, quantity: l.quantity })),
                }),
            });
            const json = (await res.json().catch(() => null)) as
                | { data?: { order_id: string; total: string; order_type: string }; message?: string }
                | null;
            if (!res.ok || !json?.data) {
                setSubmitError(json?.message ?? 'No pudimos enviar el pedido. Intenta de nuevo.');
                return;
            }
            setPlacedOrder(json.data);
            setCart({});
            setCheckoutOpen(false);
        } catch {
            setSubmitError('No pudimos enviar el pedido. Revisa tu conexión e intenta de nuevo.');
        } finally {
            setSubmitting(false);
        }
    };

    useEffect(() => {
        if (typeof window === 'undefined' || !nit) return;
        try {
            localStorage.setItem(LAST_NIT_KEY, nit);
        } catch {
            // localStorage bloqueado en modo privado; el redirect server igual ocurrió.
        }
    }, [nit]);

    useEffect(() => {
        if (typeof window === 'undefined' || !effectiveNit || !effectiveTableNumber) return;
        try {
            sessionStorage.setItem(TABLE_PRESELECT_KEY(effectiveNit), effectiveTableNumber);
        } catch {
            // sessionStorage puede estar bloqueado en modo privado; ignorar.
        }
    }, [effectiveNit, effectiveTableNumber]);

    // Nuevo formato: ?table={qr_token} (no-dígito). Resuelve NIT + sede + número
    // de mesa desde el token opaco. El formato viejo (?table={número}) sigue
    // usando el efecto de abajo para backward compat con QRs ya impresos.
    useEffect(() => {
        if (typeof window === 'undefined' || !table || /^\d+$/.test(table)) return;
        let cancelled = false;
        fetch(resolveBackendUrl(`/api/v1/public/table-resolve/${encodeURIComponent(table)}`), {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(res.status === 404 ? 'qr-not-found' : 'no-table'))))
            .then(
                (json: {
                    table_exists: boolean;
                    company_nit: string;
                    qr_token: string;
                    table_number: string;
                    branch?: { id: string; name: string };
                    active_session: { id: string; guests_count: number; accepts_new_guests: boolean } | null;
                    waiter_order_active?: boolean;
                }) => {
                    if (cancelled || !json.table_exists) return;
                    setEffectiveNit(json.company_nit);
                    setEffectiveTableNumber(json.table_number);
                    setTableStatus({
                        qr_token: json.qr_token,
                        active_session: json.active_session,
                        waiter_order_active: !!json.waiter_order_active,
                    });
                    if (json.branch?.id) {
                        setEffectiveBranchId(json.branch.id);
                    }
                    try {
                        localStorage.setItem(LAST_NIT_KEY, json.company_nit);
                    } catch {
                        // ignorar
                    }
                    if (!askedRef.current && !json.waiter_order_active) {
                        askedRef.current = true;
                        setAskJoin(json.active_session ? 'join' : 'new');
                    }
                },
            )
            .catch((err: unknown) => {
                // QR inválido o de mesa/sede archivada (404): sin `nit` en la
                // URL no hay contexto legítimo — antes se caía en silencio al
                // menú de la ÚLTIMA empresa visitada (localStorage), mostrando
                // otro restaurante sin aviso. Errores de red conservan el
                // fallback best-effort.
                if (!cancelled && !nit && err instanceof Error && err.message === 'qr-not-found') {
                    setEffectiveNit(null);
                    setState({ kind: 'no-restaurant' });
                }
            });
        return () => {
            cancelled = true;
        };
    }, [table, nit]);

    useEffect(() => {
        if (typeof window === 'undefined' || !effectiveNit || !table || !/^\d+$/.test(table)) return;
        let cancelled = false;
        fetch(resolveBackendUrl(`/api/v1/public/menu/${encodeURIComponent(effectiveNit)}/table/${encodeURIComponent(table)}`), {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error('no-table'))))
            .then(
                (json: {
                    table_exists: boolean;
                    qr_token: string;
                    branch?: { id: string; name: string };
                    active_session: { id: string; guests_count: number; accepts_new_guests: boolean } | null;
                    waiter_order_active?: boolean;
                }) => {
                    if (cancelled || !json.table_exists) return;
                    setTableStatus({
                        qr_token: json.qr_token,
                        active_session: json.active_session,
                        waiter_order_active: !!json.waiter_order_active,
                    });
                    if (json.branch?.id) {
                        setEffectiveBranchId(json.branch.id);
                    }
                    // Si el mesero ya tomó la orden manualmente, NO abrimos el
                    // diálogo de "unirse" / "empezar pedido" — el cliente debe
                    // hablar con el mesero. Mostramos solo la carta a modo
                    // informativo.
                    if (!askedRef.current && !json.waiter_order_active) {
                        askedRef.current = true;
                        setAskJoin(json.active_session ? 'join' : 'new');
                    }
                },
            )
            .catch(() => {
                // 404 o red caída: cae al menú estándar sin CTA de sesión.
            });
        return () => {
            cancelled = true;
        };
    }, [effectiveNit, table]);

    useEffect(() => {
        if (typeof window === 'undefined' || !effectiveNit || scanSentRef.current) return;
        // Con QR opaco (sede o mesa) esperamos la resolución del token para
        // atribuir el scan a la sede/mesa reales — si no, el backend lo
        // asociaba a la sede default y guardaba el token como "mesa".
        const tableIsToken = !!table && !/^\d+$/.test(table);
        if (branchToken && !effectiveBranchId) return;
        if (tableIsToken && !tableStatus) return;
        scanSentRef.current = true;

        const key = SCAN_SESSION_KEY(effectiveNit);
        let sessionId: string;
        try {
            sessionId = sessionStorage.getItem(key) ?? generateUuid();
            sessionStorage.setItem(key, sessionId);
        } catch {
            sessionId = generateUuid();
        }

        const body = JSON.stringify({ table: effectiveTableNumber, branch_id: effectiveBranchId, session_id: sessionId, _h: '' });
        void fetch(resolveBackendUrl(`/api/v1/public/menu/${encodeURIComponent(effectiveNit)}/scan`), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body,
            keepalive: true,
            credentials: 'omit',
        }).catch(() => {
            // Telemetría es fire-and-forget.
        });
    }, [effectiveNit, table, branchToken, effectiveBranchId, tableStatus, effectiveTableNumber]);

    // QR de menú de sede: ?branch={menu_qr_token}. Resuelve NIT + branch_id
    // desde el token opaco, sin exponer esos valores en la URL escaneada.
    useEffect(() => {
        if (typeof window === 'undefined' || !branchToken || branchResolvedRef.current) return;
        branchResolvedRef.current = true;
        let cancelled = false;
        fetch(resolveBackendUrl(`/api/v1/public/branch-resolve/${encodeURIComponent(branchToken)}`), {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(res.status === 404 ? 'qr-not-found' : 'no-branch'))))
            .then((json: { branch_exists: boolean; company_nit: string; branch_id: string }) => {
                if (cancelled || !json.branch_exists) return;
                setEffectiveNit(json.company_nit);
                setEffectiveBranchId(json.branch_id);
                try {
                    localStorage.setItem(LAST_NIT_KEY, json.company_nit);
                } catch {
                    // localStorage bloqueado en modo privado — ignorar.
                }
            })
            .catch((err: unknown) => {
                // QR de sede inválido/archivado (404): mismo criterio que el
                // resolve de mesa — no mostrar el menú stale de otra empresa.
                if (!cancelled && !nit && err instanceof Error && err.message === 'qr-not-found') {
                    setEffectiveNit(null);
                    setState({ kind: 'no-restaurant' });
                }
            });
        return () => {
            cancelled = true;
        };
    }, [branchToken, nit]);

    useEffect(() => {
        if (!effectiveNit) {
            setState({ kind: 'no-restaurant' });
            return;
        }

        let cancelled = false;
        setState({ kind: 'loading' });

        const menuUrl = resolveBackendUrl(
            effectiveBranchId
                ? `/api/v1/public/menu/${encodeURIComponent(effectiveNit)}?branch_id=${encodeURIComponent(effectiveBranchId)}`
                : `/api/v1/public/menu/${encodeURIComponent(effectiveNit)}`,
        );
        fetch(menuUrl, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        })
            .then(async (res) => {
                if (cancelled) return;

                let payload: PublicMenuPayload | null = null;
                try {
                    payload = (await res.json()) as PublicMenuPayload;
                } catch {
                    payload = null;
                }

                if (payload?.restaurant) setRestaurant(payload.restaurant);

                if (res.status === 423) {
                    setState({
                        kind: 'closed',
                        reason: payload?.restaurant_status?.reason ?? 'closed',
                        nextOpening: payload?.restaurant_status?.next_opening ?? null,
                        message: payload?.restaurant_status?.message,
                    });
                    return;
                }

                if (res.status === 404) {
                    if (!payload?.restaurant) {
                        try {
                            localStorage.removeItem(LAST_NIT_KEY);
                        } catch {
                            // ignorar
                        }
                        setEffectiveNit(null);
                        setState({ kind: 'no-restaurant' });
                        return;
                    }
                    setState({ kind: 'no-menu' });
                    return;
                }

                if (!payload?.data) {
                    setState({ kind: 'no-menu' });
                    return;
                }

                setState({ kind: 'menu', payload: payload.data });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ kind: 'error', message: 'No se pudo cargar el menú. Intenta de nuevo.' });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [effectiveNit, effectiveBranchId]);

    const commercialName = restaurant?.commercial_name ?? '';
    const displayName = restaurant?.branch_name ?? commercialName;
    const joinUrl = tableStatus ? `/t/${encodeURIComponent(tableStatus.qr_token)}` : null;
    const guestsCount = tableStatus?.active_session?.guests_count ?? 0;
    // Una sesión con 0 guests es un edge case (cookie perdida / sesión huérfana
    // tras error). La tratamos como "abierta esperando comensales" en lugar de
    // "0 personas pidiendo", que confunde al cliente.
    const sessionHasGuests = !!tableStatus?.active_session && guestsCount > 0;
    const guestsLabel = guestsCount === 1 ? '1 persona' : `${guestsCount} personas`;

    // Caso especial: no hay NIT (recarga sin sesión) — mostramos EditorialEmpty
    // centrado, como cualquier landing sin contexto.
    if (state.kind === 'no-restaurant') {
        return (
            <>
                <div className="bg-background flex min-h-svh items-center justify-center p-4 md:p-8">
                    <div className="w-full max-w-2xl">
                        <img src="/images/logo-black-font.svg" alt={appName} className="mx-auto mb-8 block h-9 w-auto md:h-10 dark:hidden" />
                        <img src="/images/logo-white-font.svg" alt={appName} className="mx-auto mb-8 hidden h-9 w-auto md:h-10 dark:block" />
                        <EditorialEmpty
                            eyebrow="Carta digital"
                            icon={<QrCode className="h-10 w-10" />}
                            title="Escanea el QR de una empresa"
                            description="Encontrarás el código en la mesa o en la entrada del local. Te llevará directo a la carta y, si la mesa lo permite, a una sesión grupal para pedir desde tu celular."
                        />
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <div className="bg-background pwa-safe-top pwa-safe-bottom min-h-svh">
                {/* HERO */}
                {restaurant?.header_image_url ? (
                    <div className="relative flex min-h-[320px] w-full flex-col overflow-hidden md:min-h-[500px]">
                        <img
                            src={restaurant.header_image_url}
                            alt=""
                            aria-hidden
                            className="absolute inset-0 h-full w-full object-cover"
                            loading="eager"
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/35 to-black/10" />

                        <div className="relative mx-auto w-full max-w-3xl px-4 pt-6 md:px-8 md:pt-10">
                            <img src="/images/logo-white-font.svg" alt={appName} className="h-9 w-auto md:h-10" />
                        </div>

                        <div className="relative mx-auto mt-auto w-full max-w-3xl px-4 pb-8 md:px-8 md:pb-12">
                            <HeroHeadline
                                eyebrow={displayName ? 'Carta digital' : 'Carta'}
                                title={
                                    displayName ? (
                                        <>
                                            La carta de <br />
                                            {displayName}
                                        </>
                                    ) : (
                                        'Carta digital'
                                    )
                                }
                                description={
                                    displayName
                                        ? `Toda la carta de ${displayName} al alcance de tu celular. Precios siempre actualizados, sin esperar.`
                                        : 'Carta de la empresa al alcance de tu celular.'
                                }
                                size="lg"
                                className="text-white"
                            />
                        </div>
                    </div>
                ) : (
                    <section className="mx-auto w-full max-w-3xl px-4 pt-6 pb-2 md:px-8 md:pt-10 md:pb-4">
                        <div className="flex flex-col gap-6 md:gap-8">
                            <div>
                                <img src="/images/logo-black-font.svg" alt={appName} className="block h-9 w-auto md:h-10 dark:hidden" />
                                <img src="/images/logo-white-font.svg" alt={appName} className="hidden h-9 w-auto md:h-10 dark:block" />
                            </div>
                            <HeroHeadline
                                eyebrow={displayName ? 'Carta digital' : 'Carta'}
                                title={
                                    displayName ? (
                                        <>
                                            La carta de <br />
                                            {displayName}
                                        </>
                                    ) : (
                                        'Carta digital'
                                    )
                                }
                                description={
                                    displayName
                                        ? `Toda la carta de ${displayName} al alcance de tu celular. Precios siempre actualizados, sin esperar.`
                                        : 'Carta de la empresa al alcance de tu celular.'
                                }
                                size="lg"
                            />
                        </div>
                    </section>
                )}

                {/* Banner: si el mesero ya tomó la cuenta, mostramos un aviso
                    en lugar del CTA de unirse — el cliente no debe duplicar el
                    pedido por QR. */}
                {tableStatus?.waiter_order_active && (
                    <div className="mx-auto w-full max-w-3xl px-4 pt-4 md:px-8">
                        <div className="flex items-center gap-3 rounded-2xl border border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 p-3">
                            <div
                                aria-hidden
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[color:var(--color-status-warning)]/20 text-[color:var(--color-status-warning)]"
                            >
                                <UtensilsCrossed className="h-4 w-4" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-foreground text-sm font-semibold">Esta mesa ya está siendo atendida</p>
                                <p className="text-muted-foreground text-xs">
                                    El mesero ya tomó la orden de la mesa {effectiveTableNumber}. Para pedir más o pagar, hablá con el mesero directamente.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Banner de sesión grupal — solo si el mesero NO tomó la orden. */}
                {joinUrl && !tableStatus?.waiter_order_active && (
                    <div className="mx-auto w-full max-w-3xl px-4 pt-4 md:px-8">
                        <div className="border-border bg-card flex items-center gap-3 rounded-2xl border p-3">
                            <div
                                aria-hidden
                                className="bg-primary text-primary-foreground flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            >
                                <Users className="h-4 w-4" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-foreground text-sm font-semibold">
                                    {sessionHasGuests
                                        ? `${guestsLabel} pidiendo desde la Mesa ${effectiveTableNumber}`
                                        : 'Pedí desde tu celular sin esperar al mesero'}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {sessionHasGuests
                                        ? 'Unite a la sesión y al final pagás solo lo tuyo.'
                                        : 'Tu pedido va directo a la cocina y queda en una cuenta separada.'}
                                </p>
                            </div>
                            <Button asChild size="sm">
                                <a href={joinUrl}>{tableStatus?.active_session ? 'Unirme' : 'Empezar'}</a>
                            </Button>
                        </div>
                    </div>
                )}

                {/* MENU: contenido denso en columna estrecha. */}
                <main className="mx-auto w-full max-w-3xl px-4 pt-6 pb-10 md:px-8">
                    {state.kind === 'loading' && <SkeletonMenu />}
                    {state.kind === 'closed' && <ClosedNotice reason={state.reason} nextOpening={state.nextOpening} message={state.message} />}
                    {state.kind === 'no-menu' && (
                        <EmptyState
                            icon={UtensilsCrossed}
                            title="Menú no disponible"
                            description="La empresa aún no publicó un menú activo. Vuelve más tarde."
                            className="bg-card border-border rounded-3xl border"
                        />
                    )}
                    {state.kind === 'error' && (
                        <EmptyState
                            icon={WifiOff}
                            title="Error de conexión"
                            description={state.message}
                            className="bg-card border-border rounded-3xl border"
                        />
                    )}
                    {state.kind === 'menu' && (
                        <MenuContent
                            structure={state.payload.structure}
                            cardStyle={restaurant?.card_style ?? 'default'}
                            onAdd={canOrder ? addToCart : undefined}
                        />
                    )}
                </main>

                {/* Imagen decorativa inferior (banner de sede) */}
                {restaurant?.footer_image_url && (
                    <div className="w-full overflow-hidden">
                        <img
                            src={restaurant.footer_image_url}
                            alt=""
                            aria-hidden
                            className="h-36 w-full object-cover"
                            loading="lazy"
                        />
                    </div>
                )}

                {(restaurant?.show_branding ?? true) && <PublicFooter appName={appName} />}
            </div>

            {/* CTA sticky permanente — visible siempre que hay URL de sesión y el mesero no tomó la orden. */}
            {joinUrl && !tableStatus?.waiter_order_active && (
                <StickyActionBar>
                    <Button className="flex w-full items-center justify-center gap-2 shadow-lg" asChild size="lg">
                        <a href={joinUrl}>
                            <Users className="h-4 w-4" />
                            {tableStatus?.active_session ? 'Unirme a la mesa' : 'Realizar pedido'}
                        </a>
                    </Button>
                </StickyActionBar>
            )}

            {/* Carrito del pedido sin mesa (QR de sede): aparece al agregar el primer plato. */}
            {canOrder && cartCount > 0 && (
                <StickyActionBar>
                    <Button className="flex w-full items-center justify-center gap-2 shadow-lg" size="lg" onClick={() => setCheckoutOpen(true)}>
                        <ShoppingBag className="h-4 w-4" />
                        Realizar pedido · {cartCount} {cartCount === 1 ? 'ítem' : 'ítems'} · {formatCurrency(cartItemsTotal)}
                    </Button>
                </StickyActionBar>
            )}

            {/* Checkout del pedido sin mesa (para llevar / domicilio). */}
            <Dialog open={checkoutOpen} onOpenChange={setCheckoutOpen}>
                <DialogContent className="max-h-[90svh] max-w-sm overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Tu pedido</DialogTitle>
                        <DialogDescription>
                            El restaurante confirmará tu pedido antes de prepararlo.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {/* Líneas del carrito con stepper */}
                        <ul className="divide-border divide-y">
                            {cartLines.map((line) => (
                                <li key={line.id} className="flex items-center gap-2 py-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-foreground truncate text-sm font-medium">{line.name}</p>
                                        <p className="text-muted-foreground text-xs">{formatCurrency(line.price)}</p>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Button size="icon" variant="outline" className="h-7 w-7" onClick={() => changeQuantity(line.id, -1)} aria-label={`Quitar uno de ${line.name}`}>
                                            <Minus className="h-3.5 w-3.5" />
                                        </Button>
                                        <span className="w-6 text-center text-sm tabular-nums">{line.quantity}</span>
                                        <Button size="icon" variant="outline" className="h-7 w-7" onClick={() => changeQuantity(line.id, 1)} aria-label={`Agregar uno de ${line.name}`}>
                                            <Plus className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {/* Tipo de pedido */}
                        <div className="grid grid-cols-2 gap-2">
                            <Button type="button" variant={orderType === 'pickup' ? 'default' : 'outline'} onClick={() => setOrderType('pickup')}>
                                Para llevar
                            </Button>
                            <Button type="button" variant={orderType === 'delivery' ? 'default' : 'outline'} onClick={() => setOrderType('delivery')}>
                                Domicilio
                            </Button>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="order-name">Tu nombre</Label>
                            <Input
                                id="order-name"
                                autoComplete="name"
                                maxLength={80}
                                value={custName}
                                onChange={(e) => setCustName(sanitizePlainText(e.target.value, 80, true, false))}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="order-phone">Celular</Label>
                            <Input
                                id="order-phone"
                                inputMode="tel"
                                autoComplete="tel"
                                placeholder="3001234567"
                                maxLength={10}
                                value={custPhone}
                                onChange={(e) => setCustPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
                            />
                        </div>

                        {orderType === 'delivery' && (
                            <>
                                <p className="text-muted-foreground text-xs">
                                    {restaurant?.branch_city
                                        ? `Solo hacemos domicilios en ${restaurant.branch_city}.`
                                        : 'Solo hacemos domicilios en la ciudad de la sede.'}
                                </p>
                                <div className="space-y-1.5">
                                    <Label htmlFor="order-address">Dirección</Label>
                                    <Input
                                        id="order-address"
                                        autoComplete="street-address"
                                        placeholder="Ej: Calle 10 # 5-23, apto 201"
                                        maxLength={160}
                                        value={custAddress}
                                        onChange={(e) => setCustAddress(sanitizePlainText(e.target.value, 160, true, false))}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="order-neighborhood">Barrio</Label>
                                    <Input
                                        id="order-neighborhood"
                                        placeholder="Ej: Centro"
                                        maxLength={80}
                                        value={custNeighborhood}
                                        onChange={(e) => setCustNeighborhood(sanitizePlainText(e.target.value, 80, true, false))}
                                    />
                                </div>
                            </>
                        )}

                        {/* Totales */}
                        <div className="border-border space-y-1 border-t pt-3 text-sm">
                            <div className="text-muted-foreground flex justify-between">
                                <span>Productos</span>
                                <span className="tabular-nums">{formatCurrency(cartItemsTotal)}</span>
                            </div>
                            {orderType === 'delivery' && (
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Envío</span>
                                    <span className="tabular-nums">{deliveryFee > 0 ? formatCurrency(deliveryFee) : 'Sin costo'}</span>
                                </div>
                            )}
                            <div className="text-foreground flex justify-between font-semibold">
                                <span>Total</span>
                                <span className="tabular-nums">{formatCurrency(cartTotal)}</span>
                            </div>
                        </div>

                        {submitError && <p className="text-destructive text-sm">{submitError}</p>}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setCheckoutOpen(false)} disabled={submitting}>
                            Seguir mirando
                        </Button>
                        <Button onClick={() => void submitOrder()} disabled={!checkoutValid || submitting}>
                            {submitting ? 'Enviando…' : 'Enviar pedido'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Confirmación: el pedido quedó esperando aprobación de la empresa. */}
            <Dialog open={placedOrder !== null} onOpenChange={(o) => !o && setPlacedOrder(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>¡Pedido enviado!</DialogTitle>
                        <DialogDescription>
                            {placedOrder?.order_type === 'delivery'
                                ? `El restaurante confirmará tu domicilio en breve. Total: ${formatCurrency(Number(placedOrder?.total ?? 0))}.`
                                : `El restaurante confirmará tu pedido en breve para que pases a recogerlo. Total: ${formatCurrency(Number(placedOrder?.total ?? 0))}.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button onClick={() => setPlacedOrder(null)}>Entendido</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={askJoin !== null} onOpenChange={(o) => !o && setAskJoin(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{sessionHasGuests ? `Tu mesa ya está pidiendo` : `¿Pedís desde la Mesa ${effectiveTableNumber}?`}</DialogTitle>
                        <DialogDescription>
                            {sessionHasGuests
                                ? `${guestsLabel === '1 persona' ? 'Hay 1 persona' : `Hay ${guestsCount} personas`} pidiendo desde la Mesa ${effectiveTableNumber}. Si te unís, tu pedido entra a la misma cuenta y al final cada uno paga lo que consumió.`
                                : 'Abrí tu pedido desde el celular sin esperar al mesero. Si llegan más personas a tu mesa, pueden sumarse al mismo pedido escaneando el mismo QR.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setAskJoin(null)}>
                            Solo ver menú
                        </Button>
                        <Button asChild>
                            <a href={joinUrl ?? '#'}>{sessionHasGuests ? 'Unirme a la mesa' : 'Empezar pedido'}</a>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function MenuContent({
    structure,
    cardStyle = 'default',
    onAdd,
}: {
    structure: MenuStructure;
    cardStyle?: 'default' | 'compact' | 'card';
    /** Si viene, cada plato muestra un botón "+" que lo agrega al carrito (pedido sin mesa). */
    onAdd?: (item: { id: string; name: string; price: number }) => void;
}) {
    const [detail, setDetail] = useState<MenuItemDetailDialogItem | null>(null);

    if (!structure?.categories?.length) {
        return (
            <EmptyState
                icon={UtensilsCrossed}
                title="Sin categorías"
                description="El menú aún no tiene productos disponibles."
                className="bg-card border-border rounded-3xl border"
            />
        );
    }

    return (
        <div className="space-y-8">
            {structure.categories.map((category) => (
                <section key={category.id} className="space-y-3">
                    <div className="border-border flex items-baseline justify-between gap-3 border-b pb-2">
                        <h2 className="text-foreground text-base font-semibold tracking-tight">{category.name}</h2>
                        {category.description && <p className="text-muted-foreground text-xs">{category.description}</p>}
                    </div>
                    {category.items.length === 0 ? (
                        <p className="text-muted-foreground px-3 py-4 text-sm">Sin productos disponibles.</p>
                    ) : (
                        <ul className="space-y-2">
                            {category.items.map((item) => {
                                const openDetail = () =>
                                    setDetail({
                                        name: item.name,
                                        description: item.description,
                                        image_url: item.image_url,
                                        thumbnail_url: (item as MenuItem & { thumbnail_url?: string | null }).thumbnail_url,
                                        price: item.price,
                                    });
                                return (
                                    <li key={item.id} onClick={openDetail} className="cursor-pointer">
                                        <MenuItemRow
                                            item={item as MenuItem & { available?: boolean; thumbnail_url?: string | null }}
                                            formatPrice={formatCurrency}
                                            variant={cardStyle}
                                            onImageClick={openDetail}
                                            action={
                                                onAdd ? (
                                                    <Button
                                                        size="icon"
                                                        className="h-8 w-8 shrink-0"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            onAdd({ id: item.id, name: item.name, price: item.price });
                                                        }}
                                                        aria-label={`Agregar ${item.name} al pedido`}
                                                    >
                                                        <Plus className="h-4 w-4" />
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="h-8 w-8 shrink-0"
                                                        onClick={(e) => { e.stopPropagation(); openDetail(); }}
                                                        aria-label={`Ver detalle de ${item.name}`}
                                                    >
                                                        <Info className="h-4 w-4" />
                                                    </Button>
                                                )
                                            }
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            ))}
            <MenuItemDetailDialog item={detail} open={detail !== null} onOpenChange={(o) => !o && setDetail(null)} formatPrice={formatCurrency} />
        </div>
    );
}

function SkeletonMenu() {
    return (
        <div className="space-y-5">
            {[0, 1, 2].map((i) => (
                <section key={i} className="bg-card border-border space-y-3 rounded-3xl border p-4">
                    <Skeleton className="h-4 w-32" />
                    <div className="divide-border divide-y">
                        {[0, 1].map((j) => (
                            <div key={j} className="flex gap-3 py-3 first:pt-0 last:pb-0">
                                <Skeleton className="size-16 shrink-0 rounded-xl" />
                                <div className="flex-1 space-y-2">
                                    <Skeleton className="h-3 w-2/3" />
                                    <Skeleton className="h-3 w-1/3" />
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

function ClosedNotice({ reason, nextOpening, message }: { reason: string; nextOpening: string | null; message?: string }) {
    let title = 'Empresa cerrada';
    let body = message ?? 'No estamos recibiendo pedidos en este momento.';

    if (reason === 'closed_hours' || reason === 'outside_business_hours') {
        title = 'Cerrado';
        if (nextOpening) {
            const dt = new Date(nextOpening);
            if (!Number.isNaN(dt.getTime())) {
                body = `Reabrimos a las ${dt.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}.`;
            }
        }
    } else if (reason === 'cash_register_closed') {
        title = 'Caja cerrada';
        body = message ?? 'La empresa no está recibiendo órdenes en este momento.';
    }

    return (
        <div className="bg-card border-border flex flex-col items-center rounded-3xl border px-6 py-12 text-center">
            <div className="mb-3 flex size-14 items-center justify-center rounded-full bg-[color:var(--color-status-warning)]/15">
                <Clock className="h-7 w-7 text-[color:var(--color-status-warning)]" />
            </div>
            <h2 className="text-foreground text-base font-semibold tracking-tight">{title}</h2>
            <p className="text-muted-foreground mt-1 text-sm">{body}</p>
        </div>
    );
}

function PublicFooter({ appName }: { appName: string }) {
    return (
        <footer className="text-muted-foreground mx-auto w-full max-w-3xl px-4 pt-2 pb-8 text-center text-xs md:px-8">
            <p className="flex items-center justify-center gap-1.5">
                <ShoppingBag className="text-primary h-3.5 w-3.5" />
                <span>Carta dinámica · {appName} mantiene los precios siempre al día</span>
            </p>
            <p className="text-muted-foreground/70 mt-1 flex items-center justify-center gap-1 text-[10px]">
                <MapPin className="h-3 w-3" />
                Si tu mesa tiene QR puedes pedir directo sin esperar al mesero.
            </p>
        </footer>
    );
}
