<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreQuickReplyRequest;
use App\Http\Requests\Chat\UpdateQuickReplyRequest;
use App\Http\Resources\ChatQuickReplyResource;
use App\Models\Branch;
use App\Models\ChatQuickReply;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Respuestas rápidas del operador (§8.4b punto 7).
 *
 *   GET    /api/v1/chats/quick-replies        listar/usar   (chats.read)
 *   POST   /api/v1/chats/quick-replies        crear         (owner/admin)
 *   PUT    /api/v1/chats/quick-replies/{id}   editar        (owner/admin)
 *   DELETE /api/v1/chats/quick-replies/{id}   borrar        (owner/admin)
 *
 * **RBAC (opción A del plan F3b)**: usar/listar lo puede cualquiera con
 * `chats.read`; gestionar (crear/editar/borrar) es owner/admin, con el mismo
 * patrón `is_system` no-basta / nombre-de-rol que el resto del módulo. No se
 * agregó ningún slug al catálogo: las respuestas son texto enlatado, no un
 * recurso con permiso propio.
 *
 * Alcance por sede: `branch_id` nulo = de toda la empresa; con sede = de esa
 * sede. El listado le muestra al operador las de su empresa más las de sus
 * sedes; owner/admin ven todas.
 */
class ChatQuickReplyController extends Controller
{
    public function __construct(
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'chats', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $accessible = $this->accessibleBranchIds($request);

        $replies = ChatQuickReply::forCompany($companyNit)
            ->with('branch:id,name')
            // Empresa (branch_id null) siempre; las de sede solo si el usuario
            // llega a esa sede. `null` de `$accessible` = owner/admin: todas.
            ->when($accessible !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $accessible)
            ))
            ->orderByRaw('branch_id IS NOT NULL')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => ChatQuickReplyResource::collection($replies),
        ]);
    }

    public function store(StoreQuickReplyRequest $request): JsonResponse
    {
        if (($denied = $this->denyIfNotManager($request)) !== null) {
            return $denied;
        }

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = $request->input('branch_id');

        if (($resolved = $this->resolveBranch($companyNit, $branchId)) instanceof JsonResponse) {
            return $resolved;
        }

        $reply = ChatQuickReply::create([
            'company_nit' => $companyNit,
            'branch_id' => $resolved?->id,
            'title' => $request->string('title')->toString(),
            'body' => $request->string('body')->toString(),
            'created_by_user_id' => $request->attributes->get('jwt_payload')['sub'] ?? null,
        ]);

        return response()->json([
            'data' => new ChatQuickReplyResource($reply->load('branch:id,name')),
        ], 201);
    }

    public function update(UpdateQuickReplyRequest $request, string $id): JsonResponse
    {
        if (($denied = $this->denyIfNotManager($request)) !== null) {
            return $denied;
        }

        $reply = $this->findOrDeny($request, $id);

        $reply->update([
            'title' => $request->string('title')->toString(),
            'body' => $request->string('body')->toString(),
        ]);

        return response()->json([
            'data' => new ChatQuickReplyResource($reply->fresh()->load('branch:id,name')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->denyIfNotManager($request)) !== null) {
            return $denied;
        }

        $this->findOrDeny($request, $id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Resuelve una respuesta de la empresa activa o aborta con 404.
     *
     * Mismo criterio del módulo: id validado antes de tocar la base (un no-UUID
     * hace fallar a Postgres con 22P02 → 500) y 404 para toda falla de scope.
     */
    private function findOrDeny(Request $request, string $id): ChatQuickReply
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $reply = Str::isUuid($id)
            ? ChatQuickReply::forCompany($companyNit)->whereKey($id)->first()
            : null;

        if ($reply === null) {
            abort(404);
        }

        return $reply;
    }

    /**
     * Verifica que la sede sea de la empresa activa. Devuelve el `Branch`, `null`
     * si el alcance es de empresa, o una respuesta 404 si la sede no existe acá.
     */
    private function resolveBranch(string $companyNit, ?string $branchId): Branch|JsonResponse|null
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        $branch = Branch::query()
            ->where('company_nit', $companyNit)
            ->where('id', $branchId)
            ->whereNull('archived_at')
            ->first();

        if ($branch === null) {
            // 404, no 403: que la sede exista en otra empresa es lo que el
            // aislamiento tiene que ocultar (§7.5).
            return response()->json([
                'message' => 'Sede no encontrada en esta empresa.',
                'code' => 'BRANCH_NOT_FOUND',
            ], 404);
        }

        return $branch;
    }

    /**
     * Gestionar respuestas es owner/admin (opción A). `is_system` no sirve:
     * también es true para `employee`; se compara el nombre del rol, patrón
     * canónico del proyecto.
     */
    private function denyIfNotManager(Request $request): ?JsonResponse
    {
        $payload = (array) $request->attributes->get('jwt_payload', []);
        $roleName = $payload['role']['name'] ?? null;

        $isManager = in_array($roleName, [
            config('roles.role_names.owner'),
            config('roles.role_names.admin'),
        ], true);

        if ($isManager) {
            return null;
        }

        return response()->json([
            'message' => 'Solo el propietario o un administrador puede gestionar las respuestas rápidas.',
            'code' => 'QUICK_REPLY_FORBIDDEN',
        ], 403);
    }

    /**
     * Sedes cuyas respuestas puede ver, o `null` si las ve todas (owner/admin).
     *
     * @return list<string>|null
     */
    private function accessibleBranchIds(Request $request): ?array
    {
        $payload = (array) $request->attributes->get('jwt_payload', []);
        $roleName = $payload['role']['name'] ?? null;

        if (in_array($roleName, [config('roles.role_names.owner'), config('roles.role_names.admin')], true)) {
            return null;
        }

        return collect($payload['branches'] ?? [])->pluck('id')->map(fn ($v) => (string) $v)->all();
    }
}
