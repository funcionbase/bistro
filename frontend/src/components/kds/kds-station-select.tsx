import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apiFetch } from '@/lib/api';
import type { KdsStation } from '@/types';
import { useEffect, useState } from 'react';

interface KdsStationSelectProps {
    /** Id de la estación seleccionada. null = usar fallback (estación is_default de la sede). */
    value: string | null;
    onChange: (stationId: string | null) => void;
    disabled?: boolean;
    /** Id para asociar con `<Label htmlFor>`. */
    id?: string;
    /**
     * Texto cuando no hay estación elegida. Por defecto refleja el comportamiento
     * de fallback (#115): los items caen en la estación default de la sede.
     */
    fallbackLabel?: string;
}

/**
 * Selector reutilizable de estaciones KDS de la sede activa (#115).
 *
 * Carga la lista vía `GET /api/v1/kds/stations` al montarse. El valor `null`
 * representa "sin asignar" — los items de la categoría caerán en la estación
 * `is_default=true` de la sede como fallback (regla de mapping definida en
 * `bistro/backend/constants/KDS_STATIONS.md`).
 *
 * Reutilizable desde el modal de categoría del menú, la página de settings KDS
 * y cualquier otro contexto que necesite vincular algo a una estación.
 */
export function KdsStationSelect({ value, onChange, disabled = false, id, fallbackLabel = 'Predeterminada (fallback)' }: KdsStationSelectProps) {
    const [stations, setStations] = useState<KdsStation[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        async function load() {
            try {
                const resp = await apiFetch('/api/v1/kds/stations');
                if (!resp.ok) throw new Error();
                const json = (await resp.json()) as { data: KdsStation[] };
                if (!cancelled) {
                    setStations(json.data);
                    setError(null);
                }
            } catch {
                if (!cancelled) {
                    setError('No pudimos cargar las estaciones.');
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        }
        void load();
        return () => {
            cancelled = true;
        };
    }, []);

    const selectedValue = value === null ? '__fallback__' : value;

    function handleChange(next: string) {
        if (next === '__fallback__') {
            onChange(null);
            return;
        }
        onChange(next);
    }

    return (
        <Select value={selectedValue} onValueChange={handleChange} disabled={disabled || loading}>
            <SelectTrigger id={id} className="w-full">
                <SelectValue placeholder={loading ? 'Cargando estaciones…' : 'Selecciona una estación'} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__fallback__">{fallbackLabel}</SelectItem>
                {stations.map((s) => (
                    <SelectItem key={s.id} value={s.id}>
                        <span aria-hidden className="mr-2 inline-block h-2.5 w-2.5 rounded-full align-middle" style={{ backgroundColor: s.color }} />
                        {s.name}
                        {s.is_default && <span className="text-muted-foreground ml-1 text-xs">(default)</span>}
                    </SelectItem>
                ))}
                {error && (
                    <SelectItem value="__error__" disabled>
                        {error}
                    </SelectItem>
                )}
            </SelectContent>
        </Select>
    );
}
