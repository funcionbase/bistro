export type IngredientUnit = 'kg' | 'g' | 'l' | 'ml' | 'un';

export type IngredientMovementType = 'entry' | 'adjustment' | 'sale_consumption' | 'waste' | 'transfer';

export type WarehouseType = 'main' | 'kitchen' | 'bar' | 'cold_storage' | 'dry_storage';

/** Asignación de una bodega (company-scoped) a una sede vía pivot. */
export interface WarehouseBranchAssignment {
    branch_id: string;
    is_default: boolean;
}

export interface Warehouse {
    id: string;
    name: string;
    slug: string;
    type: WarehouseType;
    /** Informativo a nivel empresa: si la bodega es default en alguna sede. */
    is_default: boolean;
    /** Sedes a las que está asignada la bodega (multibodega). */
    branches: WarehouseBranchAssignment[];
    archived_at: string | null;
    created_at?: string | null;
}

export interface IngredientStock {
    warehouse_id: string;
    name: string; // warehouse name (snapshot)
    quantity: string;
    min_stock: string;
    /** WAC (costo promedio ponderado) del insumo en esta bodega. */
    current_cost: string;
}

export interface Ingredient {
    id: string;
    name: string;
    category: string | null;
    unit: IngredientUnit;
    current_cost: string;
    total_stock: string; // suma de stocks por bodega (decimal:3)
    is_low_stock: boolean;
    stocks: IngredientStock[];
    filtered_stock: IngredientStock | null; // si se filtró por warehouse_id en la query
    archived_at: string | null;
}

export interface IngredientFormPayload {
    name: string;
    category?: string | null;
    unit: IngredientUnit;
    warehouse_id?: string;
    min_stock?: number;
    initial_stock?: number;
    initial_cost?: number;
    reference?: string;
}

export interface IngredientMovement {
    id: string;
    type: IngredientMovementType;
    quantity: string;
    unit_cost: string | null;
    warehouse_id: string | null;
    /** Nombre de la bodega del movimiento (origen en transfers). */
    warehouse_name: string | null;
    dest_warehouse_id: string | null;
    /** Nombre de la bodega contraparte en transfers (destino/origen según la pata). */
    dest_warehouse_name: string | null;
    reference: string | null;
    created_at: string | null;
    actor: { id: string; name: string } | null;
}

export interface IngredientListMeta {
    low_stock_count: number;
    categories: string[];
}

export interface IngredientPagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface IngredientListResponse {
    data: Ingredient[];
    pagination: IngredientPagination;
    meta: IngredientListMeta;
}

export interface MovementResponse {
    data: {
        movement: IngredientMovement;
        ingredient: { id: string; current_cost: string };
        stock: { warehouse_id: string; warehouse_name: string; quantity: string; min_stock: string };
    };
}

export interface TransferPayload {
    from_warehouse_id: string;
    to_warehouse_id: string;
    ingredient_id: string;
    quantity: number;
    reference?: string;
}
