import CategoryItemsList from '@/components/menu/category-items-list';
import SortableCategory from '@/components/menu/sortable-category';
import { Button } from '@/components/ui/button';
import { useMenuDrag } from '@/hooks/use-menu-drag';
import type { MenuCategory, MenuItem, RestaurantMenu } from '@/types';
import { closestCenter, DndContext, DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Plus } from 'lucide-react';
import { useCallback, useState } from 'react';

interface MenuManagerProps {
    menu: RestaurantMenu;
    onCategoryDeleted: (categoryId: string) => void;
    onItemDeleted: (categoryId: string, itemId: string) => void;
    onItemAvailabilityToggle: (categoryId: string, itemId: string, available: boolean) => void;
    onPublish: () => void;
    onPreview: () => void;
    onAddCategory: () => void;
    onEditCategory: (category: MenuCategory) => void;
    onAddItem: (categoryId: string) => void;
    onEditItem: (categoryId: string, item: MenuItem) => void;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}

export default function MenuManager({
    menu,
    onCategoryDeleted,
    onItemDeleted,
    onItemAvailabilityToggle,
    onPublish,
    onPreview,
    onAddCategory,
    onEditCategory,
    onAddItem,
    onEditItem,
    canCreate,
    canUpdate,
    canDelete,
}: MenuManagerProps) {
    const [selectedCategoryId, setSelectedCategoryId] = useState<string | null>(
        menu.structure.categories.length > 0 ? menu.structure.categories[0].id : null,
    );
    const [droppedId, setDroppedId] = useState<string | null>(null);
    const { updateCategoryOrder, updateItemOrder } = useMenuDrag(menu.id);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 8,
            },
        }),
        useSensor(KeyboardSensor),
    );

    const selectedCategory = menu.structure.categories.find((cat) => cat.id === selectedCategoryId);

    const categoryIds = menu.structure.categories.map((cat) => cat.id);

    const flashDropped = useCallback((id: string) => {
        setDroppedId(id);
        window.setTimeout(() => {
            setDroppedId((cur) => (cur === id ? null : cur));
        }, 650);
    }, []);

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;

            if (!over || active.id === over.id) {
                return;
            }

            // Check if we're dragging a category
            const activeCategory = menu.structure.categories.find((cat) => cat.id === active.id);
            if (activeCategory) {
                const oldIndex = menu.structure.categories.findIndex((cat) => cat.id === active.id);
                const newIndex = menu.structure.categories.findIndex((cat) => cat.id === over.id);

                const newCategories = arrayMove(menu.structure.categories, oldIndex, newIndex);

                // Update order for each category
                newCategories.forEach((cat, index) => {
                    if (cat.order !== index + 1) {
                        updateCategoryOrder(cat.id, index + 1);
                    }
                });

                flashDropped(String(active.id));
                return;
            }

            // Check if we're dragging an item within a category
            if (selectedCategory) {
                const oldIndex = selectedCategory.items.findIndex((item) => item.id === active.id);
                const newIndex = selectedCategory.items.findIndex((item) => item.id === over.id);

                if (oldIndex !== -1 && newIndex !== -1) {
                    const newItems = arrayMove(selectedCategory.items, oldIndex, newIndex);

                    // Update order for each item
                    newItems.forEach((item, index) => {
                        if (item.order !== index + 1) {
                            updateItemOrder(item.id, index + 1);
                        }
                    });

                    flashDropped(String(active.id));
                }
            }
        },
        [menu, selectedCategory, updateCategoryOrder, updateItemOrder, flashDropped],
    );

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
            <div className="flex flex-col gap-4">
                {/* Action Buttons */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    {canCreate && (
                        <Button onClick={onAddCategory} className="w-full gap-2 sm:w-auto" size="sm">
                            <Plus className="h-4 w-4" />
                            Agregar Categoría
                        </Button>
                    )}
                    <Button onClick={onPreview} variant="outline" size="sm" className="w-full sm:w-auto">
                        Vista previa
                    </Button>
                    {canUpdate && (
                        <Button onClick={onPublish} variant="default" size="sm" className="w-full sm:ml-auto sm:w-auto">
                            Publicar menú
                        </Button>
                    )}
                </div>

                {/* Main Content */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Left Panel - Categories */}
                    <div className="space-y-3">
                        <h3 className="text-foreground font-semibold">Categorías</h3>
                        {menu.structure.categories.length === 0 ? (
                            <div className="border-border rounded-lg border-2 border-dashed py-8 text-center">
                                <p className="text-muted-foreground text-sm">No hay categorías</p>
                            </div>
                        ) : (
                            <SortableContext items={categoryIds} strategy={verticalListSortingStrategy} disabled={!canUpdate}>
                                <div className="space-y-2">
                                    {menu.structure.categories.map((category) => (
                                        <SortableCategory
                                            key={category.id}
                                            category={category}
                                            isSelected={selectedCategoryId === category.id}
                                            onSelect={() => setSelectedCategoryId(category.id)}
                                            onEdit={() => onEditCategory(category)}
                                            onDelete={() => onCategoryDeleted(category.id)}
                                            canUpdate={canUpdate}
                                            canDelete={canDelete}
                                            justDropped={droppedId === category.id}
                                        />
                                    ))}
                                </div>
                            </SortableContext>
                        )}
                    </div>

                    {/* Right Panel - Items */}
                    <div className="space-y-3">
                        <h3 className="text-foreground font-semibold">
                            {selectedCategory ? `Items - ${selectedCategory.name}` : 'Selecciona una categoría'}
                        </h3>
                        {selectedCategory ? (
                            <CategoryItemsList
                                menuId={menu.id}
                                categoryId={selectedCategory.id}
                                items={selectedCategory.items}
                                onReorder={(itemId, newOrder) => updateItemOrder(itemId, newOrder)}
                                onEdit={(item) => onEditItem(selectedCategory.id, item)}
                                onDelete={(itemId) => onItemDeleted(selectedCategory.id, itemId)}
                                onAvailabilityToggle={(itemId, available) => onItemAvailabilityToggle(selectedCategory.id, itemId, available)}
                                onAddItem={() => onAddItem(selectedCategory.id)}
                                canCreate={canCreate}
                                canUpdate={canUpdate}
                                canDelete={canDelete}
                                droppedItemId={droppedId}
                            />
                        ) : (
                            <div className="border-border rounded-lg border-2 border-dashed py-8 text-center">
                                <p className="text-muted-foreground text-sm">Selecciona una categoría para ver sus items</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </DndContext>
    );
}
