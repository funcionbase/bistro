import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { UtensilsCrossed } from 'lucide-react';

export interface MenuItemDetailDialogItem {
    name: string;
    description?: string | null;
    image_url?: string | null;
    thumbnail_url?: string | null;
    price?: number | null;
}

interface MenuItemDetailDialogProps {
    item: MenuItemDetailDialogItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Formato de precio opcional. Si se omite, no se muestra el precio. */
    formatPrice?: (value: number) => string;
}

/**
 * Modal reutilizable que muestra el detalle de un plato: foto grande,
 * nombre, descripción y (opcional) precio. Se usa al tocar la imagen del
 * item en /menus, /t/{qr}/menu y /menu/{id}.
 *
 * Sin lógica de carrito ni acciones: es solo de presentación.
 */
export default function MenuItemDetailDialog({ item, open, onOpenChange, formatPrice }: MenuItemDetailDialogProps) {
    if (item === null) return null;

    const src = item.image_url ?? item.thumbnail_url ?? null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg overflow-hidden p-0">
                <div className="bg-muted relative aspect-square w-full sm:aspect-[4/3]">
                    {src ? (
                        <img src={src} alt={item.name} className="h-full w-full object-cover" loading="eager" />
                    ) : (
                        <div className="text-muted-foreground flex h-full w-full items-center justify-center">
                            <UtensilsCrossed className="size-16" />
                        </div>
                    )}
                </div>
                <div className="space-y-3 p-5">
                    <DialogHeader className="space-y-1">
                        <DialogTitle className="text-foreground text-xl font-semibold tracking-tight">{item.name}</DialogTitle>
                        {item.description ? (
                            <DialogDescription className="text-muted-foreground text-sm leading-relaxed">{item.description}</DialogDescription>
                        ) : (
                            <DialogDescription className="text-muted-foreground/70 text-sm italic">Sin descripción.</DialogDescription>
                        )}
                    </DialogHeader>
                    {formatPrice && typeof item.price === 'number' && (
                        <p className="text-foreground text-lg font-semibold tabular-nums">{formatPrice(item.price)}</p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
