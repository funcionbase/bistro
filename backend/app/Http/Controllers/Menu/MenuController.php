<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\DuplicateMenuRequest;
use App\Http\Requests\Menu\ScheduleMenuRequest;
use App\Http\Requests\Menu\StoreCategoryRequest;
use App\Http\Requests\Menu\StoreItemRequest;
use App\Http\Requests\Menu\StoreMenuRequest;
use App\Http\Requests\Menu\UpdateCategoryRequest;
use App\Http\Requests\Menu\UpdateDishAvailabilityRequest;
use App\Http\Requests\Menu\UpdateItemRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Requests\Menu\UploadDishImageRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\RestaurantMenu;
use App\Models\User;
use App\Services\Analytics\BotDetectionService;
use App\Services\AuditService;
use App\Services\BusinessHoursService;
use App\Services\CashRegisterService;
use App\Services\CompanySettingsService;
use App\Services\MenuPermissionService;
use App\Services\MenuSchedulerService;
use App\Services\RecipeCostService;
use App\Support\SignedAssetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CRUD completo de menús, categorías e ítems usando estructura JSON v3.
 *
 * Solo un menú puede estar en estado 'active' por empresa; activate() desactiva todos los demás.
 * destroy(): solo permite eliminar menús en estado 'draft'.
 * destroyItem(): bloquea la eliminación si el ítem tiene órdenes activas (pending, in_kitchen).
 * showPublic(): retorna el menú activo con solo ítems disponibles; accesible con JWT de cualquier empresa.
 * Las imágenes se almacenan en config menu.image_disk; las URLs son temporales (60 min).
 * La estructura JSON v3 incluye version, categories[] con items[] y sus campos.
 *
 * @env FILESYSTEM_DISK — disco de almacenamiento de imágenes de platos
 */
