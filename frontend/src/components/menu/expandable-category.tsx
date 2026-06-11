import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { MenuCategory } from '@/types';
import { ChevronDown, GripVertical, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ExpandableCategoryProps {
    category: MenuCategory;
    menuId: string;
    onEdit?: () => void;
    onDelete?: () => void;
    isDragging?: boolean;
    children?: React.ReactNode;
}

export default function ExpandableCategory({ category, onEdit, onDelete, isDragging = false, children }: ExpandableCategoryProps) {
    const [isExpanded, setIsExpanded] = useState(false);

    return (
        <div className="border-border border-b last:border-b-0">
            <button
                onClick={() => setIsExpanded(!isExpanded)}
                className={cn('hover:bg-muted flex w-full items-center gap-3 px-3 py-3 transition-colors', isDragging && 'opacity-50')}
            >
                {/* Drag handle */}
                <GripVertical className="text-muted-foreground/50 h-4 w-4 shrink-0" />

                {/* Category name */}
                <div className="flex-1 text-left">
                    <p className="text-sm font-medium">{category.name}</p>
                </div>

                {/* Item count badge */}
                <Badge variant="outline" className="shrink-0 text-xs">
                    {category.items.length} {category.items.length === 1 ? 'plato' : 'platos'}
                </Badge>

                {/* Edit and Delete buttons */}
                <div className="flex shrink-0 items-center gap-1">
                    {onEdit && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            onClick={(e) => {
                                e.stopPropagation();
                                onEdit();
                            }}
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                    )}
                    {onDelete && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="text-destructive hover:text-destructive h-7 w-7"
                            onClick={(e) => {
                                e.stopPropagation();
                                onDelete();
                            }}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    )}
                </div>

                {/* Chevron indicator */}
                <ChevronDown className={cn('text-muted-foreground/50 h-4 w-4 shrink-0 transition-transform', isExpanded && 'rotate-180')} />
            </button>

            {/* Collapsible content */}
            <div
                className={cn(
                    'overflow-hidden transition-all duration-300 ease-in-out',
                    isExpanded ? 'max-h-[9999px] opacity-100' : 'max-h-0 opacity-0',
                )}
            >
                <div className="border-primary bg-muted/30 space-y-3 border-l-2 px-4 py-3">{children}</div>
            </div>
        </div>
    );
}
