import { FileText, Upload, X } from 'lucide-react';
import { type DragEvent, type ReactNode, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

interface FileDropzoneProps {
    /** Archivo actual seleccionado, o null si no hay nada. */
    value: File | null;
    /** Callback al seleccionar/cambiar archivo. null cuando se remueve. */
    onChange: (file: File | null) => void;
    /** Attribute accept del input (ej. `.pdf,.png,.jpg` o `image/*`). */
    accept: string;
    /** Copy de instrucción dentro del dropzone vacío (ej. "Arrastra el documento aquí"). */
    label?: string;
    /** Copy de soporte debajo del label (ej. "PDF, Word, JPG (máx. 10 MB)"). */
    helperText?: string;
    /**
     * Si true, muestra preview de la imagen seleccionada cuando es image/*.
     * Si false o el archivo no es imagen, muestra icono FileText genérico.
     */
    showImagePreview?: boolean;
    /** ID del input para vincular con Label externo. */
    id?: string;
    className?: string;
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Drag-and-drop file uploader con dos estados visuales:
 * - Vacío: zona dashed con icono Upload + label + helper.
 * - Con archivo: chip con preview (imagen) o icono genérico + nombre + tamaño + remove.
 *
 * No incluye validación interna de tipo/tamaño — la página decide qué hacer
 * con el File recibido (ej. error de "máx 10MB" se muestra vía InputError
 * por fuera). Esto mantiene el componente desacoplado de la lógica de form.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §11 (formularios) y §6.2 (catálogo).
 */
export function FileDropzone({
    value,
    onChange,
    accept,
    label = 'Arrastra el archivo aquí',
    helperText,
    showImagePreview = false,
    id,
    className,
}: FileDropzoneProps) {
    const [isDragging, setIsDragging] = useState(false);
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const handleFile = (file: File | null) => {
        onChange(file);
        if (file && showImagePreview && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (ev) => setImagePreview(ev.target?.result as string);
            reader.readAsDataURL(file);
        } else {
            setImagePreview(null);
        }
    };

    const handleDragOver = (e: DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (e: DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
    };

    const handleDrop = (e: DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        const file = e.dataTransfer.files?.[0];
        if (file) handleFile(file);
    };

    const removeFile = () => {
        handleFile(null);
        if (inputRef.current) inputRef.current.value = '';
    };

    const openPicker = () => inputRef.current?.click();

    const previewVisual: ReactNode =
        imagePreview && showImagePreview ? (
            <img src={imagePreview} alt="Vista previa" className="h-16 w-16 rounded object-cover" />
        ) : (
            <div className="bg-muted text-muted-foreground flex h-10 w-10 shrink-0 items-center justify-center rounded">
                <FileText className="h-5 w-5" />
            </div>
        );

    return (
        <div className={className}>
            {value ? (
                <div className="border-border relative flex items-center gap-3 rounded-md border p-3">
                    {previewVisual}
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{value.name}</p>
                        <p className="text-muted-foreground text-xs tabular-nums">{formatFileSize(value.size)}</p>
                    </div>
                    <button
                        type="button"
                        onClick={removeFile}
                        className="text-muted-foreground hover:bg-muted hover:text-foreground flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors"
                        aria-label="Eliminar archivo"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            ) : (
                <div
                    role="button"
                    tabIndex={0}
                    className={cn(
                        'flex cursor-pointer flex-col items-center gap-3 rounded-md border-2 border-dashed px-4 py-8 text-center transition-colors',
                        isDragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50 hover:bg-muted/50',
                    )}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    onClick={openPicker}
                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && openPicker()}
                >
                    <Upload className="text-muted-foreground h-8 w-8" />
                    <div>
                        <p className="text-sm font-medium">{label}</p>
                        {helperText && <p className="text-muted-foreground text-xs">{helperText}</p>}
                    </div>
                </div>
            )}
            <input
                ref={inputRef}
                id={id}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) handleFile(file);
                }}
            />
        </div>
    );
}
