import MenuItemDetailDialog, { type MenuItemDetailDialogItem } from '@/components/menu/menu-item-detail-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { BottomSheet } from '@/components/ui/bottom-sheet';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { MenuItemRow, MenuItemRowSkeleton } from '@/components/ui/menu-item-row';
import { NotesEditor } from '@/components/ui/notes-editor';
import { OrderItemCard, OrderItemCardSkeleton, type OrderItemStatus } from '@/components/ui/order-item-card';
import { QtyStepper } from '@/components/ui/qty-stepper';
import { Skeleton } from '@/components/ui/skeleton';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { ApiError, apiClient } from '@/lib/api-client';
import { cn } from '@/lib/utils';
import { CheckCircle2, Clock, MapPin, QrCode, ShoppingBag, UtensilsCrossed } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { formatCurrency } from '@/lib/formatters';

const TERMINAL_SESSION_STATUSES = ['closed', 'expired'] as const;

interface TableMenuContext {
    qrToken: string;
    nit: string;
    table: { number: string; capacity: number };
    branch: { id: string; name: string; city: string | null };
    company: {
        name: string;
        logo_url: string | null;
    };
    session: {
        id: string;
        status: 'open' | 'locked' | 'closed' | 'expired';
        opened_at: string | null;
        expires_at: string | null;
    };
    guest: {
        id: string;
        display_name: string;
        phone_masked: string;
        joined_at: string | null;
    };
}

/** Respuesta de `GET /api/v1/public/table/{qrToken}/menu`. */
interface TableMenuContextResponse {
    data: TableMenuContext;
}

interface PublicMenuItem {
    id: string;
    name: string;
    price: number;
    description?: string | null;
    image_url?: string | null;
    available?: boolean;
}

interface PublicMenuCategory {
    id: string;
    name: string;
    items?: PublicMenuItem[];
}

interface PublicMenuPayload {
    data: {
        id: string;
        company_nit: string;
        name: string;
        description: string | null;
        structure: { categories?: PublicMenuCategory[] };
    } | null;
}

interface CartItem {
    id: string;
    menu_item_id: string;
    name: string;
    quantity: number;
    unit_price: string;
    notes: string | null;
    status: OrderItemStatus;
    cancellation_reason: string | null;
    submitted_at: string | null;
}

interface GroupNote {
    id: string;
    scope: 'group' | 'kitchen_alert';
    body: string;
    created_at: string | null;
    author_label: string | null;
}

interface PendingCancellation {
    id: string;
    order_item_id: string;
    status: string;
    reason: string | null;
}

interface SessionGuest {
    id: string;
    display_name: string;
    phone: string;
    joined_at: string | null;
    is_self: boolean;
}

interface TableState {
    session: { id: string; status: string; expires_at: string | null };
    order: { id: string; status: string; total: string } | null;
    current_guest_id: string;
    guests: SessionGuest[];
    my_items: CartItem[];
    group_notes: GroupNote[];
    pending_cancellations: PendingCancellation[];
}

type LoadState =
    | { kind: 'loading' }
    | { kind: 'ready'; context: TableMenuContext }
    | { kind: 'error'; message: string };

const QUICK_NOTES = ['sin cebolla', 'sin sal', 'bien cocido', 'sin picante'] as const;

/**
 * Pantalla del menú del comensal (#191).
 *
 * Migrada a SPA standalone (#191): el `qrToken` se lee de la URL con
 * `useParams` y el contexto del comensal (sesión, sede, identidad) se trae
 * con fetch a `GET /api/v1/public/table/{qrToken}/menu`. Si la cookie `tdt_*`
 * no identifica al comensal, el endpoint responde 401 y la página redirige al
 * formulario de unión.
 *
 * El catálogo viene de `/api/v1/public/menu/{nit}`. El carrito propio del
 * comensal se opera contra `/api/v1/public/table/{qrToken}/{state,items,
 * submit,notes}`. Polling cada 5s mientras la sesión esté activa para
 * reflejar aprobaciones del mesero.
 */
