import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoaderCircle, Palette } from 'lucide-react';

const DEFAULT_COLOR = '#FF6B35';

interface CompanyColorSectionProps {
    primaryColor: string;
    colorHexInput: string;
    canEdit: boolean;
    savingColor: boolean;
    colorSaved: boolean;
    colorError: string | null;
    isValidHex: (value: string) => boolean;
    onColorPick: (value: string) => void;
    onHexChange: (value: string) => void;
    onSave: () => void;
}

/**
 * Panel "Color principal del menú": color picker + input hexadecimal con
 * vista previa y botón de guardado independiente.
 */
export function CompanyColorSection({
    primaryColor,
    colorHexInput,
    canEdit,
    savingColor,
    colorSaved,
    colorError,
    isValidHex,
    onColorPick,
    onHexChange,
    onSave,
}: CompanyColorSectionProps) {
    return (
        <DashboardPanel title="Color principal del menú" icon={Palette} className="md:col-span-2">
            <p className="text-muted-foreground mb-3 text-xs">Color de marca que se muestra en el menú público de la empresa.</p>
            {colorSaved && (
                <Alert variant="safe" className="mb-3">
                    <AlertDescription>Color actualizado.</AlertDescription>
                </Alert>
            )}
            <div className="flex flex-wrap items-end gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="menu_primary_color">Vista previa</Label>
                    <div className="flex items-center gap-3">
                        <div
                            className="border-border h-10 w-10 rounded-lg border shadow-sm"
                            style={{
                                backgroundColor: isValidHex(primaryColor) ? primaryColor : DEFAULT_COLOR,
                            }}
                        />
                        {canEdit ? (
                            <input
                                id="menu_primary_color"
                                type="color"
                                value={isValidHex(primaryColor) ? primaryColor : DEFAULT_COLOR}
                                onChange={(e) => onColorPick(e.target.value)}
                                className="border-border h-10 w-10 cursor-pointer rounded border bg-transparent p-0.5"
                            />
                        ) : null}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="hex_input">Código hexadecimal</Label>
                    <Input
                        id="hex_input"
                        value={colorHexInput}
                        onChange={(e) => onHexChange(e.target.value)}
                        disabled={!canEdit}
                        placeholder="#FF6B35"
                        className="w-32 font-mono"
                        maxLength={7}
                    />
                </div>
                {canEdit && (
                    <Button type="button" variant="outline" size="sm" disabled={savingColor} onClick={onSave}>
                        {savingColor && <LoaderCircle className="h-3 w-3 animate-spin" />}
                        Guardar color
                    </Button>
                )}
            </div>
            {colorError && <p className="mt-2 text-sm text-[color:var(--color-status-critical)]">{colorError}</p>}
        </DashboardPanel>
    );
}
