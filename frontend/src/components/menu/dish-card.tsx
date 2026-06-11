import { AvailabilityBadge } from '@/components/menu/availability-badge';
import AvailabilityToggle from '@/components/menu/availability-toggle';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import type { MenuItem } from '@/types';
import { Pencil, Trash2, UtensilsCrossed } from 'lucide-react';

interface DishCardProps {
    menuId: string;
    categoryId: string;
    dish: MenuItem;
    onEdit: () => void;
    onDelete: () => void;
    onAvailabilityToggle?: (available: boolean) => void;
}

export default function DishCard({ menuId, categoryId, dish, onEdit, onDelete, onAvailabilityToggle }: DishCardProps) {
    const formatPrice = useCurrencyFormatter();

    return (
        <Card className="rounded-lg shadow-sm transition-shadow hover:shadow-md">
            <CardContent className="flex gap-4 p-4">
                <div className="bg-muted h-20 w-20 shrink-0 overflow-hidden rounded-lg">
                    {dish.image_url ? (
                        <img src={dish.image_url} alt={dish.name} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center">
                            <UtensilsCrossed className="text-muted-foreground/50 h-8 w-8" />
                        </div>
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="mb-1 flex items-start justify-between gap-2">
                        <p className="truncate font-semibold">{dish.name}</p>
                        <div className="flex shrink-0 gap-1">
                            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={onEdit}>
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive h-7 w-7" onClick={onDelete}>
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    </div>

                    {dish.description && <p className="text-muted-foreground mb-2 line-clamp-2 text-sm">{dish.description}</p>}

                    <div className="flex items-center justify-between">
                        <p className="text-primary font-semibold tabular-nums">{formatPrice(dish.price)}</p>
                        <div className="flex items-center gap-2">
                            <AvailabilityBadge available={dish.available} className="text-xs" />
                            <AvailabilityToggle
                                menuId={menuId}
                                categoryId={categoryId}
                                itemId={dish.id}
                                available={dish.available}
                                onToggle={onAvailabilityToggle}
                            />
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