class MenuController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly MenuPermissionService $menuPermissionService,
        private readonly MenuSchedulerService $menuSchedulerService,
        private readonly BusinessHoursService $businessHoursService,
        private readonly CashRegisterService $cashRegister,
        private readonly BotDetectionService $botDetection,
        private readonly CompanySettingsService $companySettings,
        private readonly RecipeCostService $recipeCostService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $menus = RestaurantMenu::forCompany($companyNit)
            ->orderBy('status')
            ->orderBy('name')
            ->get()
            ->map(fn ($menu) => $this->formatMenuWithoutImages($menu));

        return response()->json(['data' => $menus]);
    }

    public function store(StoreMenuRequest $request): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $menu = RestaurantMenu::create([
            'company_nit' => $companyNit,
            'branch_id' => (string) request()->attributes->get('active_branch_id'),
            'name' => $request->validated()['name'],
            'description' => $request->validated()['description'] ?? null,
            'status' => 'draft',
            'structure' => ['version' => 3, 'categories' => []],
        ]);

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('menu.created', $actor, $menu, [
            'company_nit' => $companyNit,
            'name' => $menu->name,
        ], $request);

        return response()->json(['data' => $this->formatMenuWithoutImages($menu)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'read');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        return response()->json(['data' => $this->formatMenuWithImages($menu)]);
    }

    public function update(UpdateMenuRequest $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $menu->update([
            'name' => $request->validated()['name'],
            'description' => $request->validated()['description'] ?? null,
        ]);

        return response()->json(['data' => $this->formatMenuWithoutImages($menu->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'delete');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        if ($menu->status !== 'draft') {
            abort(422, 'Solo se pueden eliminar menús en estado borrador.');
        }

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('menu.deleted', $actor, $menu, [
            'company_nit' => $companyNit,
            'name' => $menu->name,
        ], $request);

        $menu->delete();

        return response()->json(['deleted' => true]);
    }

    public function duplicate(DuplicateMenuRequest $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $original = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $copy = DB::transaction(function () use ($original, $companyNit, $jwtPayload, $request) {
            $newMenu = RestaurantMenu::create([
                'company_nit' => $companyNit,
                'branch_id' => (string) request()->attributes->get('active_branch_id'),
                'name' => $original->name.' (Copia)',
                'description' => $original->description,
                'status' => 'draft',
                'structure' => $original->structure,
            ]);

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.duplicated', $actor, $newMenu, [
                'company_nit' => $companyNit,
                'original_id' => $original->id,
                'original_name' => $original->name,
            ], $request);

            return $newMenu;
        });

        return response()->json(['data' => $this->formatMenuWithoutImages($copy)], 201);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        // No permitir activar un menú programado en un día que su active_days no
        // cubre: quedaría 'active' pero el menú público no lo mostraría (enforce
        // en lectura), un estado inconsistente. active_days null (sin
        // programación) siempre pasa. Día en zona del negocio, igual que horario.
        $todayDow = Carbon::now(config('business-hours.timezone', config('app.timezone', 'UTC')))->dayOfWeek;
        if (! $menu->isScheduledForDay($todayDow)) {
            abort(422, 'Este menú está programado para días que no incluyen hoy. Quita la programación o actívalo en un día cubierto.');
        }

        DB::transaction(function () use ($menu, $companyNit, $jwtPayload, $request) {
            // Solo desactivamos otros menús de la MISMA sede. En multi-sede
            // (#117) cada branch opera su propia carta; activar Cartago no
            // debe degradar Pereira a draft, lo que dejaba esa sede sin
            // menú servible y bloqueaba la creación de órdenes ahí.
            RestaurantMenu::forCompany($companyNit)
                ->where('branch_id', $menu->branch_id)
                ->where('id', '!=', $menu->id)
                ->update(['status' => 'draft']);
            $menu->update(['status' => 'active']);

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.activated', $actor, $menu, [
                'company_nit' => $companyNit,
                'branch_id' => $menu->branch_id,
                'name' => $menu->name,
            ], $request);
        });

        return response()->json(['data' => $this->formatMenuWithoutImages($menu->fresh())]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        // Idempotente: si ya está en draft, no logueamos (evita ruido en audit).
        if ($menu->status === 'active') {
            DB::transaction(function () use ($menu, $companyNit, $jwtPayload, $request) {
                $menu->update(['status' => 'draft']);

                $actor = User::find($jwtPayload['sub'] ?? null);
                $this->auditService->log('menu.deactivated', $actor, $menu, [
                    'company_nit' => $companyNit,
                    'branch_id' => $menu->branch_id,
                    'name' => $menu->name,
                ], $request);
            });
        }

        return response()->json(['data' => $this->formatMenuWithoutImages($menu->fresh())]);
    }

    public function setSchedule(ScheduleMenuRequest $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        if ($menu->status === 'draft') {
            abort(422, 'No se puede programar un menú en estado borrador.');
        }

        $activeDays = $request->validated()['active_days'] ?? null;

        $menu->update([
            'active_days' => $activeDays,
            'status' => $activeDays !== null ? 'scheduled' : $menu->status,
        ]);

        if ($activeDays !== null) {
            $this->menuSchedulerService->sync($companyNit);
        }

        return response()->json(['data' => $this->formatMenuWithoutImages($menu->fresh())]);
    }

    public function syncSchedule(Request $request): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $result = $this->menuSchedulerService->sync($companyNit);

        return response()->json(['data' => $result]);
    }

    public function storeCategory(StoreCategoryRequest $request, string $id): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;

        if (count($structure['categories']) >= config('menu.max_categories')) {
            abort(422, 'Se alcanzó el límite máximo de categorías permitidas.');
        }

        $category = [
            // ID lógico de categoría dentro del JSON structure — uuid v7 por
            // consistencia con el resto de PKs.
            'id' => (string) Str::uuid7(),
            'name' => $request->validated()['name'],
            'description' => $request->validated()['description'] ?? null,
            'order' => $request->validated()['order'] ?? 0,
            // Mapping a estación KDS. null = fallback al is_default
            // de la sede. Validado en StoreCategoryRequest contra
            // kds_stations filtrado por (company_nit, branch_id activos).
            'kds_station_id' => $request->validated()['kds_station_id'] ?? null,
            'items' => [],
        ];

        DB::transaction(function () use ($menu, $structure, $category, $jwtPayload, $companyNit, $request) {
            $structure['categories'][] = $category;
            $menu->structure = $structure;
            $menu->save();

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.category_created', $actor, $menu, [
                'company_nit' => $companyNit,
                'category_id' => $category['id'],
                'category_name' => $category['name'],
            ], $request);
        });

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(UpdateCategoryRequest $request, string $id, string $catId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        $structure['categories'][$categoryIndex]['name'] = $request->validated()['name'];
        $structure['categories'][$categoryIndex]['description'] = $request->validated()['description'] ?? null;
        $structure['categories'][$categoryIndex]['order'] = $request->validated()['order'] ?? $structure['categories'][$categoryIndex]['order'];

        // Mapping a estación KDS. `sometimes` en la rule preserva el
        // valor previo si la key no llega en el payload; si llega como null,
        // desasocia la categoría (cae al fallback is_default).
        if ($request->has('kds_station_id')) {
            $structure['categories'][$categoryIndex]['kds_station_id'] = $request->validated()['kds_station_id'];
        }

        $menu->structure = $structure;
        $menu->save();

        return response()->json(['data' => $structure['categories'][$categoryIndex]]);
    }

    public function destroyCategory(Request $request, string $id, string $catId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'delete');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        $categoryName = $structure['categories'][$categoryIndex]['name'];

        DB::transaction(function () use ($menu, $structure, $categoryIndex, $catId, $categoryName, $jwtPayload, $companyNit, $request) {
            array_splice($structure['categories'], $categoryIndex, 1);
            $menu->structure = $structure;
            $menu->save();

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.category_deleted', $actor, $menu, [
                'company_nit' => $companyNit,
                'category_id' => $catId,
                'category_name' => $categoryName,
            ], $request);
        });

        return response()->json(['deleted' => true]);
    }

    public function storeItem(StoreItemRequest $request, string $id, string $catId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        if (count($structure['categories'][$categoryIndex]['items']) >= config('menu.max_items_per_category')) {
            abort(422, 'Se alcanzó el límite máximo de ítems por categoría.');
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $disk = $menu->getConfiguredDisk();
            $imagePath = $request->file('image')->store("menus/{$companyNit}/items", $disk);
        }

        $validated = $request->validated();
        $item = [
            // ID lógico de item dentro del JSON structure — uuid v7 por
            // consistencia con el resto de PKs.
            'id' => (string) Str::uuid7(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => (float) $validated['price'],
            // Costo unitario opcional (sensible — nunca expuesto vía showPublic).
            'cost' => isset($validated['cost']) ? (float) $validated['cost'] : null,
            'image_path' => $imagePath,
            'available' => $validated['available'] ?? true,
            'order' => 0,
            // Override tributario opcional. null → hereda companies.default_tax_rate.
            'tax_rate' => isset($validated['tax_rate']) ? (float) $validated['tax_rate'] : null,
            'tax_label' => $validated['tax_label'] ?? null,
        ];

        DB::transaction(function () use ($menu, $structure, $categoryIndex, $item, $jwtPayload, $companyNit, $request) {
            $structure['categories'][$categoryIndex]['items'][] = $item;
            $menu->structure = $structure;
            $menu->save();

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.item_created', $actor, $menu, [
                'company_nit' => $companyNit,
                'item_id' => $item['id'],
                'item_name' => $item['name'],
            ], $request);
        });

        $item['image_url'] = $this->buildImageUrl($imagePath, $menu->getConfiguredDisk());

        return response()->json(['data' => $item], 201);
    }

    public function updateItem(UpdateItemRequest $request, string $id, string $catId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        $itemIndex = $this->findItemIndex($structure['categories'][$categoryIndex]['items'], $itemId);

        if ($itemIndex === -1) {
            abort(404, 'Ítem no encontrado.');
        }

        $item = &$structure['categories'][$categoryIndex]['items'][$itemIndex];
        $disk = $menu->getConfiguredDisk();

        if (isset($request->validated()['name'])) {
            $item['name'] = $request->validated()['name'];
        }
        if (array_key_exists('description', $request->validated())) {
            $item['description'] = $request->validated()['description'];
        }
        if (isset($request->validated()['price'])) {
            $item['price'] = (float) $request->validated()['price'];
        }
        if (array_key_exists('cost', $request->validated())) {
            $c = $request->validated()['cost'];
            $item['cost'] = $c === null ? null : (float) $c;
        }
        if (isset($request->validated()['available'])) {
            $item['available'] = $request->validated()['available'];
        }
        if (array_key_exists('tax_rate', $request->validated())) {
            $tr = $request->validated()['tax_rate'];
            $item['tax_rate'] = $tr === null ? null : (float) $tr;
        }
        if (array_key_exists('tax_label', $request->validated())) {
            $item['tax_label'] = $request->validated()['tax_label'];
        }
        if ($request->hasFile('image')) {
            if ($item['image_path']) {
                Storage::disk($disk)->delete($item['image_path']);
            }
            $item['image_path'] = $request->file('image')->store("menus/{$companyNit}/items", $disk);
        }

        $menu->structure = $structure;
        $menu->save();

        $item['image_url'] = $this->buildImageUrl($item['image_path'], $disk);

        return response()->json(['data' => $item]);
    }

    public function updateItemDirectly(UpdateItemRequest $request, string $id, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $disk = $menu->getConfiguredDisk();

        foreach ($structure['categories'] as $catIndex => $category) {
            $itemIndex = $this->findItemIndex($category['items'], $itemId);
            if ($itemIndex !== -1) {
                $item = &$structure['categories'][$catIndex]['items'][$itemIndex];

                if (isset($request->validated()['name'])) {
                    $item['name'] = $request->validated()['name'];
                }
                if (array_key_exists('description', $request->validated())) {
                    $item['description'] = $request->validated()['description'];
                }
                if (isset($request->validated()['price'])) {
                    $item['price'] = (float) $request->validated()['price'];
                }
                if (array_key_exists('cost', $request->validated())) {
                    $c = $request->validated()['cost'];
                    $item['cost'] = $c === null ? null : (float) $c;
                }
                if (isset($request->validated()['available'])) {
                    $item['available'] = $request->validated()['available'];
                }
                if (array_key_exists('tax_rate', $request->validated())) {
                    $tr = $request->validated()['tax_rate'];
                    $item['tax_rate'] = $tr === null ? null : (float) $tr;
                }
                if (array_key_exists('tax_label', $request->validated())) {
                    $item['tax_label'] = $request->validated()['tax_label'];
                }
                if ($request->hasFile('image')) {
                    if ($item['image_path']) {
                        Storage::disk($disk)->delete($item['image_path']);
                    }
                    $item['image_path'] = $request->file('image')->store("menus/{$companyNit}/items", $disk);
                }

                $menu->structure = $structure;
                $menu->save();

                $item['image_url'] = $this->buildImageUrl($item['image_path'], $disk);

                return response()->json(['data' => $item]);
            }
        }

        abort(404, 'Ítem no encontrado.');
    }

    public function updateItemAvailability(UpdateDishAvailabilityRequest $request, string $id, string $catId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        $itemIndex = $this->findItemIndex($structure['categories'][$categoryIndex]['items'], $itemId);

        if ($itemIndex === -1) {
            abort(404, 'Ítem no encontrado.');
        }

        $available = $request->validated()['available'];
        $structure['categories'][$categoryIndex]['items'][$itemIndex]['available'] = $available;

        DB::transaction(function () use ($menu, $structure, $itemId, $available, $jwtPayload, $companyNit, $request) {
            $menu->structure = $structure;
            $menu->save();

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.item_availability_changed', $actor, $menu, [
                'company_nit' => $companyNit,
                'item_id' => $itemId,
                'available' => $available,
            ], $request);
        });

        return response()->json(['data' => $structure['categories'][$categoryIndex]['items'][$itemIndex]]);
    }

    public function destroyItem(Request $request, string $id, string $catId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'delete');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $categoryIndex = $this->findCategoryIndex($structure['categories'], $catId);

        if ($categoryIndex === -1) {
            abort(404, 'Categoría no encontrada.');
        }

        $itemIndex = $this->findItemIndex($structure['categories'][$categoryIndex]['items'], $itemId);

        if ($itemIndex === -1) {
            abort(404, 'Ítem no encontrado.');
        }

        // La columna `items` es JSON (no JSONB), así que whereJsonContains no funciona en PostgreSQL.
        // Cast explícito a jsonb para usar el operador de contención @>.
        $hasActiveOrders = Order::where('company_nit', $companyNit)
            ->whereIn('status', ['pending', 'in_kitchen'])
            ->whereRaw('items::jsonb @> ?', [json_encode([['id' => $itemId]])])
            ->exists();

        if ($hasActiveOrders) {
            abort(422, 'No se puede eliminar un ítem con órdenes activas.');
        }

        $itemName = $structure['categories'][$categoryIndex]['items'][$itemIndex]['name'];
        $imagePath = $structure['categories'][$categoryIndex]['items'][$itemIndex]['image_path'] ?? null;
        $disk = $menu->getConfiguredDisk();

        DB::transaction(function () use ($menu, $structure, $categoryIndex, $itemIndex, $itemId, $itemName, $imagePath, $disk, $jwtPayload, $companyNit, $request) {
            array_splice($structure['categories'][$categoryIndex]['items'], $itemIndex, 1);
            $menu->structure = $structure;
            $menu->save();

            if ($imagePath) {
                Storage::disk($disk)->delete($imagePath);
            }

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('menu.item_deleted', $actor, $menu, [
                'company_nit' => $companyNit,
                'item_id' => $itemId,
                'item_name' => $itemName,
            ], $request);
        });

        return response()->json(['deleted' => true]);
    }

    public function uploadDishImage(UploadDishImageRequest $request, string $id, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $menu = RestaurantMenu::where('id', $id)->where('company_nit', $companyNit)->firstOrFail();

        $structure = $menu->structure;
        $disk = $menu->getConfiguredDisk();

        foreach ($structure['categories'] as $catIndex => $category) {
            $itemIndex = $this->findItemIndex($category['items'], $itemId);
            if ($itemIndex !== -1) {
                $item = &$structure['categories'][$catIndex]['items'][$itemIndex];

                if ($item['image_path']) {
                    Storage::disk($disk)->delete($item['image_path']);
                }

                $item['image_path'] = $request->file('image')->store("menus/{$companyNit}/items", $disk);

                $menu->structure = $structure;
                $menu->save();

                $actor = User::find($jwtPayload['sub'] ?? null);
                $this->auditService->log('menu.item_image_uploaded', $actor, $menu, [
                    'company_nit' => $companyNit,
                    'item_id' => $itemId,
                    'item_name' => $item['name'],
                ], $request);

                $item['image_url'] = $this->buildImageUrl($item['image_path'], $disk);

                return response()->json(['data' => $item]);
            }
        }

        abort(404, 'Ítem no encontrado.');
    }

    public function showPublic(Request $request, string $companyNit): JsonResponse
    {
        $company = Company::where('nit', $companyNit)->firstOrFail();

        $restaurant = $this->buildRestaurantPayload($company);

        // Si la empresa está bloqueada por mora, el menú público se
        // presenta como "no disponible" SIN revelar el motivo al comensal.
        // Reusa el mismo formato 423 que ya emite el flujo de horario cerrado
        // para que la UI no distinga visualmente la causa.
        if (! $company->canServePublic()) {
            return response()->json([
                'data' => null,
                'restaurant' => $restaurant,
                'restaurant_status' => [
                    'is_open' => false,
                    'reason' => 'unavailable',
                    'message' => 'La empresa no está disponible en este momento.',
                ],
            ], 423);
        }

        // Sede efectiva: la del QR/mesa (?branch_id=) o la sede por defecto de
        // la empresa. Horario, excepciones y menú se evalúan POR SEDE — las
        // sedes son independientes (una puede estar abierta y otra no) y no
        // comparten carta. Sin esto, el flujo público (sin active_branch_id)
        // tomaría una sede arbitraria y podría cerrar/abrir otra por error.
        $requestedBranchId = (string) $request->query('branch_id', '');
        $branchId = $requestedBranchId !== ''
            ? $requestedBranchId
            : (Branch::resolveDefault($company->nit)?->id ?? '');
        $branchScope = $branchId !== '' ? $branchId : null;

        $hoursStatus = $this->businessHoursService->getCurrentStatus($company->nit, null, $branchScope);

        if (! $hoursStatus['menu_available']) {
            return response()->json([
                'data' => null,
                'restaurant' => $restaurant,
                'restaurant_status' => [
                    'is_open' => false,
                    'reason' => $hoursStatus['reason'],
                    'next_opening' => $hoursStatus['next_opening'],
                ],
            ], 423);
        }

        // Bloqueo del menú público si la caja está cerrada: el restaurante no
        // puede recibir órdenes (los receipts requieren sesión activa, validado
        // en OrderController). Coherente con el flujo: si los cobros fallarían,
        // ofrecer el menú genera frustración al cliente.
        //
        // Multi-sede (#117): se evalúa por SEDE (la resuelta arriba). Ruta
        // pública sin `active_branch_id` → el BranchScope global NO aplica, así
        // que el filtro de sede DEBE ser explícito; si no, una sede con caja
        // abierta dejaría el menú de otra sede (cerrada) disponible por error.
        if ($branchScope === null || ! $this->cashRegister->activeSessionForBranch($company->nit, $branchScope)) {
            return response()->json([
                'data' => null,
                'restaurant' => $restaurant,
                'restaurant_status' => [
                    'is_open' => false,
                    'reason' => 'cash_register_closed',
                    'message' => 'La empresa no está recibiendo órdenes en este momento.',
                ],
            ], 423);
        }

        // Menú de la sede efectiva (resuelta arriba). 3er nivel de precedencia
        // (Programación de menú): además de estar 'active', el menú debe estar
        // programado para HOY según su active_days. Lo validamos en lectura —no
        // solo vía el cron `menus:sync-schedule`— porque `activate()` puede
        // dejar un menú 'active' ignorando active_days y hay ventana de
        // staleness hasta el siguiente tick. Día en zona del negocio, igual que
        // el horario (0=domingo).
        $todayDow = Carbon::now(config('business-hours.timezone', config('app.timezone', 'UTC')))->dayOfWeek;

        $menuQuery = RestaurantMenu::withoutBranchScope()
            ->forCompany($company->nit)
            ->active();

        if ($branchId !== '') {
            $menuQuery->where('branch_id', $branchId);
        }

        $menu = $menuQuery
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (RestaurantMenu $candidate) => $candidate->isScheduledForDay($todayDow));

        if (! $menu) {
            return response()->json(['data' => null, 'restaurant' => $restaurant], 404);
        }

        $structure = $menu->structure;
        $disk = $menu->getConfiguredDisk();

        $thumbWidth = (int) config('mobile.menu_thumbnail_width', 400);
        $thumbHeight = (int) config('mobile.menu_thumbnail_height', 300);

        foreach ($structure['categories'] as &$category) {
            $category['items'] = array_values(array_filter(
                $category['items'],
                fn ($item) => $item['available'] === true,
            ));
            foreach ($category['items'] as &$item) {
                // No exponer el costo unitario al menú público — es información
                // sensible competitivamente. Se mantiene en el editor solamente.
                unset($item['cost']);
                $imageUrl = $this->buildImageUrl($item['image_path'] ?? null, $disk);
                $item['image_url'] = $imageUrl;
                $item['thumbnail_url'] = $imageUrl
                    ? $imageUrl.'?w='.$thumbWidth.'&h='.$thumbHeight
                    : null;
            }
        }

        return response()->json([
            'data' => [
                'id' => $menu->id,
                'company_nit' => $menu->company_nit,
                'name' => $menu->name,
                'description' => $menu->description,
                'status' => $menu->status,
                'structure' => $structure,
                'is_mobile_optimized' => true,
                'thumbnail_config' => [
                    'width' => $thumbWidth,
                    'height' => $thumbHeight,
                ],
            ],
            'restaurant' => $restaurant,
        ]);
    }

    /**
     * Branding mostrado por el cliente público en el header del menú.
     * Se incluye en todas las variantes (200/423/404) para que la página
     * pública pueda renderizar la cabecera incluso cuando el restaurante
     * está cerrado o sin menú activo.
     *
     * @return array{commercial_name: string, logo_url: ?string, primary_color: string}
     */
    private function buildRestaurantPayload(Company $company): array
    {
        return [
            'commercial_name' => $company->commercial_name,
            'logo_url' => SignedAssetUrl::for($company->logo_path),
            'primary_color' => (string) $this->companySettings->get($company->nit, 'menu_primary_color', '#FF6B35'),
        ];
    }

    /**
     * Telemetría pública: registra un escaneo del QR del menú.
     *
     * Sin auth, idempotente por session_id (dedupe 60s vía cache), append-only,
     * sin transacción ni AuditLog (no es operación financiera). Heurísticas de
     * BotDetectionService marcan filas sospechosas como is_bot=true en lugar de
     * descartar — los reportes filtran via índices parciales.
     */
    public function recordScan(Request $request, string $nit): JsonResponse
    {
        $validated = $request->validate([
            'table' => 'nullable|string|max:16',
            'session_id' => 'nullable|uuid',
            '_h' => 'nullable|string|max:64',
        ]);

        $company = Company::query()->where('nit', $nit)->first();
        if (! $company) {
            // 204 silencioso aunque no exista: evita filtración de NITs y los
            // bots reciben la misma respuesta para cualquier nit inventado.
            return response()->json(null, 204);
        }

        // Empresa bloqueada por mora — 204 silencioso (no registramos
        // el scan, no revelamos motivo). El comensal igual ve "no disponible"
        // al abrir el menú.
        if (! $company->canServePublic()) {
            return response()->json(null, 204);
        }

        $sessionId = $validated['session_id'] ?? null;
        if ($sessionId !== null) {
            $dedupeKey = "menu_scan:dedupe:{$sessionId}";
            if (Cache::has($dedupeKey)) {
                return response()->json(null, 204);
            }
            Cache::put($dedupeKey, 1, now()->addSeconds(60));
        }

        $isBot = $this->botDetection->isBot($request, $company->nit);
        $ipHashHex = $this->botDetection->hashIp($request->ip());

        // Multi-sede (#117): menu_scan_events.branch_id es NOT NULL. El menú
        // público es por empresa (un único QR), así que asociamos el scan a la
        // sede default. Si no hay sedes (caso edge), retornamos 204 sin
        // registrar — el cliente no detecta diferencia.
        $defaultBranch = Branch::query()
            ->where('company_nit', $company->nit)
            ->whereNull('archived_at')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();

        if (! $defaultBranch) {
            return response()->json(null, 204);
        }

        // bytea via parámetro: Postgres requiere o `\x...` o decode('hex', 'hex').
        // Usamos INSERT raw para que `decode(?, 'hex')` mantenga binding seguro.
        DB::statement(<<<'SQL'
            INSERT INTO menu_scan_events
                (company_nit, branch_id, table_number, scanned_at, session_id, user_agent, ip_hash, is_bot)
            VALUES (?, ?::uuid, ?, now(), ?::uuid, ?, CASE WHEN ?::text IS NULL THEN NULL ELSE decode(?, 'hex') END, ?)
        SQL, [
            $company->nit,
            $defaultBranch->id,
            $validated['table'] ?? null,
            $sessionId,
            mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            $ipHashHex,
            $ipHashHex,
            $isBot,
        ]);

        return response()->json(null, 204);
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function findCategoryIndex(array $categories, string $categoryId): int
    {
        foreach ($categories as $index => $category) {
            if ($category['id'] === $categoryId) {
                return $index;
            }
        }

        return -1;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function findItemIndex(array $items, string $itemId): int
    {
        foreach ($items as $index => $item) {
            if ($item['id'] === $itemId) {
                return $index;
            }
        }

        return -1;
    }

    private function buildImageUrl(?string $path, ?string $disk = null): ?string
    {
        return SignedAssetUrl::for($path, $disk);
    }

    /** @return array<string, mixed> */
    private function formatMenuWithoutImages(RestaurantMenu $menu): array
    {
        return [
            'id' => $menu->id,
            'company_nit' => $menu->company_nit,
            'name' => $menu->name,
            'description' => $menu->description,
            'status' => $menu->status,
            'active_days' => $menu->active_days,
            'structure' => $menu->structure,
            'created_at' => $menu->created_at,
            'updated_at' => $menu->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function formatMenuWithImages(RestaurantMenu $menu): array
    {
        $structure = $menu->structure;
        $disk = $menu->getConfiguredDisk();

        foreach ($structure['categories'] as &$category) {
            foreach ($category['items'] as &$item) {
                $item['image_url'] = $this->buildImageUrl($item['image_path'] ?? null, $disk);
                // Costo computado desde la receta (BOM) si existe, con fallback
                // al costo manual legacy. `cost_source` permite a la UI mostrar
                // si el costo viene de receta (read-only) o es manual (editable).
                $computed = $this->recipeCostService->compute($menu->company_nit, (string) $menu->branch_id, $item['id']);
                $hasRecipe = ! empty($computed['breakdown']);
                if ($hasRecipe) {
                    $item['cost'] = (float) $computed['total_cost'];
                    $item['cost_source'] = 'recipe';
                } else {
                    $item['cost_source'] = 'manual';
                }
                $item['has_recipe'] = $hasRecipe;
            }
        }

        return [
            'id' => $menu->id,
            'company_nit' => $menu->company_nit,
            'name' => $menu->name,
            'description' => $menu->description,
            'status' => $menu->status,
            'active_days' => $menu->active_days,
            'structure' => $structure,
            'created_at' => $menu->created_at,
            'updated_at' => $menu->updated_at,
        ];
    }
}
