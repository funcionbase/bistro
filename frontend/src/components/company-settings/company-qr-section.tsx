import InputError from '@/components/input-error';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { cn } from '@/lib/utils';
import { Upload, X } from 'lucide-react';

interface CompanyQrSectionProps {
    /** URL del QR a mostrar (preview local o el persistido). */
    currentQrSrc: string | null;
    /** True si hay un archivo nuevo seleccionado sin guardar. */
    hasNewQr: boolean;
    canEdit: boolean;
    isDragging: boolean;
    fileInputRef: React.RefObject<HTMLInputElement | null>;
    errorMessage?: string;
    onQrFile: (file: File) => void;
    onRemoveQr: () => void;
    onDraggingChange: (v: boolean) => void;
}

/**
 * Panel "QR de pagos": muestra el QR actual o un dropzone para subir uno
 * nuevo (PNG/JPG, máx. 5 MB) para pagos por transferencia.
 */
export function CompanyQrSection({
    currentQrSrc,
    hasNewQr,
    canEdit,
    isDragging,
    fileInputRef,
    errorMessage,
    onQrFile,
    onRemoveQr,
    onDraggingChange,
}: CompanyQrSectionProps) {
    return (
        <DashboardPanel title="QR de pagos" className="md:col-span-2">
            <p className="text-muted-foreground mb-3 text-xs">
                Imagen estática que se muestra al cliente para pagos por transferencia (Nequi, Daviplata, etc.).
            </p>
            <div className="grid gap-2">
                {currentQrSrc ? (
                    <div className="border-border flex items-center gap-4 rounded-md border p-3">
                        <img src={currentQrSrc} alt="QR de pagos" className="h-20 w-20 rounded object-cover" />
                        <div className="flex-1">
                            <p className="text-sm font-medium">QR actual</p>
                            {canEdit && (
                                <div className="mt-1 flex items-center gap-3 text-xs">
                                    <button
                                        type="button"
                                        onClick={() => fileInputRef.current?.click()}
                                        className="text-primary hover:underline"
                                    >
                                        {hasNewQr ? 'Cambiar selección' : 'Reemplazar'}
                                    </button>
                                    {hasNewQr && (
                                        <button
                                            type="button"
                                            onClick={onRemoveQr}
                                            className="text-muted-foreground hover:text-foreground flex items-center gap-1"
                                        >
                                            <X className="h-3 w-3" />
                                            Cancelar
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                ) : canEdit ? (
                    <div
                        role="button"
                        tabIndex={0}
                        className={cn(
                            'flex cursor-pointer flex-col items-center gap-3 rounded-md border-2 border-dashed px-4 py-8 text-center transition-colors',
                            isDragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50 hover:bg-muted/50',
                        )}
                        onDragOver={(e) => {
                            e.preventDefault();
                            onDraggingChange(true);
                        }}
                        onDragLeave={() => onDraggingChange(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            onDraggingChange(false);
                            const file = e.dataTransfer.files[0];
                            if (file) onQrFile(file);
                        }}
                        onClick={() => fileInputRef.current?.click()}
                        onKeyDown={(e) => e.key === 'Enter' && fileInputRef.current?.click()}
                    >
                        <Upload className="text-muted-foreground h-8 w-8" />
                        <div>
                            <p className="text-sm font-medium">Arrastra tu imagen aquí</p>
                            <p className="text-muted-foreground text-xs">o haz clic para seleccionar — PNG, JPG (máx. 5 MB)</p>
                        </div>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">Sin QR registrado.</p>
                )}
                <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/png,image/jpeg,image/jpg"
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) onQrFile(file);
                    }}
                />
                <InputError message={errorMessage} />
            </div>
        </DashboardPanel>
    );
}
