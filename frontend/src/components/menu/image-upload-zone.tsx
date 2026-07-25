import { useImageUpload } from '@/hooks/use-image-upload';
import { cn } from '@/lib/utils';
import { Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

interface ImageUploadZoneProps {
    onImageSelected: (file: File) => void;
    initialImage?: string | null;
}

export default function ImageUploadZone({ onImageSelected, initialImage }: ImageUploadZoneProps) {
    const { preview, selectedFile, error, handleImageSelect, clearImage } = useImageUpload();
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(true);
    };

    const handleDragLeave = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(false);
    };

    const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(false);

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            // Solo entregamos el file al padre si pasó la validación de tipo/tamaño:
            // antes se enviaba igual y el submit subía un archivo inválido.
            if (handleImageSelect(file)) {
                onImageSelected(file);
            }
        }
    };

    const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = e.currentTarget.files;
        if (files && files.length > 0) {
            const file = files[0];
            if (handleImageSelect(file)) {
                onImageSelected(file);
            }
        }
    };

    const handleRemoveImage = () => {
        clearImage();
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const displayImage = preview || initialImage;

    return (
        <div className="space-y-4">
            {!displayImage ? (
                <div
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    className={cn(
                        'relative flex items-center justify-center rounded-lg border-2 border-dashed px-6 py-12 transition-colors',
                        isDragging ? 'border-primary bg-primary/10' : 'border-border bg-muted/30',
                    )}
                >
                    <input
                        type="file"
                        ref={fileInputRef}
                        onChange={handleFileInputChange}
                        accept="image/jpeg,image/jpg,image/png,image/webp"
                        className="hidden"
                    />
                    <button type="button" onClick={() => fileInputRef.current?.click()} className="flex flex-col items-center gap-3">
                        <Upload className="text-muted-foreground/50 h-10 w-10" />
                        <div className="text-center">
                            <p className="text-foreground text-sm font-medium">Arrastra la imagen aquí</p>
                            <p className="text-muted-foreground text-xs">o haz clic para seleccionar</p>
                        </div>
                        <p className="text-muted-foreground text-xs">JPG, PNG, WEBP • Máx 2 MB</p>
                    </button>
                </div>
            ) : (
                <div className="relative inline-block">
                    <img src={displayImage} alt="Vista previa" className="border-border max-h-64 w-auto rounded-lg border object-cover" />
                    {selectedFile && (
                        <button
                            type="button"
                            onClick={handleRemoveImage}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 absolute top-2 right-2 rounded-full p-1"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    )}
                </div>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
