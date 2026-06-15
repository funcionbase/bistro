import { apiFetch } from '@/lib/api';
import { useCallback, useRef } from 'react';

/**
 * Persiste el reordenamiento drag-and-drop de categorías/ítems del menú.
 *
 * El debounce se mantiene **por entidad** (un timer por id), no global: un
 * `arrayMove` desplaza los índices de todas las entidades entre la posición
 * vieja y la nueva, así que `handleDragEnd` dispara N llamadas seguidas. Con un
 * único timer compartido cada llamada cancelaba la anterior y solo persistía el
 * último PUT (pérdida de datos). El Map keyed-by-id garantiza que cada entidad
 * conserve su propio PUT.
 */
export function useMenuDrag(menuId: string) {
    const timersRef = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map());

    const scheduleUpdate = useCallback((key: string, run: () => void) => {
        const timers = timersRef.current;
        const existing = timers.get(key);
        if (existing) {
            clearTimeout(existing);
        }
        const timer = setTimeout(() => {
            timers.delete(key);
            run();
        }, 300);
        timers.set(key, timer);
    }, []);

    const updateCategoryOrder = useCallback(
        (categoryId: string, newOrder: number) => {
            scheduleUpdate(`category:${categoryId}`, () => {
                apiFetch(`/api/v1/menus/${menuId}/categories/${categoryId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: newOrder }),
                }).catch((err) => {
                    console.error('Failed to update category order:', err);
                });
            });
        },
        [menuId, scheduleUpdate],
    );

    const updateItemOrder = useCallback(
        (itemId: string, newOrder: number) => {
            scheduleUpdate(`item:${itemId}`, () => {
                apiFetch(`/api/v1/menus/${menuId}/items/${itemId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: newOrder }),
                }).catch((err) => {
                    console.error('Failed to update item order:', err);
                });
            });
        },
        [menuId, scheduleUpdate],
    );

    // Nota: no se cancelan los timers pendientes en unmount. El callback solo
    // hace `apiFetch` (sin `setState`), así que dejar que un PUT pendiente
    // dispare tras desmontar es inocuo y evita perder un reordenamiento si el
    // usuario navega dentro de la ventana de 300ms.

    return { updateCategoryOrder, updateItemOrder };
}
