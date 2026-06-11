import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';

interface UseCompanyColorReturn {
    primaryColor: string;
    setPrimaryColor: (v: string) => void;
    colorHexInput: string;
    setColorHexInput: (v: string) => void;
    savingColor: boolean;
    colorSaved: boolean;
    colorError: string | null;
    setColorError: (v: string | null) => void;
    isValidHex: (value: string) => boolean;
    handleColorPick: (value: string) => void;
    handleColorHexChange: (value: string) => void;
    handleColorSave: () => Promise<void>;
}

const DEFAULT_COLOR = '#FF6B35';

const isValidHex = (value: string) => /^#[0-9A-Fa-f]{6}$/.test(value);

/**
 * Maneja el color principal del menú público: carga el valor actual,
 * valida el hexadecimal y persiste en `/api/v1/companies/settings`. El
 * comportamiento y los endpoints son idénticos a los de la página
 * `company/settings.tsx`.
 */
export function useCompanyColor(): UseCompanyColorReturn {
    const activeToken = useToken();

    const [primaryColor, setPrimaryColor] = useState(DEFAULT_COLOR);
    const [colorHexInput, setColorHexInput] = useState(DEFAULT_COLOR);
    const [savingColor, setSavingColor] = useState(false);
    const [colorSaved, setColorSaved] = useState(false);
    const [colorError, setColorError] = useState<string | null>(null);

    // Espera a que el token esté sincronizado antes de hacer fetch
    const [tokenReady, setTokenReady] = useState(false);

    useEffect(() => {
        // Espera un ciclo para que useToken() lo detecte
        setTimeout(() => setTokenReady(true), 0);
    }, [activeToken]);

    useEffect(() => {
        if (!activeToken) return;
        if (!tokenReady) return;

        let isMounted = true;

        const urlToken = new URLSearchParams(window.location.search).get('token');
        const extraHeaders: Record<string, string> = {};
        if (urlToken) {
            extraHeaders['Authorization'] = `Bearer ${urlToken}`;
        }

        apiFetch('/api/v1/companies/settings/menu_primary_color', { headers: extraHeaders })
            .then((res) => res.json())
            .then((data) => {
                if (!isMounted) return;
                if (data.value) {
                    setPrimaryColor(data.value);
                    setColorHexInput(data.value);
                }
            })
            .catch(() => {});

        return () => {
            isMounted = false;
        };
    }, [activeToken, tokenReady]);

    // El color picker nativo siempre devuelve un hex válido, así que aplica
    // el valor a ambos inputs y limpia el error de inmediato.
    const handleColorPick = (value: string) => {
        setPrimaryColor(value);
        setColorHexInput(value);
        setColorError(null);
    };

    const handleColorHexChange = (value: string) => {
        setColorHexInput(value);
        if (isValidHex(value)) {
            setPrimaryColor(value);
            setColorError(null);
        }
    };

    const handleColorSave = async () => {
        if (!isValidHex(primaryColor)) {
            setColorError('El color debe ser un valor hexadecimal válido (ej: #FF6B35).');
            return;
        }
        setColorError(null);
        setSavingColor(true);
        try {
            const urlToken = new URLSearchParams(window.location.search).get('token');
            const headers: Record<string, string> = { 'Content-Type': 'application/json' };
            if (urlToken) headers['Authorization'] = `Bearer ${urlToken}`;
            const response = await apiFetch('/api/v1/companies/settings', {
                method: 'PATCH',
                headers,
                body: JSON.stringify({ settings: { menu_primary_color: primaryColor } }),
            });
            const data = await response.json();
            if (!response.ok) {
                setColorError(data.errors?.['settings.menu_primary_color']?.[0] ?? data.message ?? 'Error al guardar el color.');
                return;
            }
            setColorHexInput(primaryColor);
            setColorSaved(true);
            setTimeout(() => setColorSaved(false), 4000);
        } catch {
            setColorError('Error de conexión. Intenta de nuevo.');
        } finally {
            setSavingColor(false);
        }
    };

    return {
        primaryColor,
        setPrimaryColor,
        colorHexInput,
        setColorHexInput,
        savingColor,
        colorSaved,
        colorError,
        setColorError,
        isValidHex,
        handleColorPick,
        handleColorHexChange,
        handleColorSave,
    };
}
