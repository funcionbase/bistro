import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { MenuCategory } from '@/types';
import { GripVertical, Pencil, Trash2 } from 'lucide-react';

interface CategoryCardProps {
    category: MenuCategory;
    isSelected: boolean;
    onSelect: () => void;
    onEdit: () => void;
    onDelete: () => void;
    canUpdate?: boolean;
    canDelete?: boolean;
}

export default function CategoryCard({ category, isSelected, onSelect, onEdit, onDelete, canUpdate = true, canDelete = true }: CategoryCardProps) {
    const showActions = canUpdate || canDelete;

    return (
        <Card className={cn('cursor-pointer transition-shadow hover:shadow-md', isSelected && 'ring-primary ring-2')} onClick={onSelect}>
            <CardContent className="flex items-center gap-3 p-3">
                {canUpdate && <GripVertical className="text-muted-foreground/50 h-4 w-4 shrink-0" />}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{category.name}</p>
                    {category.description && <p className="text-muted-foreground truncate text-xs">{category.description}</p>}
                </div>
                <Badge variant="secondary" className="shrink-0 text-xs">
                    {category.items.length} ítem{category.items.length !== 1 ? 's' : ''}
                </Badge>
                {showActions && (
                    <div className="flex shrink-0 gap-1" onClick={(e) => e.stopPropagation()}>
                        {canUpdate && (
                            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={onEdit}>
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                        )}
                        {canDelete && (
                            <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive h-7 w-7" onClick={onDelete}>
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
