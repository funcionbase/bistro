import { AppLink } from '@/components/app-link';
import { CouponForm } from '@/components/coupons/coupon-form';
import { CouponStatusBadge } from '@/components/coupons/coupon-status-badge';
import { CouponTypeBadge } from '@/components/coupons/coupon-type-badge';
import { PageShell } from '@/components/page-shell';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { CouponsListSkeleton } from '@/components/ui/coupons-list-skeleton';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { useCoupons } from '@/hooks/use-coupons';
import { useToken } from '@/hooks/use-token';
import { formatDate, formatDiscountValue, getCouponStatus } from '@/lib/coupon-helpers';
import { route } from '@/lib/route-compat';
import { cn } from '@/lib/utils';
import type { Coupon, CouponFormData } from '@/types/coupon';

import { AlertCircle, Check, Copy, Eye, LoaderCircle, Pencil, Plus, RefreshCw, ToggleLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';


const FILTERS = [
    { value: 'all', label: 'Todos' },
    { value: 'active', label: 'Activos' },
    { value: 'inactive', label: 'Inactivos' },
    { value: 'exhausted', label: 'Agotados' },
] as const;

type FilterValue = (typeof FILTERS)[number]['value'];

export default function CouponsIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const { coupons, loading, error, fetchCoupons, createCoupon, updateCoupon, updateCouponStatus, deleteCoupon } = useCoupons(token);

    const [showForm, setShowForm] = useState(false);
    const [editingCoupon, setEditingCoupon] = useState<Coupon | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [actionId, setActionId] = useState<string | null>(null);
    const [copied, setCopied] = useState<string | null>(null);
    const [filter, setFilter] = useState<FilterValue>('all');
    const [search, setSearch] = useState('');
    const [confirmDelete, setConfirmDelete] = useState<Coupon | null>(null);
    const [deleting, setDeleting] = useState(false);

    const filteredCoupons = coupons.filter((c) => {
        const matchesSearch = c.code.toLowerCase().includes(search.toLowerCase());
        if (filter === 'all') return matchesSearch;
        const status = getCouponStatus(c);
        return matchesSearch && status === filter;
    });

    async function handleSubmit(data: Partial<CouponFormData>) {
        setSubmitting(true);
        setFormErrors({});
        try {
            if (editingCoupon) {
                await updateCoupon(editingCoupon.id, data);
                showToast('success', `Cupón "${editingCoupon.code}" actualizado.`);
            } else {
                const created = await createCoupon(data);
                showToast('success', `Cupón "${created.code}" creado.`);
            }
            setShowForm(false);
            setEditingCoupon(null);
            await fetchCoupons();
        } catch (err: unknown) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            if (apiErr?.errors) {
                setFormErrors(apiErr.errors);
            } else {
                showToast('error', apiErr?.message ?? 'Error al guardar el cupón.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    async function handleToggleStatus(coupon: Coupon) {
        setActionId(coupon.id);
        try {
            const newStatus = coupon.status === 'active' ? 'inactive' : 'active';
            await updateCouponStatus(coupon.id, newStatus);
            await fetchCoupons();
            showToast('info', `Cupón "${coupon.code}" ${newStatus === 'active' ? 'activado' : 'desactivado'}.`);
        } catch {
            showToast('error', 'No se pudo cambiar el estado del cupón.');
        } finally {
            setActionId(null);
        }
    }

    async function doDelete() {
        if (!confirmDelete) return;
        setDeleting(true);
        const code = confirmDelete.code;
        const id = confirmDelete.id;
        setConfirmDelete(null);
        try {
            await deleteCoupon(id);
            await fetchCoupons();
            showToast('success', `Cupón "${code}" eliminado.`);
        } catch (err: unknown) {
            const apiErr = err as { message?: string };
            showToast('error', apiErr?.message ?? 'No se pudo eliminar el cupón.');
        } finally {
            setDeleting(false);
        }
    }

    function handleCopy(code: string) {
        navigator.clipboard.writeText(code);
        setCopied(code);
        setTimeout(() => setCopied(null), 2000);
    }

    function openEdit(coupon: Coupon) {
        setEditingCoupon(coupon);
        setShowForm(true);
    }

    function closeForm() {
        setShowForm(false);
        setEditingCoupon(null);
        setFormErrors({});
    }

    return (
        <PageShell title="Cupones">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading && coupons.length === 0 ? (
                    <CouponsListSkeleton rows={5} />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="Promociones"
                            title="Cupones de descuento"
                            description="Gestiona los cupones y promociones de la empresa."
                            actions={
                                <>
                                    <ExportPdfButton
                                        endpoint="/api/v1/exports/coupons/pdf"
                                        filters={{ status: filter !== 'all' ? filter : undefined, search: search || undefined }}
                                        filename={`cupones_${new Date().toISOString().slice(0, 10)}.pdf`}
                                        disabled={filteredCoupons.length === 0}
                                    />
                                    <Button variant="outline" size="sm" onClick={fetchCoupons} disabled={loading} title="Actualizar">
                                        <RefreshCw className={cn('mr-1 h-4 w-4', loading && 'animate-spin')} />
                                        Actualizar
                                    </Button>
                                    <Button onClick={() => setShowForm(true)}>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Crear cupón
                                    </Button>
                                </>
                            }
                        />

                        <FilterBar
                            searchValue={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Buscar por código…"
                        >
                            <div className="flex flex-wrap gap-2">
                                {FILTERS.map((f) => (
                                    <button
                                        key={f.value}
                                        onClick={() => setFilter(f.value)}
                                        className={cn(
                                            'focus:ring-ring rounded-md px-3 py-1.5 text-xs font-medium transition-colors focus:ring-2 focus:outline-none',
                                            filter === f.value
                                                ? 'bg-primary text-primary-foreground'
                                                : 'border-border bg-card text-muted-foreground hover:bg-muted border',
                                        )}
                                    >
                                        {f.label}
                                    </button>
                                ))}
                            </div>
                        </FilterBar>

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        <div className="bg-card overflow-hidden rounded-lg border shadow-sm">
                            {filteredCoupons.length === 0 ? (
                                <EmptyState
                                    title={search ? 'Sin coincidencias' : 'Aún no tienes cupones'}
                                    description={
                                        search
                                            ? 'Prueba con otro código o limpia el filtro.'
                                            : 'Crea tu primer cupón para empezar a aplicar descuentos en órdenes.'
                                    }
                                    action={
                                        !search ? (
                                            <Button onClick={() => setShowForm(true)} data-cta="crear-cupon" data-cta-location="coupons-empty">
                                                <Plus className="mr-2 h-4 w-4" />
                                                Crear primer cupón
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <>
                                    {/* Mobile: card-stack */}
                                    <DataCardList
                                        items={filteredCoupons}
                                        getKey={(coupon) => coupon.id}
                                        className="p-4 sm:hidden"
                                        renderCard={(coupon) => (
                                            <DataCard
                                                title={<span className="font-mono">{coupon.code}</span>}
                                                subtitle={<CouponTypeBadge type={coupon.type} />}
                                                fields={[
                                                    {
                                                        label: 'Valor',
                                                        value: <span className="font-medium tabular-nums">{formatDiscountValue(coupon)}</span>,
                                                    },
                                                    { label: 'Estado', value: <CouponStatusBadge coupon={coupon} /> },
                                                    {
                                                        label: 'Usos',
                                                        value: (
                                                            <span className="tabular-nums">
                                                                {coupon.uses_count}
                                                                {coupon.max_uses !== null ? ` / ${coupon.max_uses}` : ' / ∞'}
                                                            </span>
                                                        ),
                                                    },
                                                    { label: 'Válido hasta', value: formatDate(coupon.valid_until) },
                                                ]}
                                                footer={
                                                    <div className="flex items-center justify-end gap-1">
                                                        {actionId === coupon.id ? (
                                                            <LoaderCircle className="text-muted-foreground h-4 w-4 animate-spin" />
                                                        ) : (
                                                            <>
                                                                <AppLink
                                                                    href={route('coupons.show', { id: coupon.id })}
                                                                    className="text-muted-foreground hover:bg-muted hover:text-foreground rounded p-2 transition-colors"
                                                                    title="Ver detalle"
                                                                >
                                                                    <Eye className="h-4 w-4" />
                                                                </AppLink>
                                                                {coupon.uses_count === 0 && (
                                                                    <button
                                                                        onClick={() => openEdit(coupon)}
                                                                        className="text-muted-foreground hover:bg-muted hover:text-foreground rounded p-2 transition-colors"
                                                                        title="Editar"
                                                                    >
                                                                        <Pencil className="h-4 w-4" />
                                                                    </button>
                                                                )}
                                                                {coupon.status !== 'exhausted' && (
                                                                    <button
                                                                        onClick={() => handleToggleStatus(coupon)}
                                                                        className={cn(
                                                                            'rounded p-2 transition-colors',
                                                                            coupon.status === 'active'
                                                                                ? 'text-[color:var(--color-status-warning)] hover:bg-[color:var(--color-status-warning)]/10'
                                                                                : 'text-[color:var(--color-status-safe)] hover:bg-[color:var(--color-status-safe)]/10',
                                                                        )}
                                                                        title={coupon.status === 'active' ? 'Desactivar' : 'Activar'}
                                                                    >
                                                                        <ToggleLeft className="h-4 w-4" />
                                                                    </button>
                                                                )}
                                                                {coupon.uses_count === 0 && (
                                                                    <button
                                                                        onClick={() => setConfirmDelete(coupon)}
                                                                        className="text-destructive hover:bg-destructive/10 rounded p-2 transition-colors"
                                                                        title="Eliminar"
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                    </button>
                                                                )}
                                                            </>
                                                        )}
                                                    </div>
                                                }
                                            />
                                        )}
                                    />

                                    {/* Desktop: tabla densa */}
                                    <div className="hidden overflow-x-auto sm:block">
                                        <table className="w-full text-sm">
                                            <thead className="bg-muted/50 text-foreground text-xs uppercase">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-semibold">Código</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Tipo</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Valor</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Estado</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Usos</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Válido hasta</th>
                                                    <th className="px-4 py-3 text-left font-semibold">Programación</th>
                                                    <th className="px-4 py-3 text-right font-semibold">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {filteredCoupons.map((coupon) => (
                                                    <tr key={coupon.id} className="hover:bg-muted/40 border-t transition-colors">
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-foreground font-mono font-semibold">{coupon.code}</span>
                                                                <button
                                                                    onClick={() => handleCopy(coupon.code)}
                                                                    title="Copiar código"
                                                                    className="text-muted-foreground hover:text-foreground rounded p-0.5 transition-colors"
                                                                >
                                                                    {copied === coupon.code ? (
                                                                        <Check className="h-3.5 w-3.5 text-[color:var(--color-status-safe)]" />
                                                                    ) : (
                                                                        <Copy className="h-3.5 w-3.5" />
                                                                    )}
                                                                </button>
                                                                {copied === coupon.code && (
                                                                    <span className="animate-in fade-in-0 text-xs text-[color:var(--color-status-safe)] duration-200">
                                                                        ¡Copiado!
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <CouponTypeBadge type={coupon.type} />
                                                        </td>
                                                        <td className="px-4 py-3 font-medium tabular-nums">{formatDiscountValue(coupon)}</td>
                                                        <td className="px-4 py-3">
                                                            <CouponStatusBadge coupon={coupon} />
                                                        </td>
                                                        <td className="text-muted-foreground px-4 py-3 tabular-nums">
                                                            {coupon.uses_count}
                                                            {coupon.max_uses !== null ? ` / ${coupon.max_uses}` : ' / ∞'}
                                                        </td>
                                                        <td className="text-muted-foreground px-4 py-3">{formatDate(coupon.valid_until)}</td>
                                                        <td className="px-4 py-3">
                                                            <ScheduleSummary coupon={coupon} />
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center justify-end gap-1">
                                                                {actionId === coupon.id ? (
                                                                    <LoaderCircle className="text-muted-foreground h-4 w-4 animate-spin" />
                                                                ) : (
                                                                    <>
                                                                        <AppLink
                                                                            href={route('coupons.show', { id: coupon.id })}
                                                                            className="text-muted-foreground hover:bg-muted hover:text-foreground rounded p-1.5 transition-colors"
                                                                            title="Ver detalle"
                                                                        >
                                                                            <Eye className="h-4 w-4" />
                                                                        </AppLink>
                                                                        {coupon.uses_count === 0 && (
                                                                            <button
                                                                                onClick={() => openEdit(coupon)}
                                                                                className="text-muted-foreground hover:bg-muted hover:text-foreground rounded p-1.5 transition-colors"
                                                                                title="Editar"
                                                                            >
                                                                                <Pencil className="h-4 w-4" />
                                                                            </button>
                                                                        )}
                                                                        {coupon.status !== 'exhausted' && (
                                                                            <button
                                                                                onClick={() => handleToggleStatus(coupon)}
                                                                                className={cn(
                                                                                    'rounded p-1.5 transition-colors',
                                                                                    coupon.status === 'active'
                                                                                        ? 'text-[color:var(--color-status-warning)] hover:bg-[color:var(--color-status-warning)]/10'
                                                                                        : 'text-[color:var(--color-status-safe)] hover:bg-[color:var(--color-status-safe)]/10',
                                                                                )}
                                                                                title={coupon.status === 'active' ? 'Desactivar' : 'Activar'}
                                                                            >
                                                                                <ToggleLeft className="h-4 w-4" />
                                                                            </button>
                                                                        )}
                                                                        {coupon.uses_count === 0 && (
                                                                            <button
                                                                                onClick={() => setConfirmDelete(coupon)}
                                                                                className="text-destructive hover:bg-destructive/10 rounded p-1.5 transition-colors"
                                                                                title="Eliminar"
                                                                            >
                                                                                <Trash2 className="h-4 w-4" />
                                                                            </button>
                                                                        )}
                                                                    </>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}
                        </div>
                    </>
                )}
            </div>

            {showForm && (
                <CouponForm coupon={editingCoupon} onSubmit={handleSubmit} onCancel={closeForm} submitting={submitting} errors={formErrors} />
            )}

            <ConfirmDialog
                open={confirmDelete !== null}
                title="Eliminar cupón"
                message={`¿Eliminar el cupón "${confirmDelete?.code}"? Esta acción no se puede deshacer.`}
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={doDelete}
                onCancel={() => setConfirmDelete(null)}
            />
        </PageShell>
    );
}

const DAY_ABBR = ['D', 'L', 'M', 'X', 'J', 'V', 'S'];

function ScheduleSummary({ coupon }: { coupon: Coupon }) {
    const hasDays = Array.isArray(coupon.valid_days) && coupon.valid_days.length > 0;
    const hasHours = !!coupon.valid_hours_from && !!coupon.valid_hours_to;
    if (!hasDays && !hasHours && !coupon.auto_apply) {
        return <span className="text-muted-foreground/50">—</span>;
    }
    const daysLabel = hasDays
        ? [...coupon.valid_days!]
              .sort((a, b) => a - b)
              .map((d) => DAY_ABBR[d])
              .join('·')
        : 'Todos';
    const hoursLabel = hasHours ? `${coupon.valid_hours_from!.slice(0, 5)}–${coupon.valid_hours_to!.slice(0, 5)}` : '24h';
    return (
        <div className="flex flex-col text-xs">
            <span className="text-foreground font-medium">
                {daysLabel} · {hoursLabel}
            </span>
            {coupon.auto_apply && (
                <span className="mt-0.5 inline-flex w-fit items-center rounded bg-[color:var(--color-status-safe)]/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-[color:var(--color-status-safe)] uppercase">
                    Auto
                </span>
            )}
        </div>
    );
}
