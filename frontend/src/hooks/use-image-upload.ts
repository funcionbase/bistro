import { useCallback, useEffect, useRef, useState } from 'react';

const MAX_FILE_SIZE = 2048 * 1024; // 2048 KB

export function useImageUpload() {
    const [preview, setPreview] = useState<string | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

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

        // Create preview. La lectura es asíncrona: si el componente se
        // desmonta antes del onload/onerror, no tocamos estado (evita el
        // warning de React y un set sobre un hook muerto).
        const reader = new FileReader();
        reader.onload = (e) => {
            if (!isMounted.current) return;
            setPreview(e.target?.result as string);
            setSelectedFile(file);
        };
        reader.onerror = () => {
            if (!isMounted.current) return;
            setError('No se pudo leer la imagen. Intenta con otro archivo.');
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
