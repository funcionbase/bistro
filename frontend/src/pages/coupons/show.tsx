import { AppLink } from '@/components/app-link';
import { CouponForm } from '@/components/coupons/coupon-form';
import { CouponStatusBadge } from '@/components/coupons/coupon-status-badge';
import { CouponTypeBadge } from '@/components/coupons/coupon-type-badge';
import { RedemptionHistoryTable } from '@/components/coupons/redemption-history-table';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { CouponDetailSkeleton } from '@/components/ui/coupon-detail-skeleton';
import { useToast } from '@/components/ui/toast';
import { useCoupons } from '@/hooks/use-coupons';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { formatCurrency, formatDate, formatDiscountValue } from '@/lib/coupon-helpers';
import { route } from '@/lib/route-compat';
import type { Coupon, CouponFormData, CouponRedemption, CouponStatus, PaginatedResponse } from '@/types/coupon';

import { AlertCircle, ArrowLeft, Calendar, CheckCircle, Hash, LoaderCircle, Pencil, ToggleLeft, Trash2, Users } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';


export default function CouponsShow() {
    const id = window.location.pathname.split('/').pop() ?? '';
    const navigate = useNavigate();
    const token = useToken();
    const { showToast } = useToast();
    const { updateCoupon, updateCouponStatus, deleteCoupon, fetchCouponRedemptions } = useCoupons(token);

    const [coupon, setCoupon] = useState<Coupon | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const [redemptions, setRedemptions] = useState<CouponRedemption[]>([]);
    const [redemptionPage, setRedemptionPage] = useState(1);
    const [redemptionMeta, setRedemptionMeta] = useState<Pick<PaginatedResponse<CouponRedemption>, 'current_page' | 'last_page' | 'total'>>({
        current_page: 1,
        last_page: 1,
        total: 0,
    });
    const [redemptionsLoading, setRedemptionsLoading] = useState(false);

    const [showEditForm, setShowEditForm] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [actionId, setActionId] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const fetchCoupon = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch(`/api/v1/coupons/${id}`);
            const data = await res.json();
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar cupón.');
                return;
            }
            setCoupon(data.data);
        } catch {
            setError('Error de conexión.');
        } finally {
            setLoading(false);
        }
    }, [token, id]);

    const loadRedemptions = useCallback(
        async (page: number) => {
            if (!token || !coupon) return;
            setRedemptionsLoading(true);
            try {
                const result = await fetchCouponRedemptions(id, page);
                setRedemptions(result.data);
                setRedemptionMeta({ current_page: result.current_page, last_page: result.last_page, total: result.total });
            } catch {
                // leave previous state on error
            } finally {
                setRedemptionsLoading(false);
            }
        },
        [token, coupon, id, fetchCouponRedemptions],
    );

    useEffect(() => {
        fetchCoupon();
    }, [fetchCoupon]);
    useEffect(() => {
        if (coupon) loadRedemptions(redemptionPage);
    }, [coupon, redemptionPage]); // eslint-disable-line react-hooks/exhaustive-deps

    async function handleEdit(data: Partial<CouponFormData>) {
        if (!coupon) return;
        setSubmitting(true);
        setFormErrors({});
        try {
            const updated = await updateCoupon(coupon.id, data);
            setCoupon(updated);
            setShowEditForm(false);
            showToast('success', `Cupón "${updated.code}" actualizado.`);
        } catch (err: unknown) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            if (apiErr?.errors) {
                setFormErrors(apiErr.errors);
            } else {
                showToast('error', apiErr?.message ?? 'Error al actualizar el cupón.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    async function handleToggleStatus() {
        if (!coupon) return;
        const newStatus: CouponStatus = coupon.status === 'active' ? 'inactive' : 'active';
        setActionId('status');
        try {
            await updateCouponStatus(coupon.id, newStatus);
            setCoupon((prev) => (prev ? { ...prev, status: newStatus } : prev));
            showToast('info', `Cupón ${newStatus === 'active' ? 'activado' : 'desactivado'}.`);
        } catch {
            showToast('error', 'No se pudo cambiar el estado del cupón.');
        } finally {
            setActionId(null);
        }
    }

    async function doDelete() {
        if (!coupon) return;
        setDeleting(true);
        setConfirmDelete(false);
        const code = coupon.code;
        try {
            await deleteCoupon(coupon.id);
            showToast('success', `Cupón "${code}" eliminado.`);
            navigate(route('coupons'));
        } catch (err: unknown) {
            const apiErr = err as { message?: string };
            showToast('error', apiErr?.message ?? 'No se pudo eliminar el cupón.');
            setDeleting(false);
        }
    }

    return (
        <PageShell title={coupon ? `Cupón ${coupon.code}` : 'Detalle de cupón'}>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading ? (
                    <CouponDetailSkeleton />
                ) : (
                    <>
                        {/* Header */}
                        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center md:justify-between">
                            <div className="flex items-center gap-3">
                                <AppLink href={route('coupons')}>
                                    <Button variant="ghost" size="icon" title="Volver a cupones" className="shrink-0">
                                        <ArrowLeft className="h-4 w-4" />
                                    </Button>
                                </AppLink>
                                <h1 className="text-foreground truncate text-2xl font-semibold tracking-tight md:text-3xl">
                                    {coupon ? `Cupón ${coupon.code}` : 'Cupón no encontrado'}
                                </h1>
                            </div>

                            {coupon && (
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center md:shrink-0 [&>*]:w-full sm:[&>*]:w-auto">
                                    {coupon.uses_count === 0 && (
                                        <Button variant="outline" size="sm" onClick={() => setShowEditForm(true)}>
                                            <Pencil className="mr-1.5 h-4 w-4" />
                                            Editar
                                        </Button>
                                    )}
                                    {coupon.status !== 'exhausted' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={handleToggleStatus}
                                            disabled={actionId === 'status'}
                                            className={
                                                coupon.status === 'active'
                                                    ? 'text-[color:var(--color-status-warning)] hover:text-[color:var(--color-status-warning)]'
                                                    : 'text-[color:var(--color-status-safe)] hover:text-[color:var(--color-status-safe)]'
                                            }
                                        >
                                            {actionId === 'status' ? (
                                                <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />
                                            ) : (
                                                <ToggleLeft className="mr-1.5 h-4 w-4" />
                                            )}
                                            {coupon.status === 'active' ? 'Desactivar' : 'Activar'}
                                        </Button>
                                    )}
                                    {coupon.uses_count === 0 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setConfirmDelete(true)}
                                            disabled={deleting}
                                            className="text-destructive hover:text-destructive"
                                        >
                                            {deleting ? (
                                                <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />
                                            ) : (
                                                <Trash2 className="mr-1.5 h-4 w-4" />
                                            )}
                                            Eliminar
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {coupon ? (
                            <div className="grid gap-6 lg:grid-cols-3">
                                {/* Info Card */}
                                <div className="lg:col-span-1">
                                    <div className="bg-card space-y-5 rounded-lg border p-6 shadow-sm">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Código</p>
                                                <p className="text-foreground mt-1 font-mono text-2xl font-bold">{coupon.code}</p>
                                            </div>
                                            <CouponStatusBadge coupon={coupon} />
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <CouponTypeBadge type={coupon.type} />
                                            <span className="text-primary text-lg font-bold tabular-nums">{formatDiscountValue(coupon)}</span>
                                        </div>

                                        <div className="space-y-3 border-t pt-4">
                                            <InfoRow
                                                icon={<Hash className="h-4 w-4" />}
                                                label="Usos"
                                                value={`${coupon.uses_count} / ${coupon.max_uses ?? '∞'}`}
                                            />
                                            <InfoRow
                                                icon={<Calendar className="h-4 w-4" />}
                                                label="Válido desde"
                                                value={formatDate(coupon.valid_from)}
                                            />
                                            <InfoRow
                                                icon={<Calendar className="h-4 w-4" />}
                                                label="Válido hasta"
                                                value={formatDate(coupon.valid_until)}
                                            />
                                            <InfoRow
                                                icon={<CheckCircle className="h-4 w-4" />}
                                                label="Monto mínimo"
                                                value={coupon.min_order_amount > 0 ? formatCurrency(coupon.min_order_amount) : 'Sin mínimo'}
                                            />
                                            <InfoRow
                                                icon={<Users className="h-4 w-4" />}
                                                label="Solo primer pedido"
                                                value={coupon.first_order_only ? 'Sí' : 'No'}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* Redemptions Table */}
                                <div className="lg:col-span-2">
                                    <RedemptionHistoryTable
                                        redemptions={redemptions}
                                        loading={redemptionsLoading}
                                        page={redemptionMeta.current_page}
                                        totalPages={redemptionMeta.last_page}
                                        total={redemptionMeta.total}
                                        onPageChange={setRedemptionPage}
                                    />
                                </div>
                            </div>
                        ) : null}
                    </>
                )}
            </div>

            {showEditForm && coupon && (
                <CouponForm
                    coupon={coupon}
                    onSubmit={handleEdit}
                    onCancel={() => {
                        setShowEditForm(false);
                        setFormErrors({});
                    }}
                    submitting={submitting}
                    errors={formErrors}
                />
            )}

            <ConfirmDialog
                open={confirmDelete}
                title="Eliminar cupón"
                message={`¿Eliminar el cupón "${coupon?.code}"? Esta acción no se puede deshacer.`}
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={doDelete}
                onCancel={() => setConfirmDelete(false)}
            />
        </PageShell>
    );
}

function InfoRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="flex items-center gap-2">
            <span className="text-muted-foreground/50">{icon}</span>
            <span className="text-muted-foreground text-xs">{label}:</span>
            <span className="text-foreground text-xs font-medium">{value}</span>
        </div>
    );
}
