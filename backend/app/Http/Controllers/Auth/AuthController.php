<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SelectCompanyRequest;
use App\Jobs\SendInventoryDigestPushJob;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JwtService;
use App\Support\PostLoginRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Gestiona las sesiones JWT del usuario: selección de empresa, cambio de empresa y logout.
 *
 * selectCompany(): emite el JWT final con empresa activa; valida membresía activa antes de emitir.
 * switchCompany(): invalida el JWT actual y emite uno nuevo sin empresa activa (para seleccionar otra).
 * logout(): invalida el JWT actual en la lista negra si jwt_blacklist_enabled=true.
 *
 * @env JWT_SECRET — clave de firma para el JWT emitido
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
    ) {}

    public function selectCompany(SelectCompanyRequest $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        $nit = $request->validated('nit');
        $company = $user->companies()->where('companies.nit', $nit)->first();

        if ($company === null) {
            return response()->json(['message' => 'Empresa no encontrada.'], 404);
        }

        // No rechazamos por status aquí: cualquier empresa donde el usuario
        // tenga membership activa puede entrar, y los middlewares del stack
        // (`EnsureCompanyVerified` + `EnsureCompanyNotBlocked`) deciden qué
        // rutas son accesibles según el estado real. Esto permite que:
        //  - past_due entre y opere normalmente con banner de countdown.
        //  - suspended entre y vea sólo /billing para subir comprobante.
        //  - pending_activation/rejected/inactive entren y reciban
        //    code=company_not_verified que el frontend redirige a
        //    /company/under-review.

        $membership = CompanyUser::where('company_nit', $nit)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            return response()->json([
                'message' => 'Tu acceso a esta empresa ha sido revocado.',
                'code' => 'USER_INACTIVE_IN_COMPANY',
            ], 403);
        }

        $companies = $user->companies()->get();
        $token = $this->jwtService->issue($user, $companies, $nit);

        $this->auditService->log('auth.company.selected', $user, $company, [
            'company_nit' => $nit,
        ], $request);

        // Inventory digest push (one-shot por día). Cache::add es
        // atomic: si el user hace login simultáneo en 2 dispositivos sólo
        // uno gana el `add()` y el otro retorna false (no se dispatcha
        // duplicado). TTL = segundos hasta medianoche local. N-instance
        // safe porque CACHE_STORE=database (postgres con tabla `cache` y
        // `cache_locks` — stack canónico del proyecto, no Redis).
        $today = Carbon::today();
        $cacheKey = "push.inventory.sent.{$user->id}.{$today->toDateString()}";
        $secondsUntilMidnight = max(60, Carbon::now()->diffInSeconds($today->copy()->addDay()));

        if (Cache::add($cacheKey, 1, $secondsUntilMidnight)) {
            SendInventoryDigestPushJob::dispatch($user->id, $nit);
        }

        // El frontend usa `default_route` para decidir a dónde
        // navegar tras seleccionar empresa. Courier-only → /my-deliveries;
        // el resto → /dashboard.
        return response()
            ->json([
                'authenticated' => true,
                'default_route' => PostLoginRedirect::routeNameForUser($user->id, $nit),
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }

    public function switchCompany(Request $request): JsonResponse
    {
        $request->validate([
            'company_nit' => ['required', 'string'],
        ]);

        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);
        $targetNit = $request->input('company_nit');

        $companies = $user->companies()->get();
        $target = $companies->firstWhere('nit', $targetNit);

        if ($target === null) {
            return response()->json([
                'message' => 'No eres miembro de la empresa solicitada.',
                'code' => 'COMPANY_NOT_MEMBER',
            ], 403);
        }

        if ($target->status !== 'active') {
            return response()->json([
                'message' => 'La empresa solicitada está desactivada.',
                'code' => 'COMPANY_INACTIVE',
            ], 422);
        }

        $membership = CompanyUser::where('company_nit', $targetNit)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            return response()->json([
                'message' => 'Tu acceso a esta empresa ha sido revocado.',
                'code' => 'USER_INACTIVE_IN_COMPANY',
            ], 403);
        }

        $this->jwtService->invalidate($request->attributes->get('jwt_token'));
        $token = $this->jwtService->issue($user, $companies, $targetNit);

        $this->auditService->log('auth.company.switched', $user, $target, [
            'company_nit' => $targetNit,
        ], $request);

        return response()
            ->json([
                'authenticated' => true,
                'default_route' => PostLoginRedirect::routeNameForUser($user->id, $targetNit),
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }

    /**
     * Lista las sedes a las que el usuario tiene acceso dentro de la empresa activa.
     * Útil cuando el JWT vino sin active_branch_id (usuario tiene N sedes y debe elegir).
     */
    public function branchesAvailable(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);
        $activeCompanyNit = $payload['active_company_nit'] ?? null;

        if ($activeCompanyNit === null) {
            return response()->json(['message' => 'Selecciona primero una empresa.'], 422);
        }

        // Owners (is_system) ven todas las sedes de la empresa; el resto sólo
        // las del pivot branch_users.
        $isOwner = ($payload['role']['is_system'] ?? false) === true;
        $branches = $isOwner
            ? Branch::query()
                ->where('company_nit', $activeCompanyNit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_default', 'address', 'city'])
            : $user->accessibleBranches($activeCompanyNit)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['branches.id', 'branches.name', 'branches.slug', 'branches.is_default', 'branches.address', 'branches.city']);

        return response()->json([
            'data' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'is_default' => (bool) $b->is_default,
                'address' => $b->address,
                'city' => $b->city,
            ])->values()->all(),
        ]);
    }

    /**
     * Cambia la sede activa del usuario dentro de la empresa actualmente activa.
     * Reemite el JWT con active_branch_id seteado y revoca el anterior.
     */
    public function switchBranch(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'string', 'uuid'],
        ]);

        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);
        $activeCompanyNit = $payload['active_company_nit'] ?? null;

        if ($activeCompanyNit === null) {
            return response()->json([
                'message' => 'Selecciona primero una empresa.',
                'code' => 'NO_ACTIVE_COMPANY',
            ], 422);
        }

        $branchId = (string) $request->input('branch_id');

        $branch = Branch::query()
            ->where('id', $branchId)
            ->where('company_nit', $activeCompanyNit)
            ->whereNull('archived_at')
            ->first();

        if ($branch === null) {
            return response()->json([
                'message' => 'Sede no encontrada o archivada.',
                'code' => 'BRANCH_NOT_FOUND',
            ], 404);
        }

        $isOwner = ($payload['role']['is_system'] ?? false) === true;

        if (! $isOwner && ! $user->canAccessBranch($branch->id)) {
            return response()->json([
                'message' => 'No tienes acceso a esta sede.',
                'code' => 'BRANCH_FORBIDDEN',
            ], 403);
        }

        $previousBranchId = $payload['active_branch_id'] ?? null;

        // Bloquear switch si hay caja abierta en la sede
        // ORIGEN. Cambiar de sede mientras se opera caja induce errores
        // contables (la caja queda atorada esperando un cierre que no
        // llega). Excepción: permiso `cash_register.bypass_switch_lock`
        // (owner-only por default, asignable) y el caso del propio owner
        // (is_system bypass).
        $permissionsList = (array) ($payload['permissions'] ?? []);
        $canBypassLock = $isOwner || in_array('cash_register.bypass_switch_lock', $permissionsList, true);

        if (! $canBypassLock && $previousBranchId !== null && $previousBranchId !== $branch->id) {
            $openSession = CashRegisterSession::withoutBranchScope()
                ->where('company_nit', $activeCompanyNit)
                ->where('branch_id', $previousBranchId)
                ->where('status', 'open')
                ->first();

            if ($openSession !== null) {
                return response()->json([
                    'message' => 'Tienes una caja abierta en la sede actual. Ciérrala antes de cambiar de sede o pide el permiso para hacer bypass.',
                    'code' => 'BRANCH_SWITCH_BLOCKED_CASH_OPEN',
                    'open_session_id' => $openSession->id,
                ], 422);
            }
        }

        $this->jwtService->invalidate($request->attributes->get('jwt_token'));
        $token = $this->jwtService->issue($user, $user->companies()->get(), $activeCompanyNit, $branch->id);

        // Telemetría de switch: permite reconstruir patrones de uso
        // del switcher post-deploy. `from_branch_id` ayuda a detectar
        // saltos cross-sede frecuentes que justificarían un endpoint
        // específico (ej. supervisor que opera 2 sedes en paralelo).
        $this->auditService->log('auth.branch.switched', $user, $branch, [
            'company_nit' => $activeCompanyNit,
            'from_branch_id' => $previousBranchId,
            'to_branch_id' => $branch->id,
            'branch_slug' => $branch->slug,
            'was_owner_bypass' => $isOwner,
        ], $request);

        return response()
            ->json([
                'authenticated' => true,
                'active_branch_id' => $branch->id,
                'default_route' => PostLoginRedirect::routeNameForUser($user->id, $activeCompanyNit),
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }

    public function logout(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        $this->jwtService->invalidate($request->attributes->get('jwt_token'));

        $this->auditService->log('auth.logout', $user, $user, [], $request);

        return response()
            ->json(['message' => 'Sesión cerrada correctamente.'])
            ->withCookie($this->jwtService->forgetCookie());
    }
}
