import InputError from '@/components/input-error';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CompanyFieldErrors } from '@/hooks/use-company-settings';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { Building2 } from 'lucide-react';

interface CompanyGeneralSectionProps {
    nit: string;
    commercialName: string;
    legalName: string;
    canEdit: boolean;
    errors: CompanyFieldErrors;
    onCommercialNameChange: (v: string) => void;
    onLegalNameChange: (v: string) => void;
}

/** Panel "Información general": NIT, nombre comercial y razón social. */
export function CompanyGeneralSection({
    nit,
    commercialName,
    legalName,
    canEdit,
    errors,
    onCommercialNameChange,
    onLegalNameChange,
}: CompanyGeneralSectionProps) {
    return (
        <DashboardPanel title="Información general" icon={Building2}>
            <div className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="nit">NIT</Label>
                    <Input id="nit" value={nit} disabled placeholder="NIT" />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="commercial_name">Nombre comercial</Label>
                    <Input
                        id="commercial_name"
                        value={commercialName}
                        onChange={(e) => onCommercialNameChange(sanitizePlainText(e.target.value, 255, false, false))}
                        disabled={!canEdit}
                        placeholder="Ej: Mi empresa S.A.S."
                        maxLength={255}
                    />
                    <InputError message={errors.commercial_name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="legal_name">Razón social</Label>
                    <Input
                        id="legal_name"
                        value={legalName}
                        onChange={(e) => onLegalNameChange(sanitizePlainText(e.target.value, 255, false, false))}
                        disabled={!canEdit}
                        placeholder="Ej: El Sabor S.A.S."
                        maxLength={255}
                    />
                    <InputError message={errors.legal_name} />
                </div>
            </div>
        </DashboardPanel>
    );
}
