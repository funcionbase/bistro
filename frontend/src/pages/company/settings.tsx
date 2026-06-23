import { BillingTab } from '@/components/company-settings/billing-tab';
import { CompanyBankSection } from '@/components/company-settings/company-bank-section';
import { CompanyColorSection } from '@/components/company-settings/company-color-section';
import { CompanyFiscalSection } from '@/components/company-settings/company-fiscal-section';
import { CompanyGeneralSection } from '@/components/company-settings/company-general-section';
import { CompanyLogoSection } from '@/components/company-settings/company-logo-section';
import { CompanyQrSection } from '@/components/company-settings/company-qr-section';
import { CompanyTaxSection } from '@/components/company-settings/company-tax-section';
import { EnrollmentProofPanel } from '@/components/company-settings/enrollment-proof-panel';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCompanyBilling } from '@/hooks/use-company-billing';
import { useCompanyColor } from '@/hooks/use-company-color';
import { useCompanySettings } from '@/hooks/use-company-settings';
import { useEnrollmentProof } from '@/hooks/use-enrollment-proof';
import { useToken } from '@/hooks/use-token';
import { useSharedData } from '@/lib/shared-data';
import { Building2, ChefHat, LoaderCircle, Printer as PrinterIcon, Receipt } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

// Breadcrumb alineado con la URL: cada nivel mapea a un segmento real.
// La URL /company/settings tiene 2 segmentos → 2 niveles tras Dashboard.
// "Información"/"Facturación" son tabs internos del mismo recurso, no
// una jerarquía de URL.

