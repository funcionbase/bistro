import { apiFetch } from '@/lib/api';
import type { RecipeResponse, RecipeUpsertPayload } from '@/types/recipes';
import { useCallback } from 'react';

/**
 * CRUD del set completo de receta (BOM) para un ítem de menú.
 *
 * El backend reemplaza el set completo por transacción al hacer PUT.
 */
export function useRecipes() {
    const fetchRecipe = useCallback(async (menuId: string, itemId: string): Promise<RecipeResponse> => {
        const res = await apiFetch(`/api/v1/menus/${menuId}/items/${itemId}/recipe`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as RecipeResponse;
    }, []);

    const upsertRecipe = useCallback(async (menuId: string, itemId: string, payload: RecipeUpsertPayload): Promise<RecipeResponse> => {
        const res = await apiFetch(`/api/v1/menus/${menuId}/items/${itemId}/recipe`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as RecipeResponse;
    }, []);

    return { fetchRecipe, upsertRecipe };
}
