import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import type { RestaurantMenu } from '@/types';

interface MenuPreviewProps {
    menu: RestaurantMenu;
}

export default function MenuPreview({ menu }: MenuPreviewProps) {
    const formatPrice = useCurrencyFormatter();

    return (
        <div className="bg-card space-y-6 p-6">
            <div className="border-border border-b pb-4">
                <h2 className="text-foreground text-2xl font-bold tracking-tight">{menu.name}</h2>
                {menu.description && <p className="text-muted-foreground mt-2">{menu.description}</p>}
            </div>

            <div className="space-y-8">
                {menu.structure.categories.map((category) => {
                    const availableItems = category.items.filter((item) => item.available);

                    if (availableItems.length === 0) {
                        return null;
                    }

                    return (
                        <div key={category.id}>
                            <h3 className="text-foreground mb-4 text-lg font-semibold">{category.name}</h3>
                            {category.description && <p className="text-muted-foreground mb-4 text-sm">{category.description}</p>}

                            <div className="space-y-3">
                                {availableItems.map((item) => (
                                    <div key={item.id} className="border-border flex gap-4 border-b pb-4">
                                        {item.image_url && <img src={item.image_url} alt={item.name} className="h-20 w-20 rounded-lg object-cover" />}
                                        <div className="flex-1">
                                            <h4 className="text-foreground font-medium">{item.name}</h4>
                                            {item.description && <p className="text-muted-foreground mt-1 text-sm">{item.description}</p>}
                                            <p className="text-foreground mt-2 font-semibold tabular-nums">{formatPrice(item.price)}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