export default function CompanySettings() {
    const navigate = useNavigate();
    const activeToken = useToken();
    const availableBanks = useSharedData().availableBanks ?? [];

    const settings = useCompanySettings();
    const color = useCompanyColor();
    const proof = useEnrollmentProof();
    const billing = useCompanyBilling();

    const { company, canEdit, loading, fetchError } = settings;

    if (loading) {
        return (
            <PageShell title="Mi Empresa">
                <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                    <Skeleton className="h-12 w-64" />
                    <Skeleton className="h-9 w-72" />
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <Skeleton className="h-48 w-full rounded-2xl" />
                        <Skeleton className="h-48 w-full rounded-2xl" />
                    </div>
                </div>
            </PageShell>
        );
    }

    if (fetchError) {
        return (
            <PageShell title="Mi Empresa">
                <div className="mx-auto w-full max-w-7xl p-4 sm:p-6">
                    <Alert variant="destructive">
                        <AlertDescription>{fetchError}</AlertDescription>
                    </Alert>
                </div>
            </PageShell>
        );
    }

    const currentQrSrc = settings.qrPreview ?? company?.qr_code_url ?? null;
    const currentLogoSrc = settings.logoPreview ?? company?.logo_url ?? null;

    return (
        <PageShell title="Mi Empresa">
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="MI EMPRESA"
                    title={company?.commercial_name ?? 'Mi empresa'}
                    description={company?.nit ? `NIT: ${company.nit}` : undefined}
                    variant="editorial"
                    actions={
                        <>
                            {!canEdit && <Badge variant="warning">Solo lectura</Badge>}
                            <Button variant="outline" size="sm" onClick={() => navigate('/company/printers')}>
                                <PrinterIcon className="mr-1.5 h-4 w-4" />
                                Configurar impresoras
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => navigate('/company/kds')}>
                                <ChefHat className="mr-1.5 h-4 w-4" />
                                KDS / Cocina
                            </Button>
                        </>
                    }
                />

                <Tabs defaultValue="informacion">
                    <TabsList className="w-full sm:w-auto">
                        <TabsTrigger value="informacion" className="flex-1 gap-2 sm:flex-none">
                            <Building2 className="h-4 w-4" />
                            Información
                        </TabsTrigger>
                        <TabsTrigger value="facturacion" className="flex-1 gap-2 sm:flex-none">
                            <Receipt className="h-4 w-4" />
                            Facturación
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="informacion" className="space-y-6 pt-2">
                        {settings.errors.general && (
                            <Alert variant="destructive">
                                <AlertDescription>{settings.errors.general}</AlertDescription>
                            </Alert>
                        )}
                        {settings.saved && (
                            <Alert variant="safe">
                                <AlertDescription>Información actualizada.</AlertDescription>
                            </Alert>
                        )}

                        <form noValidate onSubmit={settings.handleSubmit} className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <CompanyGeneralSection
                                nit={company?.nit ?? ''}
                                commercialName={settings.commercialName}
                                legalName={settings.legalName}
                                canEdit={canEdit}
                                errors={settings.errors}
                                onCommercialNameChange={settings.setCommercialName}
                                onLegalNameChange={settings.setLegalName}
                            />

                            <CompanyBankSection
                                availableBanks={availableBanks}
                                bankId={settings.bankId}
                                accountNumber={settings.accountNumber}
                                accountType={settings.accountType}
                                brebKey={settings.brebKey}
                                canEdit={canEdit}
                                errors={settings.errors}
                                onBankIdChange={settings.setBankId}
                                onAccountNumberChange={settings.setAccountNumber}
                                onAccountTypeChange={settings.setAccountType}
                                onBrebKeyChange={settings.setBrebKey}
                            />

                            <CompanyTaxSection
                                taxRegime={settings.taxRegime}
                                defaultTaxRate={settings.defaultTaxRate}
                                defaultTaxLabel={settings.defaultTaxLabel}
                                taxIncludedInPrice={settings.taxIncludedInPrice}
                                taxPresets={settings.taxPresets}
                                canEdit={canEdit}
                                processing={settings.processing}
                                onTaxRegimeChange={settings.setTaxRegime}
                                onDefaultTaxRateChange={settings.setDefaultTaxRate}
                                onDefaultTaxLabelChange={settings.setDefaultTaxLabel}
                                onTaxIncludedInPriceChange={settings.setTaxIncludedInPrice}
                            />

                            <CompanyLogoSection
                                currentLogoSrc={currentLogoSrc}
                                hasNewLogo={settings.logoFile !== null}
                                canEdit={canEdit}
                                isLogoDragging={settings.isLogoDragging}
                                logoInputRef={settings.logoInputRef}
                                errorMessage={settings.errors.logo}
                                onLogoFile={settings.handleLogoFile}
                                onRemoveLogo={settings.removeLogo}
                                onDraggingChange={settings.setIsLogoDragging}
                            />

                            <CompanyColorSection
                                primaryColor={color.primaryColor}
                                colorHexInput={color.colorHexInput}
                                canEdit={canEdit}
                                savingColor={color.savingColor}
                                colorSaved={color.colorSaved}
                                colorError={color.colorError}
                                isValidHex={color.isValidHex}
                                onColorPick={color.handleColorPick}
                                onHexChange={color.handleColorHexChange}
                                onSave={() => void color.handleColorSave()}
                            />

                            <CompanyQrSection
                                currentQrSrc={currentQrSrc}
                                hasNewQr={settings.qrFile !== null}
                                canEdit={canEdit}
                                isDragging={settings.isDragging}
                                fileInputRef={settings.fileInputRef}
                                errorMessage={settings.errors.qr_code}
                                onQrFile={settings.handleQrFile}
                                onRemoveQr={settings.removeQr}
                                onDraggingChange={settings.setIsDragging}
                            />

                            {canEdit && (
                                <div className="flex justify-end md:col-span-2">
                                    <Button type="submit" disabled={settings.processing} className="min-w-28">
                                        {settings.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                        Guardar cambios
                                    </Button>
                                </div>
                            )}
                        </form>

                        {/* Datos fiscales del emisor (facturación electrónica DIAN). Form
                            autocontenido con su propio guardado — antes vivía en /company/dian. */}
                        <CompanyFiscalSection />

                        {/* #154 — Prueba de pertenencia: documento de propiedad subido en el
                            enrolamiento. Solo lectura; el backend firma una URL temporal de S3
                            al abrirlo y gatea el acceso (owner o quien lo subió). */}
                        <EnrollmentProofPanel
                            proofData={proof.proofData}
                            proofLoading={proof.proofLoading}
                            proofError={proof.proofError}
                            proofOpening={proof.proofOpening}
                            onOpenProof={() => void proof.openProof()}
                        />
                    </TabsContent>

                    <TabsContent value="facturacion" className="space-y-4 pt-2">
                        <BillingTab
                            billingData={billing.billingData}
                            billingLoading={billing.billingLoading}
                            billingError={billing.billingError}
                            acceptedContract={billing.acceptedContract}
                            companyStatus={company?.status ?? ''}
                            companyNit={company?.nit}
                            token={activeToken}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </PageShell>
    );
}
