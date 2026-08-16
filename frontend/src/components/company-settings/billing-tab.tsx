import { ActivePromoCodeCard } from '@/components/billing/active-promo-code-card';
import DianUsageCard from '@/components/billing/dian-usage-card';
import { funcionbasePaymentInfo } from '@/components/billing/bistro-payment-info';
import InvoiceList from '@/components/billing/invoice-list';
import OverdueBanner from '@/components/billing/overdue-banner';
import { PromoCodeEnrollForm } from '@/components/billing/promo-code-enroll-form';
import SubscriptionCard from '@/components/billing/subscription-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import Markdown from '@/components/ui/markdown';
import { Skeleton } from '@/components/ui/skeleton';
import { useBootstrap } from '@/hooks/use-bootstrap';
import type { AcceptedContract } from '@/hooks/use-company-billing';
import { useCompanyPromoCode } from '@/hooks/use-company-promo-code';
import { type BillingSubscriptionData } from '@/types';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '@/lib/datetime';

interface BillingTabProps {
    billingData: BillingSubscriptionData | null;
    billingLoading: boolean;
    billingError: string | null;
    acceptedContract: AcceptedContract | null;
    /** Estado de la empresa (para el OverdueBanner). */
    companyStatus: string;
    /** NIT de la empresa (para listar facturas). */
    companyNit: string | null | undefined;
    /** Token activo (requerido por InvoiceList). */
    token: string | null;
}

/**
 * Tab "Facturación" de `company/settings.tsx`: banner de mora, tarjeta de
 * suscripción, listado de facturas y el contrato de servicio aceptado.
 * Comportamiento idéntico al que vivía inline en la página.
 */
export function BillingTab({
    billingData,
    billingLoading,
    billingError,
    acceptedContract,
    companyStatus,
    companyNit,
    token,
}: BillingTabProps) {
    const [contractDialogOpen, setContractDialogOpen] = useState(false);

    // Promo code self-service. owner/admin pueden aplicar/cancelar;
    // los demás roles ven el promo activo si existe pero sin form de inscripción.
    const bootstrap = useBootstrap().data;
    const roleName = bootstrap?.role?.name ?? '';
    const canManagePromo = bootstrap?.role?.is_system === true && (roleName === 'Propietario' || roleName === 'Administrador');
    const promo = useCompanyPromoCode(true);

    // Datos para transferir a bistro (visible siempre, no solo en mora).
    const funcionbasePayment = bootstrap?.activeCompany?.funcionbase_payment ?? null;

    return (
        <>
            {billingLoading ? (
                <div className="space-y-4">
                    <Skeleton className="h-28 w-full rounded-2xl" />
                    <Skeleton className="h-48 w-full rounded-2xl" />
                </div>
            ) : billingError ? (
                <Alert variant="destructive">
                    <AlertDescription>{billingError}</AlertDescription>
                </Alert>
            ) : (
                <>
                    {billingData && (
                        <OverdueBanner
                            companyStatus={companyStatus}
                            overdueTotal={billingData.overdue_total}
                            earliestOverdueDate={billingData.earliest_overdue_date}
                        />
                    )}
                    <SubscriptionCard subscription={billingData?.subscription ?? null} />
                    {billingData?.dian_usage && <DianUsageCard usage={billingData.dian_usage} />}
                    {/* Datos para transferir a bistro (visible siempre). */}
                    {funcionbasePayment !== null && <funcionbasePaymentInfo payment={funcionbasePayment} />}
                    {/* Promo code activo o form para inscribir uno nuevo. */}
                    {!promo.loading && promo.active !== null && (
                        <ActivePromoCodeCard active={promo.active} canCancel={canManagePromo} onCancel={promo.cancel} />
                    )}
                    {!promo.loading && promo.active === null && canManagePromo && (
                        <PromoCodeEnrollForm onPreview={promo.preview} onApply={promo.apply} />
                    )}
                    {token && companyNit && <InvoiceList token={token} companyNit={companyNit} />}
                </>
            )}

            {/* Contrato de servicio aceptado. Snapshot inmutable
                del contrato firmado por el owner al crear la empresa. Vive
                en el tab Facturación porque agrupa todo lo relacionado con
                la relación contractual (suscripción, facturas, contrato). */}
            <DashboardPanel title="Contrato de servicio aceptado" icon={FileText}>
                {acceptedContract ? (
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <Badge variant="secondary" className="font-mono text-xs">
                                v{acceptedContract.version}
                            </Badge>
                            <span className="text-muted-foreground text-sm">
                                Aceptado el{' '}
                                {acceptedContract.accepted_at
                                    ? formatDateTime(acceptedContract.accepted_at, {
                                          year: 'numeric',
                                          month: 'long',
                                          day: 'numeric',
                                      })
                                    : '—'}
                            </span>
                            {acceptedContract.accepter_name && (
                                <span className="text-muted-foreground text-sm">
                                    por <span className="text-foreground font-medium">{acceptedContract.accepter_name}</span>
                                </span>
                            )}
                        </div>

                        {acceptedContract.latest_published && (
                            <Alert variant="warning">
                                <FileText className="h-4 w-4" />
                                <AlertDescription className="space-y-0.5">
                                    <p className="font-medium">
                                        Hay una versión más reciente del contrato (v{acceptedContract.latest_published.version})
                                        {acceptedContract.latest_published.published_at && (
                                            <>
                                                {' '}
                                                publicada el{' '}
                                                {formatDateTime(acceptedContract.latest_published.published_at, {
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric',
                                                })}
                                            </>
                                        )}
                                        .
                                    </p>
                                    <p className="text-xs">
                                        El contrato vigente para tu empresa sigue siendo la versión que aceptaste; la actualización es
                                        solo informativa.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        <Dialog open={contractDialogOpen} onOpenChange={setContractDialogOpen}>
                            <DialogTrigger asChild>
                                <Button type="button" variant="outline" size="sm" className="gap-2">
                                    <FileText className="h-4 w-4" />
                                    Ver contrato completo
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[80vh] max-w-3xl overflow-hidden">
                                <DialogHeader>
                                    <DialogTitle>Contrato de servicio · v{acceptedContract.version}</DialogTitle>
                                    <DialogDescription>
                                        Snapshot inmutable aceptado el{' '}
                                        {acceptedContract.accepted_at
                                            ? formatDateTime(acceptedContract.accepted_at, {
                                                  year: 'numeric',
                                                  month: 'long',
                                                  day: 'numeric',
                                              })
                                            : '—'}
                                        {acceptedContract.accepter_name && (
                                            <>
                                                {' '}
                                                por <span className="font-medium">{acceptedContract.accepter_name}</span>
                                            </>
                                        )}
                                        .
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="max-h-[60vh] overflow-y-auto pr-2">
                                    <Markdown content={acceptedContract.content} />
                                </div>
                            </DialogContent>
                        </Dialog>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No se encontró un contrato de servicio aceptado para esta empresa. Si crees que esto es un error, contacta
                        soporte.
                    </p>
                )}
            </DashboardPanel>
        </>
    );
}
