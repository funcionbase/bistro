import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { reloadContext } from '@/lib/navigate-compat';
import { setToken } from '@/lib/token';
import { type CompanyDetail } from '@/types';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

export interface CompanyFieldErrors {
    commercial_name?: string;
    legal_name?: string;
    bank_id?: string;
    account_number?: string;
    account_type?: string;
    breb_key?: string;
    qr_code?: string;
    logo?: string;
    general?: string;
}

export type TaxPresets = Record<string, { rate: number; label: string }>;

interface UseCompanySettingsReturn {
    company: CompanyDetail | null;
    canEdit: boolean;
    loading: boolean;
    fetchError: string | null;

    commercialName: string;
    setCommercialName: (v: string) => void;
    legalName: string;
    setLegalName: (v: string) => void;
    bankId: string;
    setBankId: (v: string) => void;
    accountNumber: string;
    setAccountNumber: (v: string) => void;
    accountType: string;
    setAccountType: (v: string) => void;
    brebKey: string;
    setBrebKey: (v: string) => void;

    taxRegime: string;
    setTaxRegime: (v: string) => void;
    defaultTaxRate: string;
    setDefaultTaxRate: (v: string) => void;
    defaultTaxLabel: string;
    setDefaultTaxLabel: (v: string) => void;
    taxIncludedInPrice: boolean;
    setTaxIncludedInPrice: (v: boolean) => void;
    taxPresets: TaxPresets;

    errors: CompanyFieldErrors;
    setErrors: React.Dispatch<React.SetStateAction<CompanyFieldErrors>>;
    processing: boolean;
    saved: boolean;

    qrFile: File | null;
    qrPreview: string | null;
    isDragging: boolean;
    setIsDragging: (v: boolean) => void;
    fileInputRef: React.RefObject<HTMLInputElement | null>;
    handleQrFile: (file: File) => void;
    removeQr: () => void;

    logoFile: File | null;
    logoPreview: string | null;
    isLogoDragging: boolean;
    setIsLogoDragging: (v: boolean) => void;
    logoInputRef: React.RefObject<HTMLInputElement | null>;
    handleLogoFile: (file: File) => void;
    removeLogo: () => void;

    handleSubmit: FormEventHandler;
}

/**
 * Carga la empresa, mantiene el estado del formulario de "Mi Empresa" y
 * gestiona el submit (incluye subida de QR y logo). El comportamiento, los
 * endpoints y los efectos son idénticos a los que vivían inline en la
 * página `company/settings.tsx`.
 */
