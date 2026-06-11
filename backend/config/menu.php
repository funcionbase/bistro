<?php

return [
    // Default 'public' para que `Storage::url()` devuelva la URL servida por
    // el symlink `public/storage → storage/app/public`. El disco `local` apunta
    // a `storage/app/private` (NO expuesto), por eso usarlo aquí causaba 403.
    // En QA/PDN el .env debería overridear a 's3' para servir desde el bucket.
    'image_disk' => env('MENU_IMAGE_DISK', 'public'),
    'image_max_size_kb' => (int) env('MENU_IMAGE_MAX_SIZE_KB', 2048),
    'max_categories' => (int) env('MENU_MAX_CATEGORIES', 20),
    'max_items_per_category' => (int) env('MENU_MAX_ITEMS_PER_CATEGORY', 50),

    // Recetas (BOM) por ítem de menú.
    // - units: lista cerrada que coincide con `ingredients.unit`. Se valida en
    //   migración (CHECK), FormRequest y UnitConverter.
    // - low_margin_threshold: umbral usado por la UI para mostrar el badge
    //   "⚠ margen bajo" en el editor (no bloquea guardar). 0.20 = 20%.
    'recipe' => [
        'units' => ['kg', 'g', 'l', 'ml', 'un'],
        'low_margin_threshold' => (float) env('MENU_RECIPE_LOW_MARGIN_THRESHOLD', 0.20),
    ],
];
