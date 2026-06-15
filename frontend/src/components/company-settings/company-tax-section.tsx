import { Checkbox } from '@/components/ui/checkbox';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { TaxPresets } from '@/hooks/use-company-settings';
import { Receipt } from 'lucide-react';

interface CompanyTaxSectionProps {
    taxRegime: string;
    defaultTaxRate: string;
    defaultTaxLabel: string;
    taxIncludedInPrice: boolean;
    taxPresets: TaxPresets;
    canEdit: boolean;
    processing: boolean;
    onTaxRegimeChange: (v: string) => void;
    onDefaultTaxRateChange: (v: string) => void;
    onDefaultTaxLabelChange: (v: string) => void;
    onTaxIncludedInPriceChange: (v: boolean) => void;
}

/**
 * Panel "Impuestos": régimen tributario, tasa por defecto, etiqueta y si
 * los precios ya incluyen impuesto. Al elegir un régimen (≠ custom) aplica
 * el preset correspondiente sobre la tasa y la etiqueta.
 */
export function CompanyTaxSection({
    taxRegime,
    defaultTaxRate,
    defaultTaxLabel,
    taxIncludedInPrice,
    taxPresets,
    canEdit,
    processing,
    onTaxRegimeChange,
    onDefaultTaxRateChange,
    onDefaultTaxLabelChange,
    onTaxIncludedInPriceChange,
}: CompanyTaxSectionProps) {
    return (
        <DashboardPanel title="Impuestos" icon={Receipt} className="md:col-span-2">
            <p className="text-muted-foreground mb-3 text-xs">
                Configura el régimen tributario y la tasa por defecto que se aplicará a las órdenes nuevas. Los snapshots se guardan a nivel
                de orden, así que cambios aquí no afectan órdenes ya registradas.
            </p>
            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="tax_regime">Régimen tributario</Label>
                    <Select
                        value={taxRegime}
                        onValueChange={(next) => {
                            onTaxRegimeChange(next);
                            const preset = taxPresets[next];
                            if (preset && next !== 'custom') {
                                onDefaultTaxRateChange(String(preset.rate));
                                onDefaultTaxLabelChange(preset.label);
                            }
                        }}
                        disabled={!canEdit || processing}
                    >
                        <SelectTrigger id="tax_regime">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="simple">Régimen Simple (sin IVA)</SelectItem>
                            <SelectItem value="inc_8">INC 8% (servicios de alimentos)</SelectItem>
                            <SelectItem value="iva_19">IVA 19%</SelectItem>
                            <SelectItem value="iva_5">IVA 5%</SelectItem>
                            <SelectItem value="iva_exento">IVA Exento</SelectItem>
                            <SelectItem value="custom">Personalizado</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="default_tax_rate">Tasa por defecto (%)</Label>
                    <Input
                        id="default_tax_rate"
                        type="number"
                        min={0}
                        max={100}
                        step="1"
                        value={defaultTaxRate}
                        onChange={(e) => onDefaultTaxRateChange(e.target.value)}
                        disabled={!canEdit || processing || taxRegime !== 'custom'}
                    />
                    <p className="text-muted-foreground text-xs">
                        {taxRegime === 'custom'
                            ? 'Ingresa la tasa personalizada.'
                            : 'Se ajusta automáticamente al elegir un régimen.'}
                    </p>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="default_tax_label">Etiqueta del impuesto</Label>
                    <Input
                        id="default_tax_label"
                        value={defaultTaxLabel}
                        onChange={(e) => onDefaultTaxLabelChange(e.target.value)}
                        disabled={!canEdit || processing || taxRegime !== 'custom'}
                        placeholder="Ej. INC 8%"
                        maxLength={60}
                    />
                </div>
                <div className="flex items-center gap-2 self-end pb-1">
                    <Checkbox
                        id="tax_included_in_price"
                        checked={taxIncludedInPrice}
                        onCheckedChange={(v) => onTaxIncludedInPriceChange(v === true)}
                        disabled={!canEdit || processing}
                    />
                    <Label htmlFor="tax_included_in_price" className="cursor-pointer text-sm font-normal">
                        Los precios del menú ya incluyen el impuesto
                    </Label>
                </div>
            </div>
        </DashboardPanel>
    );
}