export default function TableMenuPage() {
    const { qrToken } = useParams<{ qrToken: string }>();
    const navigate = useNavigate();
    const [state, setState] = useState<LoadState>({ kind: 'loading' });

    useEffect(() => {
        if (!qrToken) {
            setState({ kind: 'error', message: 'El enlace de la mesa no es válido. Vuelve a escanear el QR.' });
            return;
        }

        let cancelled = false;
        const controller = new AbortController();

        async function load(token: string) {
            try {
                const json = await apiClient.get<TableMenuContextResponse>(
                    `/api/v1/public/table/${encodeURIComponent(token)}/menu`,
                    { signal: controller.signal },
                );
                if (cancelled) {
                    return;
                }
                setState({ kind: 'ready', context: json.data });
            } catch (err) {
                if (cancelled || (err instanceof DOMException && err.name === 'AbortError')) {
                    return;
                }
                // 401 → no hay cookie de comensal: volver al formulario de unión.
                if (err instanceof ApiError && err.status === 401) {
                    navigate(`/t/${encodeURIComponent(token)}`, { replace: true });
                    return;
                }
                const message =
                    err instanceof ApiError && err.status === 404
                        ? 'No encontramos esta mesa. Verifica el QR o pídele ayuda a un mesero.'
                        : 'No pudimos cargar la mesa. Revisa tu conexión e intenta de nuevo.';
                setState({ kind: 'error', message });
            }
        }

        void load(qrToken);

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [qrToken, navigate]);

    if (state.kind === 'loading') {
        return (
            <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-4 p-6" aria-busy="true" aria-label="Cargando mesa">
                <Skeleton className="size-12 rounded-full" />
                <Skeleton className="h-4 w-40" />
                <Skeleton className="h-3 w-56" />
            </div>
        );
    }

    if (state.kind === 'error') {
        return (
            <div className="bg-background flex min-h-svh items-center justify-center p-4 md:p-8">
                <div className="w-full max-w-2xl">
                    <EditorialEmpty
                        eyebrow="Mesa con QR"
                        icon={<QrCode className="size-10" aria-hidden="true" />}
                        title="No pudimos abrir el menú"
                        description={state.message}
                    />
                </div>
            </div>
        );
    }

    return <TableMenuView context={state.context} />;
}

/**
 * Vista del menú propiamente dicha — recibe el contexto ya resuelto.
 *
 * Reglas de cliente:
 *  - Precio jamás se envía al backend (lo lee del menu activo en DB).
 *  - Edición permitida solo si `status=pending_approval`.
 *  - Cancelar pending_approval → cancela inmediato. Cancelar approved → crea
 *    `CancellationRequest`. in_kitchen+ → error claro de backend ("Pídele al
 *    mesero").
 */
function TableMenuView({ context }: { context: TableMenuContext }) {
    const { qrToken, nit, table, branch, company, session, guest } = context;
    const navigate = useNavigate();

    const [menu, setMenu] = useState<PublicMenuPayload['data']>(null);
    const [menuLoading, setMenuLoading] = useState(true);
    const [menuError, setMenuError] = useState<string | null>(null);

    const [state, setState] = useState<TableState | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [cartOpen, setCartOpen] = useState(false);
    const [groupNoteOpen, setGroupNoteOpen] = useState(false);
    const [groupNoteBody, setGroupNoteBody] = useState('');

    const [editingItem, setEditingItem] = useState<CartItem | null>(null);
    const [editNotes, setEditNotes] = useState('');
    const [editQty, setEditQty] = useState(1);

    const [confirmCancel, setConfirmCancel] = useState<CartItem | null>(null);
    const [detailItem, setDetailItem] = useState<MenuItemDetailDialogItem | null>(null);

    // Endpoints REST del carrito del comensal (#191 SPA): viven bajo el
    // prefijo público de la API, identidad por cookie `tdt_*`.
    const apiBase = `/api/v1/public/table/${encodeURIComponent(qrToken)}`;
    const stateUrl = `${apiBase}/state`;
    const itemsUrl = `${apiBase}/items`;
    const submitUrl = `${apiBase}/submit`;
    const notesUrl = `${apiBase}/notes`;

    const refreshState = useCallback(async () => {
        try {
            // `apiClient` resuelve la base de la API (`VITE_API_URL`) y envía la
            // cookie `tdt_*` cross-origin (`credentials: include`). Raw axios no
            // adjuntaría la cookie en el deploy cross-origin same-site.
            const data = await apiClient.get<TableState>(stateUrl);
            setState(data);
        } catch {
            // Errores de red intermitentes se silencian; el polling vuelve a probar.
        }
    }, [stateUrl]);

    useEffect(() => {
        void refreshState();
    }, [refreshState]);

    // Estado vivo de la sesión: el poll actualiza esto; la UI reacciona al expirar.
    const liveSessionStatus = state?.session?.status ?? session.status;
    const isTerminal = TERMINAL_SESSION_STATUSES.includes(liveSessionStatus as (typeof TERMINAL_SESSION_STATUSES)[number]);

    // Polling automático cada 5s mientras la sesión esté activa.
    const pollingRef = useRef<number | null>(null);
    useEffect(() => {
        if (session.status === 'closed' || session.status === 'expired') return;
        pollingRef.current = window.setInterval(() => void refreshState(), 5000);
        return () => {
            if (pollingRef.current !== null) window.clearInterval(pollingRef.current);
        };
    }, [refreshState, session.status]);

    // Cuando el poll detecta sesión terminal, detener el intervalo y redirigir:
    //  - closed → join page para abrir una nueva sesión en la misma mesa.
    //  - expired → menú público (la sesión caducó por inactividad; el cliente
    //    puede volver a unirse escaneando el QR de nuevo).
    useEffect(() => {
        if (!isTerminal) return;
        if (pollingRef.current !== null) {
            window.clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
        if (liveSessionStatus === 'closed') {
            navigate(`/t/${encodeURIComponent(qrToken)}`, { replace: true });
        } else if (liveSessionStatus === 'expired') {
            navigate(`/menus/${encodeURIComponent(nit)}?table=${encodeURIComponent(table.number)}`, { replace: true });
        }
    }, [isTerminal, liveSessionStatus, navigate, nit, table.number, qrToken]);

    // Cargar el catálogo público del menú.
    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();
        async function load() {
            try {
                // Pasamos branch_id para que el endpoint filtre el menú activo
                // de esta sede (una empresa puede tener un menú activo por
                // sede). Sin este filtro, podría devolver el menú de otra sede
                // — y mostrar items / fotos que no aplican.
                const json = await apiClient.get<PublicMenuPayload>(
                    `/api/v1/public/menu/${encodeURIComponent(nit)}`,
                    { params: { branch_id: branch.id }, signal: controller.signal },
                );
                if (cancelled) return;
                setMenu(json.data);
            } catch (err) {
                if (cancelled || (err instanceof Error && err.name === 'AbortError')) return;
                setMenuError(err instanceof Error ? err.message : 'No pudimos cargar el menú.');
            } finally {
                if (!cancelled) setMenuLoading(false);
            }
        }
        void load();
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [nit, branch.id]);

    // --- Acciones ---

    const handleApiError = (err: unknown) => {
        if (err instanceof ApiError) {
            setActionError(err.message || 'No pudimos procesar tu acción. Intenta de nuevo.');
        } else {
            setActionError('No pudimos procesar tu acción.');
        }
    };

    const addItem = async (menuItemId: string, name: string) => {
        setBusy(true);
        setActionError(null);
        try {
            await apiClient.post(itemsUrl, { menu_item_id: menuItemId, quantity: 1, notes: null });
            await refreshState();
            // Snackbar simple: dejamos el cartOpen tal cual, el comensal ve el counter incrementarse.
            void name;
        } catch (err) {
            handleApiError(err);
        } finally {
            setBusy(false);
        }
    };

    const persistEdit = async () => {
        if (!editingItem) return;
        setBusy(true);
        setActionError(null);
        try {
            await apiClient.patch(`${itemsUrl}/${editingItem.id}`, {
                notes: editNotes,
                quantity: editQty,
            });
            await refreshState();
            setEditingItem(null);
        } catch (err) {
            handleApiError(err);
        } finally {
            setBusy(false);
        }
    };

    const cancelItem = async (item: CartItem) => {
        setBusy(true);
        setActionError(null);
        try {
            await apiClient.delete(`${itemsUrl}/${item.id}`);
            await refreshState();
            setConfirmCancel(null);
        } catch (err) {
            handleApiError(err);
        } finally {
            setBusy(false);
        }
    };

    const submitBatch = async () => {
        setBusy(true);
        setActionError(null);
        try {
            await apiClient.post(submitUrl);
            await refreshState();
        } catch (err) {
            handleApiError(err);
        } finally {
            setBusy(false);
        }
    };

    const addGroupNote = async () => {
        if (groupNoteBody.trim() === '') return;
        setBusy(true);
        setActionError(null);
        try {
            await apiClient.post(notesUrl, { scope: 'group', body: groupNoteBody });
            await refreshState();
            setGroupNoteOpen(false);
            setGroupNoteBody('');
        } catch (err) {
            handleApiError(err);
        } finally {
            setBusy(false);
        }
    };

    // --- Derivados ---

    const cancellationsByItem = useMemo(() => {
        const map: Record<string, boolean> = {};
        (state?.pending_cancellations ?? []).forEach((c) => {
            map[c.order_item_id] = true;
        });
        return map;
    }, [state]);

    const itemCounts = useMemo(() => {
        const items = state?.my_items ?? [];
        const active = items.filter((i) => i.status !== 'cancelled');
        const total = active.reduce((acc, i) => acc + Number.parseFloat(i.unit_price) * i.quantity, 0);
        return { active: active.length, total };
    }, [state]);

    const sessionClosed = isTerminal;

    return (
        <div className="bg-background flex min-h-svh flex-col pb-28">
            <header className="border-border bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-20 border-b px-4 pt-[max(1rem,env(safe-area-inset-top,0px))] pb-3 backdrop-blur-md sm:pt-[max(1.5rem,env(safe-area-inset-top,0px))] sm:pb-4">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-3 sm:gap-4">
                    <div className="flex items-center gap-3">
                        {company.logo_url ? (
                            <div className="border-border bg-card h-10 w-10 shrink-0 overflow-hidden rounded-full border sm:h-12 sm:w-12" aria-hidden>
                                <img
                                    src={company.logo_url}
                                    alt={company.name}
                                    className="h-full w-full object-cover p-1"
                                    loading="eager"
                                    decoding="async"
                                />
                            </div>
                        ) : (
                            <div className="bg-muted text-muted-foreground flex h-10 w-10 shrink-0 items-center justify-center rounded-full sm:h-12 sm:w-12">
                                <UtensilsCrossed className="h-5 w-5 sm:h-6 sm:w-6" />
                            </div>
                        )}
                        <div className="min-w-0 flex-1">
                            <h1
                                className="font-brand text-foreground truncate text-base font-semibold tracking-tight sm:text-lg"
                                title={company.name}
                            >
                                {company.name}
                            </h1>
                            <p className="text-muted-foreground flex items-center gap-1 text-xs">
                                <MapPin className="h-3 w-3 shrink-0" aria-hidden />
                                <span className="truncate">
                                    {branch.name}
                                    {branch.city ? ` · ${branch.city}` : ''}
                                </span>
                            </p>
                        </div>
                        <Badge variant="secondary" className="shrink-0 tabular-nums">
                            Mesa {table.number}
                        </Badge>
                    </div>

                    {session.status === 'locked' && (
                        <div className="border-border bg-muted/40 flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs">
                            <Clock className="text-muted-foreground h-3 w-3 shrink-0" aria-hidden />
                            <span className="text-muted-foreground">Mesa en curso</span>
                        </div>
                    )}

                    {state === null && !sessionClosed ? <GuestListSkeleton /> : <GuestList guests={state?.guests ?? []} />}
                </div>
            </header>

            <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-6">
                {sessionClosed && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            La sesión de esta mesa ya está cerrada. Si quieres ordenar de nuevo, pídele al mesero que abra una nueva.
                        </AlertDescription>
                    </Alert>
                )}

                {actionError && (
                    <Alert variant="destructive">
                        <AlertDescription>{actionError}</AlertDescription>
                    </Alert>
                )}

                {menuError && (
                    <Alert variant="destructive">
                        <AlertDescription>{menuError}</AlertDescription>
                    </Alert>
                )}

                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-foreground text-sm font-semibold">Menú</h2>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setGroupNoteOpen(true)}
                            disabled={sessionClosed}
                            className="text-xs"
                        >
                            Nota para la mesa
                        </Button>
                    </div>
                    {menuLoading ? (
                        <div className="space-y-8" aria-busy="true" aria-label="Cargando menú">
                            {[0, 1].map((catIdx) => (
                                <section key={catIdx} className="space-y-3">
                                    <div className="border-border border-b pb-2">
                                        <Skeleton className="h-5 w-32" />
                                    </div>
                                    <ul className="space-y-2">
                                        {[0, 1, 2].map((itemIdx) => (
                                            <li key={itemIdx}>
                                                <MenuItemRowSkeleton />
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    ) : menu ? (
                        <div className="space-y-8">
                            {(menu.structure.categories ?? []).map((category) => (
                                <section key={category.id} className="space-y-3">
                                    <div className="border-border border-b pb-2">
                                        <h3 className="text-foreground text-base font-semibold tracking-tight">{category.name}</h3>
                                    </div>
                                    <ul className="space-y-2">
                                        {(category.items ?? [])
                                            .filter((item) => item.available !== false)
                                            .map((item) => (
                                                <li key={item.id}>
                                                    <MenuItemRow
                                                        item={item}
                                                        formatPrice={formatCurrency}
                                                        onImageClick={() =>
                                                            setDetailItem({
                                                                name: item.name,
                                                                description: item.description,
                                                                image_url: item.image_url,
                                                                price: item.price,
                                                            })
                                                        }
                                                        action={
                                                            <Button
                                                                type="button"
                                                                size="default"
                                                                onClick={() => void addItem(item.id, item.name)}
                                                                disabled={busy || sessionClosed}
                                                            >
                                                                Agregar
                                                            </Button>
                                                        }
                                                    />
                                                </li>
                                            ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-center text-sm">El menú no está disponible.</p>
                    )}
                </section>

                {(state?.group_notes ?? []).length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-foreground text-sm font-semibold">Notas de la mesa</h2>
                        <ul className="space-y-1.5">
                            {(state?.group_notes ?? []).map((note) => (
                                <li key={note.id} className="border-border bg-muted/50 rounded-lg border px-3 py-2 text-sm">
                                    <div className="mb-0.5 flex items-center gap-2">
                                        <span className="text-muted-foreground text-[10px] font-semibold tracking-[0.18em] uppercase">
                                            {note.scope === 'kitchen_alert' ? 'Cocina' : 'Grupo'}
                                        </span>
                                        {note.author_label && <span className="text-muted-foreground text-[11px]">· {note.author_label}</span>}
                                    </div>
                                    <p className="text-foreground">{note.body}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </main>

            <StickyActionBar>
                {state === null ? (
                    <div
                        className="bg-primary/80 flex h-12 w-full animate-pulse items-center justify-between rounded-md px-4 shadow-lg"
                        aria-busy="true"
                        aria-label="Cargando tu pedido"
                    >
                        <span className="flex items-center gap-2">
                            <ShoppingBag className="text-primary-foreground/60 h-4 w-4" aria-hidden />
                            <span className="bg-primary-foreground/20 h-3 w-20 rounded" />
                        </span>
                        <span className="bg-primary-foreground/20 h-3 w-16 rounded" />
                    </div>
                ) : (
                    <Button
                        type="button"
                        size="lg"
                        className="flex w-full items-center justify-between shadow-lg"
                        onClick={() => setCartOpen(true)}
                        disabled={busy}
                    >
                        <span className="flex items-center gap-2">
                            <ShoppingBag className="h-4 w-4" aria-hidden />
                            {itemCounts.active > 0 ? `${itemCounts.active} ${itemCounts.active === 1 ? 'plato' : 'platos'}` : 'Tu pedido'}
                        </span>
                        <span className="tabular-nums">{formatCurrency(itemCounts.total)}</span>
                    </Button>
                )}
            </StickyActionBar>

            <BottomSheet isOpen={cartOpen} onClose={() => setCartOpen(false)} title={`Mi pedido — ${guest.display_name}`}>
                <div className="space-y-3">
                    {state === null ? (
                        <ul className="space-y-2" aria-busy="true" aria-label="Cargando pedido">
                            {[0, 1].map((i) => (
                                <li key={i}>
                                    <OrderItemCardSkeleton />
                                </li>
                            ))}
                        </ul>
                    ) : (state?.my_items ?? []).length === 0 ? (
                        <p className="text-muted-foreground text-center text-sm">Todavía no agregaste nada. Toca "Agregar" en el menú.</p>
                    ) : (
                        <ul className="space-y-2">
                            {(state?.my_items ?? []).map((item) => (
                                <li key={item.id}>
                                    <OrderItemCard
                                        id={item.id}
                                        name={item.name}
                                        quantity={item.quantity}
                                        unitPriceFormatted={formatCurrency(Number.parseFloat(item.unit_price))}
                                        subtotalFormatted={formatCurrency(Number.parseFloat(item.unit_price) * item.quantity)}
                                        notes={item.notes}
                                        status={item.status}
                                        cancellationReason={item.cancellation_reason}
                                        isSubmitted={!!item.submitted_at}
                                        hasPendingCancellation={!!cancellationsByItem[item.id]}
                                        onEdit={() => {
                                            setEditingItem(item);
                                            setEditNotes(item.notes ?? '');
                                            setEditQty(item.quantity);
                                        }}
                                        onCancel={() => setConfirmCancel(item)}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {state?.order && (
                        <div className="border-border flex items-center justify-between border-t pt-3">
                            <span className="text-foreground text-sm font-semibold">Total estimado</span>
                            <span className="text-foreground text-lg font-semibold tabular-nums">
                                {formatCurrency(Number.parseFloat(state.order.total))}
                            </span>
                        </div>
                    )}

                    <Button
                        type="button"
                        size="lg"
                        className="w-full"
                        onClick={() => void submitBatch()}
                        disabled={busy || sessionClosed || !(state?.my_items ?? []).some((i) => i.status === 'pending_approval' && !i.submitted_at)}
                    >
                        <ShoppingBag className="mr-2 h-4 w-4" /> Enviar al mesero
                    </Button>

                    {(state?.my_items ?? []).some((i) => i.submitted_at && i.status === 'pending_approval') && (
                        <p className="text-muted-foreground flex items-center gap-1 text-center text-xs">
                            <CheckCircle2 className="h-3 w-3" />
                            Esperando aprobación del mesero…
                        </p>
                    )}
                </div>
            </BottomSheet>

            <BottomSheet isOpen={!!editingItem} onClose={() => setEditingItem(null)} title={editingItem ? `Editar ${editingItem.name}` : ''}>
                {editingItem && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-sm font-medium">Cantidad</span>
                            <QtyStepper value={editQty} onChange={setEditQty} min={1} max={99} disabled={busy} />
                        </div>
                        <NotesEditor id="edit-notes" value={editNotes} onChange={setEditNotes} quickActions={QUICK_NOTES} label="Notas para cocina" />
                        <Button type="button" className="w-full" onClick={() => void persistEdit()} disabled={busy}>
                            Guardar cambios
                        </Button>
                    </div>
                )}
            </BottomSheet>

            <BottomSheet isOpen={groupNoteOpen} onClose={() => setGroupNoteOpen(false)} title="Nota para toda la mesa">
                <div className="space-y-4">
                    <NotesEditor
                        id="group-note"
                        value={groupNoteBody}
                        onChange={setGroupNoteBody}
                        label="¿Algo para todo el grupo?"
                        placeholder="Ej: traer todas las entradas juntas, alergias, etc."
                    />
                    <Button type="button" className="w-full" onClick={() => void addGroupNote()} disabled={busy || groupNoteBody.trim() === ''}>
                        Guardar nota
                    </Button>
                </div>
            </BottomSheet>

            <ConfirmDialog
                open={!!confirmCancel}
                title="¿Cancelar este plato?"
                message={
                    confirmCancel?.status === 'pending_approval'
                        ? 'Lo quitamos de tu pedido al instante.'
                        : 'Le enviamos una solicitud al mesero para que confirme.'
                }
                confirmLabel="Sí, cancelar"
                cancelLabel="No, volver"
                onConfirm={() => confirmCancel && void cancelItem(confirmCancel)}
                onCancel={() => setConfirmCancel(null)}
                loading={busy}
            />

            <MenuItemDetailDialog
                item={detailItem}
                open={detailItem !== null}
                onOpenChange={(o) => !o && setDetailItem(null)}
                formatPrice={formatCurrency}
            />
        </div>
    );
}

interface GuestListProps {
    guests: SessionGuest[];
}

/**
 * Listado de comensales activos en la mesa. Se refresca con el polling de
 * `/state`, así que al unirse un nuevo guest aparece en la siguiente tanda
 * (5s). El comensal actual se destaca con borde lima y eyebrow "Vos".
 *
 * Todos ven el mismo nivel de detalle (nombre + teléfono) — la idea es que
 * la mesa se reconozca entre sí y no queden teléfonos enmascarados solo
 * para los demás.
 */
function GuestList({ guests }: GuestListProps) {
    if (guests.length === 0) return null;

    return (
        <section className="space-y-2">
            <div className="flex items-center justify-between">
                <h2 className="text-foreground text-sm font-semibold">
                    En la mesa
                    <span className="text-muted-foreground ml-2 font-normal tabular-nums">· {guests.length}</span>
                </h2>
            </div>
            <ul className="space-y-2">
                {guests.map((g) => (
                    <li key={g.id}>
                        <GuestRow guest={g} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Esqueleto de `GuestList` para mostrar mientras el primer poll de `/state`
 * está en vuelo. Una fila ya destacada (la del comensal actual, que sabemos
 * que está) + una fila genérica de placeholder por si hay otros. Evita el
 * "popping" donde aparece de golpe la lista al recibir state.
 */
function GuestListSkeleton() {
    return (
        <section className="space-y-2" aria-busy="true" aria-label="Cargando mesa">
            <Skeleton className="h-4 w-24" />
            <ul className="space-y-2">
                {[0, 1].map((i) => (
                    <li key={i} className="border-border bg-card flex items-center gap-3 rounded-2xl border p-3">
                        <Skeleton className="size-10 shrink-0 rounded-full" />
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <Skeleton className="h-3.5 w-32" />
                            <Skeleton className="h-3 w-24" />
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function GuestRow({ guest }: { guest: SessionGuest }) {
    const initials =
        guest.display_name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || '·';

    return (
        <div
            className={cn(
                'flex items-center gap-3 rounded-2xl border p-3 transition-colors',
                guest.is_self ? 'border-accent bg-accent/15' : 'border-border bg-card',
            )}
        >
            <div
                aria-hidden
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                    guest.is_self ? 'bg-accent text-accent-foreground' : 'bg-muted text-foreground',
                )}
            >
                {initials}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-baseline gap-2">
                    <p className="text-foreground truncate text-sm font-semibold">{guest.display_name}</p>
                    {guest.is_self && <span className="text-foreground/70 text-[10px] font-semibold tracking-[0.18em] uppercase">Vos</span>}
                </div>
                <p className="text-muted-foreground truncate text-xs tabular-nums">{formatPhone(guest.phone)}</p>
            </div>
        </div>
    );
}

function formatPhone(raw: string): string {
    if (raw.length === 10) {
        return `${raw.slice(0, 3)} ${raw.slice(3, 6)} ${raw.slice(6)}`;
    }
    return raw;
}

