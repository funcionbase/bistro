import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

/**
 * Selector de ciudad (municipio DANE) con búsqueda server-side contra
 * `/api/v1/municipalities`, mostrando "Ciudad, Departamento". Reutilizable:
 * dirección del contacto (AddressFields), ciudad de la sede y municipio fiscal
 * DIAN. El valor es el `municipality_dane_code` (5 díg), el mismo del perfil
 * fiscal.
 */
interface MunicipalityDto {
    dane_code: string;
    label: string;
}

export function MunicipalityCombobox({
    value,
    label,
    onChange,
    id,
    disabled,
    placeholder = 'Buscá la ciudad…',
}: {
    value: string | null;
    /** Etiqueta ya conocida (evita el fetch por código al pre-llenar). */
    label?: string | null;
    onChange: (code: string | null, label: string | null) => void;
    id?: string;
    disabled?: boolean;
    placeholder?: string;
}) {
    const [options, setOptions] = useState<ComboboxOption[]>([]);
    const [loading, setLoading] = useState(false);

    // Siembra la opción seleccionada para que el combobox muestre su etiqueta.
    // Si no viene la etiqueta pero sí el código, se resuelve por `?code=`.
    useEffect(() => {
        if (!value) return;
        if (label) {
            setOptions((prev) => (prev.some((o) => o.value === value) ? prev : [{ value, label }, ...prev]));
            return;
        }
        let cancelled = false;
        void apiFetch(`/api/v1/municipalities?code=${encodeURIComponent(value)}`)
            .then((r) => (r.ok ? r.json() : null))
            .then((b: { data?: MunicipalityDto[] } | null) => {
                const m = b?.data?.[0];
                if (m && !cancelled) setOptions((prev) => (prev.some((o) => o.value === m.dane_code) ? prev : [{ value: m.dane_code, label: m.label }, ...prev]));
            });
        return () => {
            cancelled = true;
        };
    }, [value, label]);

    const search = useCallback((q: string) => {
        if (q.trim().length < 2) return;
        setLoading(true);
        void apiFetch(`/api/v1/municipalities?q=${encodeURIComponent(q.trim())}`)
            .then((r) => (r.ok ? r.json() : null))
            .then((b: { data?: MunicipalityDto[] } | null) => setOptions((b?.data ?? []).map((m) => ({ value: m.dane_code, label: m.label }))))
            .catch(() => setOptions([]))
            .finally(() => setLoading(false));
    }, []);

    return (
        <Combobox
            id={id}
            options={options}
            value={value ?? ''}
            onChange={(v) => onChange(v || null, options.find((o) => o.value === v)?.label ?? null)}
            onSearchChange={search}
            loading={loading}
            clearable
            disabled={disabled}
            placeholder={placeholder}
            searchPlaceholder="Ciudad o departamento…"
            emptyText="Escribí al menos 2 letras para buscar"
        />
    );
}
