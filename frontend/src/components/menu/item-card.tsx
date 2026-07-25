import { AvailabilityBadge } from '@/components/menu/availability-badge';
import AvailabilityToggle from '@/components/menu/availability-toggle';
import MenuItemDetailDialog from '@/components/menu/menu-item-detail-dialog';
import RecipeEditorModal from '@/components/menu/recipe-editor-modal';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { cn } from '@/lib/utils';
import type { MenuItem } from '@/types';
import { ChefHat, Pencil, Trash2, UtensilsCrossed } from 'lucide-react';
import { useState } from 'react';

interface ItemCardProps {
    menuId: string;
    categoryId: string;
    item: MenuItem;
    onEdit: () => void;
    onDelete: () => void;
    onAvailabilityToggle?: (available: boolean) => void;
    onRecipeSaved?: () => void;
    canUpdate?: boolean;
    canDelete?: boolean;
}

export default function ItemCard({
    menuId,
    categoryId,
    item,
    onEdit,
    onDelete,
    onAvailabilityToggle,
    onRecipeSaved,
    canUpdate = true,
    canDelete = true,
}: ItemCardProps) {
    const formatPrice = useCurrencyFormatter();
    const showActions = canUpdate || canDelete;
    const [showRecipe, setShowRecipe] = useState(false);
    const [showDetail, setShowDetail] = useState(false);
    // Patrón menu-item-row: thumbnail liviano primero, original como fallback.
    const thumbSrc = (item as MenuItem & { thumbnail_url?: string | null }).thumbnail_url ?? item.image_url;

    return (
        <Card className="rounded-lg shadow-sm transition-shadow hover:shadow-md">
            <CardContent className="flex gap-4 p-4">
                <button
                    type="button"
                    onClick={() => setShowDetail(true)}
                    aria-label={`Ver detalle de ${item.name}`}
                    className="bg-muted focus:ring-ring h-20 w-20 shrink-0 overflow-hidden rounded-lg transition-transform hover:scale-[1.02] focus:ring-2 focus:outline-none"
                >
                    {thumbSrc ? (
                        <img src={thumbSrc} alt={item.name} className="h-full w-full object-cover" loading="lazy" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center">
                            <UtensilsCrossed className="text-muted-foreground/50 h-8 w-8" />
                        </div>
                    )}
                </button>

                <div className="min-w-0 flex-1">
                    <div className="mb-1 flex items-start justify-between gap-2">
                        <p className="truncate font-semibold">{item.name}</p>
                        {showActions && (
                            <div className="flex shrink-0 gap-1">
                                {canUpdate && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className={cn('h-7 w-7', item.has_recipe ? 'text-[color:var(--color-status-safe)]' : 'text-muted-foreground')}
                                        title={item.has_recipe ? 'Receta configurada' : 'Sin receta — agrega ingredientes'}
                                        onClick={() => setShowRecipe(true)}
                                    >
                                        <ChefHat className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                                {canUpdate && (
                                    <Button variant="ghost" size="icon" className="h-7 w-7" onClick={onEdit}>
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                                {canDelete && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-destructive hover:text-destructive h-7 w-7"
                                        onClick={onDelete}
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    {item.description && <p className="text-muted-foreground mb-2 line-clamp-2 text-sm">{item.description}</p>}

                    <div className="flex items-center justify-between">
                        <p className="text-primary font-semibold tabular-nums">{formatPrice(item.price)}</p>
                        <div className="flex items-center gap-2">
                            <AvailabilityBadge available={item.available} className="text-xs" />
                            {canUpdate && (
                                <AvailabilityToggle
                                    menuId={menuId}
                                    categoryId={categoryId}
                                    itemId={item.id}
                                    available={item.available}
                                    onToggle={onAvailabilityToggle}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </CardContent>
            {showRecipe && (
                <RecipeEditorModal
                    open={showRecipe}
                    onClose={() => setShowRecipe(false)}
                    menuId={menuId}
                    itemId={item.id}
                    itemName={item.name}
                    itemPrice={item.price}
                    onSaved={() => onRecipeSaved?.()}
                />
            )}
            <MenuItemDetailDialog
                item={
                    showDetail
                        ? {
                              name: item.name,
                              description: item.description,
                              image_url: item.image_url,
                              price: item.price,
                          }
                        : null
                }
                open={showDetail}
                onOpenChange={setShowDetail}
                formatPrice={formatPrice}
            />
        </Card>
    );
}
