/** Lado mayor tras el resize. Suficiente para que una foto de plato se vea bien en WhatsApp. */
const MAX_EDGE = 1600;
/** 0,8 es donde la pérdida deja de notarse y el peso ya cayó un orden de magnitud. */
const QUALITY = 0.8;

/**
 * Reduce una imagen en el navegador antes de subirla (§8.4b punto 6).
 *
 * El operador manda fotos de 8 MB tomadas con el celular. Bajarlas a ~1600 px
 * hace la subida más rápida, recorta el egreso de S3 —que se paga— y saca el
 * tope de 16 MB del camino en el caso normal.
 *
 * Devuelve el archivo ORIGINAL si algo falla o si comprimir no ayuda: nunca
 * bloquea el envío. Un adjunto que no se manda es peor que uno pesado.
 *
 * PNG y GIF se dejan pasar sin tocar: el primero suele ser una captura donde el
 * recomprimido a JPEG destroza el texto, y el segundo perdería la animación.
 */
export async function compressImage(file: File): Promise<File> {
    if (!file.type.startsWith('image/') || file.type === 'image/png' || file.type === 'image/gif') {
        return file;
    }

    // `createImageBitmap` decodifica fuera del hilo principal y evita el baile
    // de `<img>` + onload. Si el browser no lo soporta, se sube el original.
    if (typeof createImageBitmap !== 'function') {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));

        // Ya es chica: recomprimir solo agregaría artefactos sin ahorrar nada.
        if (scale === 1) {
            bitmap.close();
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            bitmap.close();
            return file;
        }

        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

        if (!blob || blob.size >= file.size) {
            return file;
        }

        // La extensión pasa a .jpg porque el contenido AHORA es JPEG. El backend
        // detecta el MIME real con finfo y rechazaría un .png que no lo es.
        const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';

        return new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}
