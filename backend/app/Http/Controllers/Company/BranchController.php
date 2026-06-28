<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\StoreCashRegisterRequest;
use App\Http\Requests\CashRegister\UpdateCashRegisterRequest;
use App\Http\Requests\Company\StoreBranchRequest;
use App\Http\Requests\Company\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\BusinessType;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\CompanyUser;
use App\Models\KdsStation;
use App\Models\PrepArea;
use App\Models\RestaurantMenu;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\BranchSettingsService;
use App\Support\SignedAssetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CRUD de sedes y asignación de usuarios. Todas las operaciones se anclan a
 * `active_company_nit` del JWT — un usuario nunca puede operar sedes de otra empresa.
 *
 * Reglas:
 *  - El "borrado" es soft archive (archived_at). Las FK son onDelete restrict.
 *  - is_default: marcar una sede como default desmarca cualquier otra default activa.
 *  - copyMenu: duplica la estructura JSON del menú origen como menú draft en la sede destino.
 *    Tras la copia los menús son independientes (no hay vínculo).
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly BranchSettingsService $branchSettings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $includeArchived = $request->boolean('include_archived');

        $branches = Branch::query()
            ->where('company_nit', $nit)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $branches->map(fn (Branch $b) => $this->toArray($b))->values()->all(),
        ]);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $data = $request->validated();
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        $branch = DB::transaction(function () use ($nit, $data, $actorId) {
            if (($data['is_default'] ?? false) === true) {
                Branch::query()->where('company_nit', $nit)->where('is_default', true)->update(['is_default' => false]);
            }

            // #237 — vertical default 'restaurant' si no llega. Sembramos
            // prep_areas del vertical recién creada en la misma transacción.
            $businessTypeSlug = $data['business_type_id'] ?? 'restaurant';
            $businessType = BusinessType::find($businessTypeSlug);
            abort_if($businessType === null, 422, 'Tipo de negocio inválido.');

            $branch = Branch::create([
                'company_nit' => $nit,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'business_type_id' => $businessType->slug,
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            foreach ($businessType->prep_area_defaults ?? [] as $i => $area) {
                PrepArea::create([
                    'branch_id' => $branch->id,
                    'slug' => $area['slug'],
                    'label' => $area['label'],
                    'color' => $area['color'] ?? '#64748b',
                    'icon_key' => $area['icon_key'] ?? null,
                    'display_order' => $i,
                ]);
            }

            // Auto-asignar al creador (y a todos los owners de la empresa) para que
            // la sede aparezca de inmediato en su sidebar y puedan operar sin tener
            // que ir al modal de "Usuarios". Sin esto, el creador no podría acceder
            // a la sede que acaba de crear hasta asignársela explícitamente.
            $ownerUserIds = CompanyUser::query()
                ->where('company_nit', $nit)
                ->whereHas('role', fn ($q) => $q->where('is_system', true))
                ->pluck('user_id')
                ->push($actorId)
                ->unique()
                ->filter()
                ->all();

            foreach ($ownerUserIds as $uid) {
                BranchUser::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'user_id' => $uid],
                    ['granted_by_user_id' => $actorId, 'granted_at' => now()],
                );
            }

            // KDS (#115): cada sede nueva nace con sus 4 estaciones canónicas
            // (caliente/fría/barra/fritos). El owner puede renombrar/archivar
            // desde /company/settings → KDS.
            KdsStation::seedDefaultsForBranch($nit, $branch->id);

            // Inventario (#costeo-multibodega, regla D3): si la empresa tiene
            // exactamente una bodega, se asigna automáticamente como default de
            // la sede nueva; si tiene 2+, la sede arranca sin bodega y la
            // asignación es manual (bloqueo duro BRANCH_HAS_NO_WAREHOUSE hasta
            // que se asigne). Empresa sin bodegas: se siembra la "principal".
            Warehouse::ensureDefaultForBranch($nit, $branch->id);

            return $branch;
        });

        $this->auditService->log('branch.created', null, $branch, [
            'branch_id' => $branch->id,
            'slug' => $branch->slug,
            'is_default' => $branch->is_default,
        ]);

        return response()->json(['data' => $this->toArray($branch->fresh())], 201);
    }

    public function update(UpdateBranchRequest $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);
        $data = $request->validated();
        $before = $model->only(['name', 'slug', 'address', 'city', 'is_default']);

        DB::transaction(function () use ($model, $data) {
            if (array_key_exists('is_default', $data) && $data['is_default'] === true) {
                Branch::query()
                    ->where('company_nit', $model->company_nit)
                    ->where('id', '!=', $model->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $model->fill($data)->save();
        });

        $this->auditService->log('branch.updated', null, $model, [
            'branch_id' => $model->id,
            'before' => $before,
            'after' => $model->fresh()->only(array_keys($before)),
        ]);

        return response()->json(['data' => $this->toArray($model->fresh())]);
    }

    public function destroy(Request $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);

        if ($model->isArchived()) {
            return response()->json(['message' => 'La sede ya está archivada.'], 422);
        }

        $remainingActive = Branch::query()
            ->where('company_nit', $model->company_nit)
            ->whereNull('archived_at')
            ->where('id', '!=', $model->id)
            ->count();

        if ($remainingActive === 0) {
            return response()->json([
                'message' => 'No puedes archivar la única sede activa de la empresa.',
                'code' => 'LAST_ACTIVE_BRANCH',
            ], 422);
        }

        $model->forceFill(['archived_at' => now(), 'is_default' => false])->save();

        $this->auditService->log('branch.archived', null, $model, [
            'branch_id' => $model->id,
            'slug' => $model->slug,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Asigna un usuario (existente como CompanyUser) a la sede dada.
     */
    public function attachUser(Request $request, string $branch): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $model = $this->resolveBranch($request, $branch);
        $userId = (string) $request->input('user_id');
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        $isMember = CompanyUser::query()
            ->where('company_nit', $model->company_nit)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'El usuario no es miembro de esta empresa.',
                'code' => 'USER_NOT_COMPANY_MEMBER',
            ], 422);
        }

        BranchUser::query()->updateOrCreate(
            ['branch_id' => $model->id, 'user_id' => $userId],
            ['granted_by_user_id' => $actorId, 'granted_at' => now()],
        );

        $this->auditService->log('branch.user_attached', null, $model, [
            'branch_id' => $model->id,
            'user_id' => $userId,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Asigna o quita un conjunto de usuarios a una sede en una sola operación.
     *
     * Body: {branch_id, user_ids[], action: 'attach'|'detach'}
     * Permiso: branches.assign_users.
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'string', 'uuid'],
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['uuid', 'exists:users,id'],
            'action' => ['required', 'string', 'in:attach,detach'],
        ]);

        $branch = $this->resolveBranch($request, (string) $validated['branch_id']);
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        // Sólo procesa usuarios que sean miembros activos de la empresa, los demás se ignoran.
        $companyMemberIds = CompanyUser::query()
            ->where('company_nit', $branch->company_nit)
            ->whereIn('user_id', $validated['user_ids'])
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($branch, $companyMemberIds, $actorId, $validated) {
            if ($validated['action'] === 'attach') {
                foreach ($companyMemberIds as $uid) {
                    BranchUser::query()->updateOrCreate(
                        ['branch_id' => $branch->id, 'user_id' => $uid],
                        ['granted_by_user_id' => $actorId, 'granted_at' => now()],
                    );
                }
            } else {
                BranchUser::query()
                    ->where('branch_id', $branch->id)
                    ->whereIn('user_id', $companyMemberIds)
                    ->delete();
            }
        });

        $this->auditService->log("branch.users_bulk_{$validated['action']}", null, $branch, [
            'branch_id' => $branch->id,
            'user_ids' => $companyMemberIds,
            'requested_count' => count($validated['user_ids']),
            'applied_count' => count($companyMemberIds),
        ]);

        return response()->json([
            'ok' => true,
            'applied' => count($companyMemberIds),
            'skipped' => count($validated['user_ids']) - count($companyMemberIds),
        ]);
    }

    public function detachUser(Request $request, string $branch, string $userId): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);

        BranchUser::query()
            ->where('branch_id', $model->id)
            ->where('user_id', $userId)
            ->delete();

        $this->auditService->log('branch.user_detached', null, $model, [
            'branch_id' => $model->id,
            'user_id' => $userId,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Lista los usuarios asignados a una sede (con metadatos de quién los asignó).
     */
    public function users(Request $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);

        $users = $model->users()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'granted_at' => optional($u->pivot->granted_at)->toIso8601String(),
            ])->values()->all();

        return response()->json(['data' => $users]);
    }

    /**
     * Copia el menú activo de una sede origen como menú draft de la sede destino.
     * Tras la copia los menús son independientes (sin vínculo persistido).
     */
    public function copyMenu(Request $request, string $branch): JsonResponse
    {
        $request->validate([
            'source_branch_id' => ['required', 'string', 'uuid', 'different:'.$branch],
        ]);

        $target = $this->resolveBranch($request, $branch);
        $source = $this->resolveBranch($request, (string) $request->input('source_branch_id'));

        $sourceMenu = RestaurantMenu::query()
            ->where('company_nit', $source->company_nit)
            ->where('branch_id', $source->id)
            ->where('status', 'active')
            ->first();

        if ($sourceMenu === null) {
            return response()->json([
                'message' => 'La sede origen no tiene un menú activo para copiar.',
                'code' => 'SOURCE_MENU_NOT_FOUND',
            ], 422);
        }

        $copied = DB::transaction(function () use ($sourceMenu, $target) {
            return RestaurantMenu::create([
                'company_nit' => $target->company_nit,
                'branch_id' => $target->id,
                'name' => $sourceMenu->name.' (copia)',
                'description' => $sourceMenu->description,
                'status' => 'draft',
                'active_days' => $sourceMenu->active_days,
                // (#costeo-multibodega D4) Regenerar category/item ids al clonar:
                // copiar la estructura verbatim dejaría a ambas sedes con los
                // MISMOS menu_item_id, lo que colisiona en el costeo por sede y
                // en los snapshots branch-keyed. Las recetas no se copian (la
                // sede destino las configura sobre sus propios ids).
                'structure' => $this->regenerateStructureIds($sourceMenu->structure),
            ]);
        });

        $itemsCount = collect($sourceMenu->structure['categories'] ?? [])
            ->sum(fn ($cat) => count($cat['items'] ?? []));

        $this->auditService->log('branch.menu_copied', null, $target, [
            'from_branch_id' => $source->id,
            'to_branch_id' => $target->id,
            'source_menu_id' => $sourceMenu->id,
            'copied_menu_id' => $copied->id,
            'items_count' => $itemsCount,
        ]);

        return response()->json([
            'data' => [
                'menu_id' => $copied->id,
                'items_count' => $itemsCount,
            ],
        ], 201);
    }

    /**
     * Clona la estructura JSON del menú regenerando `category.id` e `item.id`
     * con UUIDv7 (defensa en profundidad D4 contra colisión de `menu_item_id`
     * entre sedes). El resto de campos (nombre, precio, costo, etc.) se copia
     * tal cual. Si un nodo no tiene `id`, igual se le asigna uno nuevo.
     *
     * @param  array<string, mixed>|null  $structure
     * @return array<string, mixed>
     */
    private function regenerateStructureIds(?array $structure): array
    {
        if ($structure === null) {
            return ['categories' => []];
        }

        $categories = [];
        foreach ($structure['categories'] ?? [] as $category) {
            $category['id'] = (string) Str::uuid7();

            $items = [];
            foreach ($category['items'] ?? [] as $item) {
                $item['id'] = (string) Str::uuid7();
                $items[] = $item;
            }
            $category['items'] = $items;

            $categories[] = $category;
        }

        $structure['categories'] = $categories;

        return $structure;
    }

    /**
     * Lista las cajas de una sede específica. Permite gestionar la configuración
     * multi-caja desde la vista de empresa sin requerir branch.access en JWT.
     */
    public function cashRegisters(Request $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);
        $includeArchived = $request->boolean('all');

        $registers = CashRegister::query()
            ->where('company_nit', $model->company_nit)
            ->where('branch_id', $model->id)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $registers->map(fn (CashRegister $r) => $this->serializeCashRegister($r))->values()->all(),
        ]);
    }

    /**
     * Crea una caja en la sede indicada. No requiere branch.access en JWT —
     * usa company_nit del token y el {branch} del path.
     */
    public function storeCashRegister(StoreCashRegisterRequest $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        $nextOrder = CashRegister::query()
            ->where('company_nit', $model->company_nit)
            ->where('branch_id', $model->id)
            ->max('sort_order') ?? -1;

        $register = CashRegister::create([
            'company_nit' => $model->company_nit,
            'branch_id' => $model->id,
            'name' => $request->validated('name'),
            'is_active' => true,
            'sort_order' => (int) ($request->validated('sort_order') ?? $nextOrder + 1),
        ]);

        $this->auditService->log('cash_register.created', null, $register, [
            'actor_id' => $actorId,
            'name' => $register->name,
            'branch_id' => $model->id,
        ]);

        return response()->json(['data' => $this->serializeCashRegister($register)], 201);
    }

    /**
     * Renombra / (des)activa / archiva una caja desde la vista de empresa.
     * Archivar requiere que no haya sesión abierta (regla contable).
     */
    public function updateCashRegister(UpdateCashRegisterRequest $request, string $branch, string $registerId): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        /** @var CashRegister $register */
        $register = CashRegister::query()
            ->where('company_nit', $model->company_nit)
            ->where('branch_id', $model->id)
            ->findOrFail($registerId);

        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $register->name = $data['name'];
        }
        if (array_key_exists('is_active', $data)) {
            $register->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $register->sort_order = (int) $data['sort_order'];
        }

        if (! empty($data['archived']) && ! $register->isArchived()) {
            $hasOpen = CashRegisterSession::query()
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->exists();

            if ($hasOpen) {
                return response()->json([
                    'message' => 'No se puede archivar una caja con una sesión abierta. Ciérrala primero.',
                    'code' => 'REGISTER_SESSION_OPEN',
                ], 422);
            }

            $register->archived_at = now();
            $register->is_active = false;
        } elseif (isset($data['archived']) && $data['archived'] === false) {
            $register->archived_at = null;
        }

        $register->save();

        $this->auditService->log('cash_register.updated', null, $register, [
            'actor_id' => $actorId,
            'branch_id' => $model->id,
            'name' => $register->name,
            'is_active' => $register->is_active,
            'archived' => $register->isArchived(),
        ]);

        return response()->json(['data' => $this->serializeCashRegister($register->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serializeCashRegister(CashRegister $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'is_active' => (bool) $r->is_active,
            'sort_order' => (int) $r->sort_order,
            'archived' => $r->isArchived(),
        ];
    }

    /**
     * Retorna las branch_settings de la sede (solo claves de branding de menú).
     */
    public function showSettings(Request $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);

        return response()->json([
            'settings' => $this->resolveSettingsUrls($this->branchSettings->all($model->id)),
        ]);
    }

    /**
     * Actualiza campos de texto/boolean de branding (tagline, card_style, show_branding).
     */
    public function updateSettings(Request $request, string $branch): JsonResponse
    {
        $model = $this->resolveBranch($request, $branch);

        $validated = $request->validate([
            'menu_tagline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'menu_card_style' => ['sometimes', 'string', 'in:default,compact,card'],
            'menu_show_branding' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated)) {
            $this->branchSettings->setMany($model->company_nit, $model->id, $validated);
        }

        return response()->json([
            'settings' => $this->resolveSettingsUrls($this->branchSettings->all($model->id)),
        ]);
    }

    /**
     * Sube o reemplaza la imagen de cabecera del menú público de esta sede.
     * Patrón idéntico al logo de empresa en CompanyController.
     */
    public function uploadMenuHeaderImage(Request $request, string $branch): JsonResponse
    {
        return $this->uploadMenuBannerImage($request, $branch, 'header');
    }

    /**
     * Sube o reemplaza la imagen de pie de página del menú público de esta sede.
     */
    public function uploadMenuFooterImage(Request $request, string $branch): JsonResponse
    {
        return $this->uploadMenuBannerImage($request, $branch, 'footer');
    }

    /**
     * Elimina la imagen de cabecera o pie de página del menú de esta sede.
     */
    public function deleteMenuBannerImage(Request $request, string $branch, string $position): JsonResponse
    {
        abort_unless(in_array($position, ['header', 'footer'], true), 422, 'Posición inválida.');

        $model = $this->resolveBranch($request, $branch);
        $settingKey = "menu_{$position}_image_url";
        $disk = config('filesystems.default');

        $storedValue = $this->branchSettings->get($model->id, $settingKey);
        if ($storedValue) {
            Storage::disk($disk)->delete($this->extractStoragePath((string) $storedValue));
            $this->branchSettings->forget($model->id, $settingKey);
        }

        return response()->json(['settings' => $this->resolveSettingsUrls($this->branchSettings->all($model->id))]);
    }

    private function uploadMenuBannerImage(Request $request, string $branch, string $position): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $model = $this->resolveBranch($request, $branch);
        $settingKey = "menu_{$position}_image_url";
        $disk = config('filesystems.default');

        $existing = $this->branchSettings->get($model->id, $settingKey);
        if ($existing) {
            Storage::disk($disk)->delete($this->extractStoragePath((string) $existing));
        }

        $ext = $request->file('image')->getClientOriginalExtension();
        $storedPath = $request->file('image')->storeAs(
            "companies/{$model->company_nit}/branches/{$model->id}",
            "menu-{$position}.{$ext}",
            $disk,
        );

        // Store the S3 path (not the URL); SignedAssetUrl resolves it on read.
        $this->branchSettings->set($model->company_nit, $model->id, $settingKey, $storedPath);

        return response()->json(['settings' => $this->resolveSettingsUrls($this->branchSettings->all($model->id))]);
    }

    /**
     * Converts stored image value (S3 path or legacy proxy URL) to a signable path.
     *
     * @ponytail: handles both new (path) and legacy (URL) stored values for backwards compat
     */
    private function extractStoragePath(string $value): string
    {
        if (! str_starts_with($value, 'http')) {
            return $value;
        }

        $urlPath = (string) (parse_url($value, PHP_URL_PATH) ?? '');

        return ltrim(str_replace('/storage-proxy', '', $urlPath), '/');
    }

    /**
     * Resolves stored image paths to signed proxy URLs before returning settings to the client.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function resolveSettingsUrls(array $settings): array
    {
        $disk = (string) config('filesystems.default');
        foreach (['menu_header_image_url', 'menu_footer_image_url'] as $key) {
            if (! empty($settings[$key])) {
                $path = $this->extractStoragePath((string) $settings[$key]);
                $settings[$key] = SignedAssetUrl::for($path, $disk);
            }
        }

        return $settings;
    }

    private function resolveBranch(Request $request, string $branch): Branch
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        return Branch::query()
            ->where('company_nit', $nit)
            ->where('id', $branch)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function toArray(Branch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'slug' => $b->slug,
            'address' => $b->address,
            'city' => $b->city,
            'business_type_id' => $b->business_type_id,
            'capabilities_override' => $b->capabilities_override,
            'is_default' => (bool) $b->is_default,
            'archived_at' => optional($b->archived_at)->toIso8601String(),
            'created_at' => optional($b->created_at)->toIso8601String(),
        ];
    }

    /**
     * Cambia el vertical de una sede existente y siembra las prep_areas
     * faltantes del nuevo vertical (las existentes se preservan).
     *
     * Precondiciones:
     *   - El usuario tiene permiso `branches.manage,update` (resuelto en routes).
     *   - El vertical destino existe en el catálogo y está activo.
     *
     * Esta operación NO modifica órdenes históricas, menús, ni receipts. Sólo
     * cambia `branches.business_type_id` y, opcionalmente, agrega áreas de
     * preparación que el nuevo vertical define.
     */
    public function changeBusinessType(Request $request, string $branch): JsonResponse
    {
        $validated = $request->validate([
            'business_type_id' => ['required', 'string', 'exists:business_types,slug'],
        ]);

        $model = $this->resolveBranch($request, $branch);
        $before = $model->business_type_id;

        if ($before === $validated['business_type_id']) {
            return response()->json([
                'message' => 'La sede ya está en ese tipo de negocio.',
                'code' => 'BUSINESS_TYPE_UNCHANGED',
            ], 422);
        }

        $businessType = BusinessType::find($validated['business_type_id']);
        abort_if($businessType === null, 422, 'Tipo de negocio inválido.');

        DB::transaction(function () use ($model, $businessType) {
            $model->forceFill(['business_type_id' => $businessType->slug])->save();

            // Sembrar sólo prep_areas faltantes. Las existentes se respetan
            // para no perder configuración de KDS / menu items asignados.
            $existingSlugs = PrepArea::query()
                ->where('branch_id', $model->id)
                ->pluck('slug')
                ->all();

            $maxOrder = PrepArea::query()
                ->where('branch_id', $model->id)
                ->max('display_order') ?? -1;

            foreach ($businessType->prep_area_defaults ?? [] as $i => $area) {
                if (in_array($area['slug'], $existingSlugs, true)) {
                    continue;
                }
                PrepArea::create([
                    'branch_id' => $model->id,
                    'slug' => $area['slug'],
                    'label' => $area['label'],
                    'color' => $area['color'] ?? '#64748b',
                    'icon_key' => $area['icon_key'] ?? null,
                    'display_order' => ++$maxOrder,
                ]);
            }
        });

        $this->auditService->log('branch.business_type_changed', null, $model, [
            'branch_id' => $model->id,
            'before' => $before,
            'after' => $businessType->slug,
        ]);

        return response()->json(['data' => $this->toArray($model->fresh())]);
    }
}
