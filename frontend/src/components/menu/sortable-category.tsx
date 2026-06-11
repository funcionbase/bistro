import CategoryCard from '@/components/menu/category-card';
import { cn } from '@/lib/utils';
import type { MenuCategory } from '@/types';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

interface SortableCategoryProps {
    category: MenuCategory;
    isSelected: boolean;
    onSelect: () => void;
    onEdit: () => void;
    onDelete: () => void;
    canUpdate?: boolean;
    canDelete?: boolean;
    /** Si true, aplica `animate-drop-bounce` un ciclo tras soltar (DS §14). */
    justDropped?: boolean;
}

export default function SortableCategory({
    category,
    isSelected,
    onSelect,
    onEdit,
    onDelete,
    canUpdate = true,
    canDelete = true,
    justDropped = false,
}: SortableCategoryProps) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: category.id, disabled: !canUpdate });

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
            <CategoryCard
                category={category}
                isSelected={isSelected}
                onSelect={onSelect}
                onEdit={onEdit}
                onDelete={onDelete}
                canUpdate={canUpdate}
                canDelete={canDelete}
            />
        </div>
    );
}
