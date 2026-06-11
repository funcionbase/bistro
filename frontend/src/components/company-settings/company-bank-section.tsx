import InputError from '@/components/input-error';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { CompanyFieldErrors } from '@/hooks/use-company-settings';
import type { Bank } from '@/types';
import { Landmark } from 'lucide-react';

interface CompanyBankSectionProps {
    availableBanks: Bank[];
    bankId: string;
    accountNumber: string;
    accountType: string;
    brebKey: string;
    canEdit: boolean;
    errors: CompanyFieldErrors;
    onBankIdChange: (v: string) => void;
    onAccountNumberChange: (v: string) => void;
    onAccountTypeChange: (v: string) => void;
    onBrebKeyChange: (v: string) => void;
}

/** Panel "Datos bancarios": banco, número y tipo de cuenta, llave BREB. */
export function CompanyBankSection({
    availableBanks,
    bankId,
    accountNumber,
    accountType,
    brebKey,
    canEdit,
    errors,
    onBankIdChange,
    onAccountNumberChange,
    onAccountTypeChange,
    onBrebKeyChange,
}: CompanyBankSectionProps) {
    return (
        <DashboardPanel title="Datos bancarios" icon={Landmark}>
            <div className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="bank">Banco</Label>
                    <Select value={bankId} onValueChange={onBankIdChange} disabled={!canEdit}>
                        <SelectTrigger id="bank">
                            <SelectValue placeholder="Selecciona un banco" />
                        </SelectTrigger>
                        <SelectContent>
                            {availableBanks.map((b) => (
                                <SelectItem key={b.id} value={String(b.id)}>
                                    {b.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="account_number">Número de cuenta</Label>
                    <Input
                        id="account_number"
                        value={accountNumber}
                        onChange={(e) => onAccountNumberChange(e.target.value)}
                        disabled={!canEdit}
                        placeholder="Número de cuenta"
                    />
                    <InputError message={errors.account_number} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="account_type">Tipo de cuenta</Label>
                    <Select value={accountType} onValueChange={onAccountTypeChange} disabled={!canEdit}>
                        <SelectTrigger id="account_type">
                            <SelectValue placeholder="Selecciona el tipo de cuenta" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="Ahorros">Ahorros</SelectItem>
                            <SelectItem value="Corriente">Corriente</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.account_type} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="breb_key">
                        Llave BREB <span className="text-muted-foreground text-xs font-normal">(opcional)</span>
                    </Label>
                    <Input
                        id="breb_key"
                        value={brebKey}
                        onChange={(e) => onBrebKeyChange(e.target.value)}
                        disabled={!canEdit}
                        placeholder="Llave de interoperabilidad"
                    />
                    <InputError message={errors.breb_key} />
                </div>
            </div>
        </DashboardPanel>
    );
}
