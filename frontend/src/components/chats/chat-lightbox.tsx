import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

import { ChevronLeft, ChevronRight, Download, ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface LightboxImage {
    url: string;
    caption?: string | null;
}

interface ChatLightboxProps {
    /** Todas las imágenes de la conversación, en orden. */
    images: LightboxImage[];
    /** Índice abierto, o `null` cuando está cerrado. */
    index: number | null;
    onIndexChange: (index: number) => void;
    onClose: () => void;
}

/**
 * Visor de imágenes de la conversación (§8.4b punto 13).
 *
 * Ya no es un callejón sin salida: flechas (y teclado ←/→) para recorrer todas
 * las imágenes del chat, zoom y descarga. Reutiliza `Dialog`, que trae overlay,
 * foco atrapado y cierre con `Esc`.
 */
export function ChatLightbox({ images, index, onIndexChange, onClose }: ChatLightboxProps) {
    const [zoom, setZoom] = useState(1);
    const open = index !== null && index >= 0 && index < images.length;
    const current = open ? images[index] : null;
    const many = images.length > 1;

    // Cada imagen arranca en su tamaño natural: sin esto, abrir la siguiente
    // heredaría el zoom de la anterior.
    useEffect(() => {
        setZoom(1);
    }, [index]);

    // Flechas del teclado. Se registra solo con el visor abierto para no comerse
    // las flechas del resto de la bandeja (navegación j/k, §8.4b punto 12).
    useEffect(() => {
        if (!open || index === null || !many) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'ArrowLeft') {
                onIndexChange((index - 1 + images.length) % images.length);
            } else if (event.key === 'ArrowRight') {
                onIndexChange((index + 1) % images.length);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, index, images.length, many, onIndexChange]);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-5xl p-2 sm:p-4">
                {/* Radix exige el título para que el diálogo sea accesible. */}
                <DialogTitle className="sr-only">{current?.caption || 'Imagen del chat'}</DialogTitle>
                {current && (
                    <div className="space-y-2">
                        <div className="relative flex items-center justify-center overflow-auto">
                            {many && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="bg-background/70 absolute top-1/2 left-1 z-10 -translate-y-1/2"
                                    onClick={() => index !== null && onIndexChange((index - 1 + images.length) % images.length)}
                                    aria-label="Imagen anterior"
                                >
                                    <ChevronLeft className="h-6 w-6" />
                                </Button>
                            )}
                            <img
                                src={current.url}
                                alt={current.caption || 'Imagen enviada en la conversación'}
                                style={{ transform: `scale(${zoom})` }}
                                className="max-h-[75svh] w-full origin-center object-contain transition-transform"
                            />
                            {many && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="bg-background/70 absolute top-1/2 right-1 z-10 -translate-y-1/2"
                                    onClick={() => index !== null && onIndexChange((index + 1) % images.length)}
                                    aria-label="Imagen siguiente"
                                >
                                    <ChevronRight className="h-6 w-6" />
                                </Button>
                            )}
                        </div>

                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => setZoom((z) => Math.max(1, z - 0.5))}
                                    disabled={zoom <= 1}
                                    aria-label="Alejar"
                                >
                                    <ZoomOut className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => setZoom((z) => Math.min(4, z + 0.5))}
                                    disabled={zoom >= 4}
                                    aria-label="Acercar"
                                >
                                    <ZoomIn className="h-4 w-4" />
                                </Button>
                                {many && index !== null && (
                                    <span className="text-muted-foreground ml-1 text-xs tabular-nums">
                                        {index + 1} / {images.length}
                                    </span>
                                )}
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <a href={current.url} download target="_blank" rel="noreferrer">
                                    <Download className="mr-2 h-4 w-4" />
                                    Descargar
                                </a>
                            </Button>
                        </div>

                        {current.caption && <p className="text-muted-foreground text-center text-sm">{current.caption}</p>}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
