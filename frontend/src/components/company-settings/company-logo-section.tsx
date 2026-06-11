import InputError from '@/components/input-error';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { cn } from '@/lib/utils';
import { Upload, X } from 'lucide-react';

interface CompanyLogoSectionProps {
    /** URL del logo a mostrar (preview local o el persistido). */
    currentLogoSrc: string | null;
    /** True si hay un archivo nuevo seleccionado sin guardar. */
    hasNewLogo: boolean;
    canEdit: boolean;
    isLogoDragging: boolean;
    logoInputRef: React.RefObject<HTMLInputElement | null>;
    errorMessage?: string;
    onLogoFile: (file: File) => void;
    onRemoveLogo: () => void;
    onDraggingChange: (v: boolean) => void;
}

/**
 * Panel "Logo de la empresa": muestra el logo actual o un dropzone para
 * subir uno nuevo (PNG/JPG/WEBP/SVG, máx. 5 MB).
 */
export function CompanyLogoSection({
    currentLogoSrc,
    hasNewLogo,
    canEdit,
    isLogoDragging,
    logoInputRef,
    errorMessage,
    onLogoFile,
    onRemoveLogo,
    onDraggingChange,
}: CompanyLogoSectionProps) {
    return (
        <DashboardPanel title="Logo de la empresa" className="md:col-span-2">
            <p className="text-muted-foreground mb-4 text-xs">Se muestra en el menú lateral y se usa como favicon del panel.</p>
            <div className="flex flex-col items-start gap-4 sm:flex-row sm:gap-6">
                {currentLogoSrc ? (
                    <div className="flex flex-col items-center gap-2">
                        <img
                            src={currentLogoSrc}
                            alt="Logo"
                            className="border-border bg-muted size-20 rounded-xl border object-contain p-1"
                        />
                        {canEdit && (
                            <div className="flex items-center gap-3 text-xs">
                                {/* "Reemplazar" abre el file picker; el archivo previo se
                                    elimina del disco en el backend al guardar
                                    (CompanyController::update). */}
                                <button
                                    type="button"
                                    onClick={() => logoInputRef.current?.click()}
                                    className="text-primary hover:underline"
                                >
                                    {hasNewLogo ? 'Cambiar selección' : 'Reemplazar'}
                                </button>
                                {hasNewLogo && (
                                    <button
                                        type="button"
                                        onClick={onRemoveLogo}
                                        className="text-muted-foreground hover:text-foreground flex items-center gap-1"
                                    >
                                        <X className="h-3 w-3" />
                                        Cancelar
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                ) : canEdit ? (
                    <div
                        role="button"
                        tabIndex={0}
                        className={cn(
                            'flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-6 py-6 text-center transition-colors',
                            isLogoDragging
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:border-primary/50 hover:bg-muted/50',
                        )}
                        onDragOver={(e) => {
                            e.preventDefault();
                            onDraggingChange(true);
                        }}
                        onDragLeave={() => onDraggingChange(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            onDraggingChange(false);
                            const f = e.dataTransfer.files[0];
                            if (f) onLogoFile(f);
                        }}
                        onClick={() => logoInputRef.current?.click()}
                        onKeyDown={(e) => e.key === 'Enter' && logoInputRef.current?.click()}
                    >
                        <Upload className="text-muted-foreground h-7 w-7" />
                        <div>
                            <p className="text-sm font-medium">Subir logo</p>
                            <p className="text-muted-foreground text-xs">PNG, JPG, WEBP o SVG (máx. 5 MB)</p>
                        </div>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">Sin logo registrado.</p>
                )}
                <input
                    ref={logoInputRef}
                    type="file"
                    accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                    className="hidden"
                    onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f) onLogoFile(f);
                    }}
                />
            </div>
            <InputError message={errorMessage} />
        </DashboardPanel>
    );
}
