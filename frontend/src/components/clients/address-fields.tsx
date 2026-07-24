import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';

/**
 * Dirección estructurada del contacto, reutilizable en el diálogo de cliente
 * (/clients) y en el editor de contacto (/chats).
 *
 * Ciudad = combobox con búsqueda server-side contra el catálogo DANE
 * (`/api/v1/municipalities?q=`), mostrando "Ciudad, Departamento" — el
 * departamento va concatenado, no en un campo aparte. El `municipality_dane_code`
 * es el mismo código del perfil fiscal DIAN. Barrio y dirección son texto libre.
 */
export interface AddressValue {
    municipality_dane_code: string | null;
    municipality_label?: string | null;
    neighborhood: string | null;
    address: string | null;
}

interface MunicipalityDto {
    dane_code: string;
    label: string;
}

export function AddressFields({ value, onChange, idPrefix = 'addr' }: { value: AddressValue; onChange: (v: AddressValue) => void; idPrefix?: string }) {
    const [options, setOptions] = useState<ComboboxOption[]>([]);
    const [loading, setLoading] = useState(false);

    // Al editar un contacto que ya tiene ciudad, sembramos la opción para que el
    // combobox muestre su etiqueta sin necesidad de buscar. Si no viene la
    // etiqueta pero sí el código, la resolvemos por `?code=`.
    useEffect(() => {
        const code = value.municipality_dane_code;
        if (!code) return;
        if (value.municipality_label) {
            setOptions((prev) => (prev.some((o) => o.value === code) ? prev : [{ value: code, label: value.municipality_label! }, ...prev]));
            return;
        }
        let cancelled = false;
        void apiFetch(`/api/v1/municipalities?code=${encodeURIComponent(code)}`)
            .then((r) => (r.ok ? r.json() : null))
            .then((b: { data?: MunicipalityDto[] } | null) => {
                const m = b?.data?.[0];
                if (m && !cancelled) setOptions((prev) => (prev.some((o) => o.value === m.dane_code) ? prev : [{ value: m.dane_code, label: m.label }, ...prev]));
            });
        return () => {
            cancelled = true;
        };
    }, [value.municipality_dane_code, value.municipality_label]);

    const search = useCallback((q: string) => {
        if (q.trim().length < 2) return;
        setLoading(true);
        void apiFetch(`/api/v1/municipalities?q=${encodeURIComponent(q.trim())}`)
            .then((r) => (r.ok ? r.json() : null))
            .then((b: { data?: MunicipalityDto[] } | null) => {
                setOptions((b?.data ?? []).map((m) => ({ value: m.dane_code, label: m.label })));
            })
            .catch(() => setOptions([]))
            .finally(() => setLoading(false));
    }, []);

    return (
        <div className="space-y-3">
            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}-city`}>Ciudad</Label>
                <Combobox
                    id={`${idPrefix}-city`}
                    options={options}
                    value={value.municipality_dane_code ?? ''}
                    onChange={(v) => {
                        const opt = options.find((o) => o.value === v);
                        onChange({ ...value, municipality_dane_code: v || null, municipality_label: opt?.label ?? null });
                    }}
                    onSearchChange={search}
                    loading={loading}
                    clearable
                    placeholder="Buscá tu ciudad…"
                    searchPlaceholder="Ciudad o departamento…"
                    emptyText="Escribí al menos 2 letras para buscar"
                />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}-hood`}>Barrio</Label>
                    <Input
                        id={`${idPrefix}-hood`}
                        value={value.neighborhood ?? ''}
                        onChange={(e) => onChange({ ...value, neighborhood: e.target.value || null })}
                        placeholder="Ej. El Poblado"
                        maxLength={120}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}-addr`}>Dirección</Label>
                    <Input
                        id={`${idPrefix}-addr`}
                        value={value.address ?? ''}
                        onChange={(e) => onChange({ ...value, address: e.target.value || null })}
                        placeholder="Calle 12 # 3-45, apto 201"
                        maxLength={200}
                    />
                </div>
            </div>
        </div>
    );
}
