import ItemCard from '@/components/menu/item-card';
import { cn } from '@/lib/utils';
import type { MenuItem } from '@/types';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

interface SortableItemProps {
    menuId: string;
    categoryId: string;
    item: MenuItem;
    onEdit: () => void;
    onDelete: () => void;
    onAvailabilityToggle?: (available: boolean) => void;
    canUpdate?: boolean;
    canDelete?: boolean;
    /** Si true, aplica `animate-drop-bounce` un ciclo tras soltar (DS §14). */
    justDropped?: boolean;
}

export default function SortableItem({
    menuId,
    categoryId,
    item,
    onEdit,
    onDelete,
    onAvailabilityToggle,
    canUpdate = true,
    canDelete = true,
    justDropped = false,
}: SortableItemProps) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.id, disabled: !canUpdate });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            {...attributes}
            {...listeners}
            className={cn(canUpdate && (isDragging ? 'cursor-grabbing' : 'cursor-grab'), justDropped && 'animate-drop-bounce rounded-lg')}
        >
            <ItemCard
                menuId={menuId}
                categoryId={categoryId}
                item={item}
                onEdit={onEdit}
                onDelete={onDelete}
                onAvailabilityToggle={onAvailabilityToggle}
                canUpdate={canUpdate}
                canDelete={canDelete}
            />
        </div>
    );
}
