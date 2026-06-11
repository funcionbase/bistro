import { useCallback, useState } from 'react';

const MAX_FILE_SIZE = 2048 * 1024; // 2048 KB

export function useImageUpload() {
    const [preview, setPreview] = useState<string | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);

    const handleImageSelect = useCallback((file: File) => {
        setError(null);

        // Validate file type
        if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
            setError('Solo se permiten archivos JPG y PNG');
            return;
        }

        // Validate file size
        if (file.size > MAX_FILE_SIZE) {
            setError('La imagen no debe superar 2 MB');
            return;
        }

        // Create preview
        const reader = new FileReader();
        reader.onload = (e) => {
            setPreview(e.target?.result as string);
            setSelectedFile(file);
        };
        reader.readAsDataURL(file);
    }, []);

    const clearImage = useCallback(() => {
        setPreview(null);
        setSelectedFile(null);
        setError(null);
    }, []);

    return {
        preview,
        selectedFile,
        error,
        handleImageSelect,
        clearImage,
    };
}