export function useCompanySettings(): UseCompanySettingsReturn {
    const activeToken = useToken();

    const [company, setCompany] = useState<CompanyDetail | null>(null);
    // canEdit viene del backend (FeaturePermissionService.company.update),
    // no del nombre del rol — así un rol personalizado con el permiso
    // explícito puede editar.
    const [canEdit, setCanEdit] = useState<boolean>(false);
    const [loading, setLoading] = useState(true);
    const [fetchError, setFetchError] = useState<string | null>(null);

    const [commercialName, setCommercialName] = useState('');
    const [legalName, setLegalName] = useState('');
    const [bankId, setBankId] = useState('');
    const [accountNumber, setAccountNumber] = useState('');
    // El select muestra 'Ahorros'/'Corriente', pero la BD usa 'ahorros'/'corriente'
    const [accountType, setAccountType] = useState('');
    const [brebKey, setBrebKey] = useState('');
    // Configuración tributaria
    const [taxRegime, setTaxRegime] = useState<string>('simple');
    const [defaultTaxRate, setDefaultTaxRate] = useState<string>('0');
    const [defaultTaxLabel, setDefaultTaxLabel] = useState<string>('Sin IVA');
    const [taxIncludedInPrice, setTaxIncludedInPrice] = useState<boolean>(true);
    const [taxPresets, setTaxPresets] = useState<TaxPresets>({});

    const [qrFile, setQrFile] = useState<File | null>(null);
    const [qrPreview, setQrPreview] = useState<string | null>(null);
    const [isDragging, setIsDragging] = useState(false);

    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [isLogoDragging, setIsLogoDragging] = useState(false);
    const logoInputRef = useRef<HTMLInputElement>(null);

    const [errors, setErrors] = useState<CompanyFieldErrors>({});
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);

    // Espera a que el token esté sincronizado antes de hacer fetch
    const [tokenReady, setTokenReady] = useState(false);

    useEffect(() => {
        // Espera un ciclo para que useToken() lo detecte (el ?token= legacy
        // ya lo consumió spa/main.tsx vía setToken()).
        setTimeout(() => setTokenReady(true), 0);
    }, [activeToken]);

    useEffect(() => {
        if (!activeToken) {
            setLoading(false);
            setFetchError('No hay sesión activa. Vuelve a iniciar sesión.');
            return;
        }

        if (!tokenReady) return;

        let isMounted = true;

        apiFetch('/api/v1/company')
            .then((res) => res.json())
            .then((data) => {
                if (!isMounted) return;
                if (data.company) {
                    const c: CompanyDetail = data.company;
                    setCompany(c);
                    setCanEdit(Boolean(data.can_update));
                    setCommercialName(c.commercial_name ?? '');
                    setLegalName(c.legal_name ?? '');
                    setBankId(c.bank_id?.toString() ?? '');
                    setAccountNumber(c.account_number ?? '');
                    // Mapear valor de BD a label del select
                    let typeLabel = '';
                    if ((c.account_type ?? '').toLowerCase() === 'ahorros') typeLabel = 'Ahorros';
                    else if ((c.account_type ?? '').toLowerCase() === 'corriente') typeLabel = 'Corriente';
                    setAccountType(typeLabel);
                    setBrebKey(c.breb_key ?? '');
                    setTaxRegime(c.tax_regime ?? 'simple');
                    setDefaultTaxRate(((c.default_tax_rate ?? 0) as number).toString());
                    setDefaultTaxLabel(c.default_tax_label ?? 'Sin IVA');
                    setTaxIncludedInPrice(c.tax_included_in_price ?? true);
                    if (data.tax_presets && typeof data.tax_presets === 'object') {
                        setTaxPresets(data.tax_presets);
                    }
                } else {
                    setFetchError('No se pudo cargar la información de la empresa.');
                }
            })
            .catch(() => {
                if (!isMounted) return;
                setFetchError('Error de conexión al cargar la empresa.');
            })
            .finally(() => {
                if (!isMounted) return;
                setLoading(false);
            });

        // Configuración de empresa cambia rara vez — eliminado polling continuo;
        // si el operador necesita ver cambios externos, recarga la pagina.
        return () => {
            isMounted = false;
        };
    }, [activeToken, tokenReady]);

    function handleLogoFile(file: File) {
        if (!file.type.match(/^image\/(png|jpe?g|webp|svg\+xml)$/)) {
            setErrors((prev) => ({ ...prev, logo: 'El archivo debe ser PNG, JPG, WEBP o SVG.' }));
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            setErrors((prev) => ({ ...prev, logo: 'La imagen no puede superar 5 MB.' }));
            return;
        }
        setErrors((prev) => ({ ...prev, logo: undefined }));
        setLogoFile(file);
        setLogoPreview(URL.createObjectURL(file));
    }

    function removeLogo() {
        setLogoFile(null);
        if (logoPreview && logoPreview.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview);
        }
        setLogoPreview(null);
        if (logoInputRef.current) logoInputRef.current.value = '';
    }

    function handleQrFile(file: File) {
        if (!file.type.match(/^image\/(png|jpe?g)$/)) {
            setErrors((prev) => ({ ...prev, qr_code: 'El archivo debe ser una imagen PNG o JPG.' }));
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            setErrors((prev) => ({ ...prev, qr_code: 'La imagen no puede superar 5 MB.' }));
            return;
        }
        setErrors((prev) => ({ ...prev, qr_code: undefined }));
        setQrFile(file);
        setQrPreview(URL.createObjectURL(file));
    }

    function removeQr() {
        setQrFile(null);
        if (qrPreview && qrPreview.startsWith('blob:')) {
            URL.revokeObjectURL(qrPreview);
        }
        setQrPreview(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    }

    const handleSubmit: FormEventHandler = async (e) => {
        e.preventDefault();
        if (!canEdit) return;

        setErrors({});
        setSaved(false);
        setProcessing(true);

        try {
            const formData = new FormData();
            formData.append('commercial_name', commercialName.trim());
            formData.append('legal_name', legalName.trim());
            formData.append('bank_id', bankId);
            formData.append('account_number', accountNumber.trim());
            // Mapear label del select a valor esperado por backend
            let typeValue = '';
            if (accountType === 'Ahorros') typeValue = 'ahorros';
            else if (accountType === 'Corriente') typeValue = 'corriente';
            formData.append('account_type', typeValue);
            formData.append('breb_key', brebKey.trim());
            formData.append('tax_regime', taxRegime);
            formData.append('default_tax_rate', defaultTaxRate || '0');
            formData.append('default_tax_label', defaultTaxLabel.trim() || 'Sin IVA');
            formData.append('tax_included_in_price', taxIncludedInPrice ? '1' : '0');
            if (qrFile) formData.append('qr_code', qrFile);
            if (logoFile) formData.append('logo', logoFile);

            // Obtiene el token de la URL si existe
            const headers: Record<string, string> = {};
            const response = await apiFetch('/api/v1/company', {
                method: 'POST',
                body: formData,
                headers,
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    const fieldErrors: CompanyFieldErrors = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        fieldErrors[field as keyof CompanyFieldErrors] = messages[0];
                    }
                    setErrors(fieldErrors);
                } else {
                    setErrors({ general: data.message ?? 'Ocurrió un error. Intenta de nuevo.' });
                }
                return;
            }

            setCompany(data.company);
            setQrFile(null);
            if (qrPreview && qrPreview.startsWith('blob:')) URL.revokeObjectURL(qrPreview);
            setQrPreview(null);
            setLogoFile(null);
            if (logoPreview && logoPreview.startsWith('blob:')) URL.revokeObjectURL(logoPreview);
            setLogoPreview(null);
            setSaved(true);
            setTimeout(() => setSaved(false), 4000);

            // Aplicar el JWT reemitido (con commercial_name actualizado en active_company_name)
            // y refrescar las shared props para que el sidebar muestre el nombre nuevo.
            if (data.token) {
                setToken(data.token);
                reloadContext();
            }
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

    return {
        company,
        canEdit,
        loading,
        fetchError,
        commercialName,
        setCommercialName,
        legalName,
        setLegalName,
        bankId,
        setBankId,
        accountNumber,
        setAccountNumber,
        accountType,
        setAccountType,
        brebKey,
        setBrebKey,
        taxRegime,
        setTaxRegime,
        defaultTaxRate,
        setDefaultTaxRate,
        defaultTaxLabel,
        setDefaultTaxLabel,
        taxIncludedInPrice,
        setTaxIncludedInPrice,
        taxPresets,
        errors,
        setErrors,
        processing,
        saved,
        qrFile,
        qrPreview,
        isDragging,
        setIsDragging,
        fileInputRef,
        handleQrFile,
        removeQr,
        logoFile,
        logoPreview,
        isLogoDragging,
        setIsLogoDragging,
        logoInputRef,
        handleLogoFile,
        removeLogo,
        handleSubmit,
    };
}
