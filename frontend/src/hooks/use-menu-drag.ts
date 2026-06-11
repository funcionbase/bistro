import { apiFetch } from '@/lib/api';
import { useCallback, useRef } from 'react';

export function useMenuDrag(menuId: string) {
    const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const updateCategoryOrder = useCallback(
        (categoryId: string, newOrder: number) => {
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
            }

            debounceTimerRef.current = setTimeout(() => {
                apiFetch(`/api/v1/menus/${menuId}/categories/${categoryId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: newOrder }),
                }).catch((err) => {
                    console.error('Failed to update category order:', err);
                });
            }, 300);
        },
        [menuId],
    );

    const updateItemOrder = useCallback(
        (itemId: string, newOrder: number) => {
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
            }

            debounceTimerRef.current = setTimeout(() => {
                apiFetch(`/api/v1/menus/${menuId}/items/${itemId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: newOrder }),
                }).catch((err) => {
                    console.error('Failed to update item order:', err);
                });
            }, 300);
        },
        [menuId],
    );

    return { updateCategoryOrder, updateItemOrder };
}
