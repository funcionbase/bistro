import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KpiCell } from '@/components/ui/kpi-cell';
import type { RestaurantMenu } from '@/types';

interface PublishModalProps {
    isOpen: boolean;
    menu: RestaurantMenu;
    onConfirm: () => void;
    onCancel: () => void;
    isLoading?: boolean;
}

export default function PublishModal({ isOpen, menu, onConfirm, onCancel, isLoading = false }: PublishModalProps) {
    const totalCategories = menu.structure.categories.length;
    const totalItems = menu.structure.categories.reduce((acc, cat) => acc + cat.items.length, 0);
    const availableItems = menu.structure.categories.reduce((acc, cat) => acc + cat.items.filter((item) => item.available).length, 0);

    return (
        <Dialog open={isOpen} onOpenChange={onCancel}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Publicar este menú?</DialogTitle>
                    <DialogDescription>{menu.name}</DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="grid grid-cols-3 gap-2">
                        <KpiCell label="Categorías" value={totalCategories} />
                        <KpiCell label="Items totales" value={totalItems} />
                        <KpiCell label="Disponibles" value={availableItems} />
                    </div>

                    <p className="text-muted-foreground border-t pt-3 text-xs">Se desactivarán otros menús activos de esta empresa.</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel} disabled={isLoading}>
                        Cancelar
                    </Button>
                    <Button type="button" onClick={onConfirm} disabled={isLoading}>
                        {isLoading ? 'Publicando…' : 'Publicar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
