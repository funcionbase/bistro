import { MunicipalityCombobox } from '@/components/clients/municipality-combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

export function AddressFields({ value, onChange, idPrefix = 'addr' }: { value: AddressValue; onChange: (v: AddressValue) => void; idPrefix?: string }) {
    return (
        <div className="space-y-3">
            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}-city`}>Ciudad</Label>
                <MunicipalityCombobox
                    id={`${idPrefix}-city`}
                    value={value.municipality_dane_code}
                    label={value.municipality_label}
                    onChange={(code, label) => onChange({ ...value, municipality_dane_code: code, municipality_label: label })}
                    placeholder="Buscá tu ciudad…"
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
