import type { IngredientUnit } from './inventory';

export interface RecipeLine {
    recipe_id: string;
    ingredient_id: string;
    ingredient_name: string;
    ingredient_unit: IngredientUnit;
    recipe_quantity: string;
    recipe_unit: IngredientUnit;
    normalized_quantity: string;
    unit_cost: string;
    line_cost: string;
    /** Bodega desde la que costea esta línea (recipes.warehouse_id NOT NULL). */
    warehouse_id: string;
    /** true cuando no hay stock para (insumo, bodega): unit_cost=0 y la línea está mal configurada. */
    misconfigured: boolean;
}

export interface RecipeResponse {
    menu_item_id: string;
    item: {
        id: string;
        name: string | null;
        price: number;
        category: string | null;
    };
    items: RecipeLine[];
    total_cost: string;
    margin_pct: number | null;
    low_margin: boolean;
}

export interface RecipeUpsertPayload {
    items: Array<{
        ingredient_id: string;
        quantity: number | string;
        unit: IngredientUnit;
        /** Bodega de costeo. Si se omite, el backend usa la bodega default de la sede. */
        warehouse_id?: string;
    }>;
}
