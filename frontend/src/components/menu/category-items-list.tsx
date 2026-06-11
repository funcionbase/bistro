import SortableItem from '@/components/menu/sortable-item';
import { Button } from '@/components/ui/button';
import type { MenuItem } from '@/types';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Plus } from 'lucide-react';

interface CategoryItemsListProps {
    menuId: string;
    categoryId: string;
    items: MenuItem[];
    onReorder: (itemId: string, newOrder: number) => void;
    onEdit: (item: MenuItem) => void;
    onDelete: (itemId: string) => void;
    onAvailabilityToggle: (itemId: string, available: boolean) => void;
    onAddItem: () => void;
    canCreate: boolean;
    canUpdate?: boolean;
    canDelete?: boolean;
    /** ID del item recien soltado en drag&drop; recibe `animate-drop-bounce`. */
    droppedItemId?: string | null;
}

export default function CategoryItemsList({
    menuId,
    categoryId,
    items,
    onEdit,
    onDelete,
    onAvailabilityToggle,
    onAddItem,
    canCreate,
    canUpdate = true,
    canDelete = true,
    droppedItemId = null,
}: CategoryItemsListProps) {
    const itemIds = items.map((item) => item.id);

    return (
        <SortableContext items={itemIds} strategy={verticalListSortingStrategy} disabled={!canUpdate}>
            <div className="space-y-3">
                {items.length === 0 ? (
                    <div className="border-border flex flex-col items-center justify-center rounded-lg border-2 border-dashed py-12">
                        <p className="text-muted-foreground mb-4 text-sm">No hay items en esta categoría</p>
                        {canCreate && (
                            <Button size="sm" onClick={onAddItem} className="gap-2">
                                <Plus className="h-4 w-4" />
                                Agregar item
                            </Button>
                        )}
                    </div>
                ) : (
                    <>
                        {items.map((item) => (
                            <SortableItem
                                key={item.id}
                                menuId={menuId}
                                categoryId={categoryId}
                                item={item}
                                onEdit={() => onEdit(item)}
                                onDelete={() => onDelete(item.id)}
                                onAvailabilityToggle={(available) => onAvailabilityToggle(item.id, available)}
                                canUpdate={canUpdate}
                                canDelete={canDelete}
                                justDropped={droppedItemId === item.id}
                            />
                        ))}
                        {canCreate && (
                            <Button variant="outline" size="sm" onClick={onAddItem} className="w-full gap-2">
                                <Plus className="h-4 w-4" />
                                Agregar item
                            </Button>
                        )}
                    </>
                )}
            </div>
        </SortableContext>
    );
}
