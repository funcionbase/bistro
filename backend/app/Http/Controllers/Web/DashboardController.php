<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\DeliveryService;
use App\Services\FeaturePermissionService;
use App\Services\JwtService;
use App\Services\MetricsService;
use App\Services\ReportsPermissionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Renderiza el dashboard principal con props diferidas para métricas y entregas.
 *
 * El JWT se extrae via JwtService::extractTokenFromRequest (cookie HttpOnly > Authorization > session > query);
 * se reemite si expira en <300s y la cookie HttpOnly se renueva via Cookie::queue().
 * El frontend recibe un marker `'__authenticated__'` en lugar del JWT real (para que el JS no pueda leerlo).
 * Las props summary/heatmap/abandonment/deliveries son Inertia::defer() y se resuelven post-render.
 * La verificación de permisos (reports.read, deliveries.read) usa un Request sintético para
 * no pasar por el middleware stack completo desde un contexto Web.
 * Si no hay token válido o el usuario carece de permisos, las props diferidas retornan null
 * y los paneles correspondientes se ocultan silenciosamente en el frontend.
 * Períodos válidos definidos en config('metrics.dashboard_periods').
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly MetricsService $metricsService,
        private readonly DeliveryService $deliveryService,
        private readonly ReportsPermissionService $reportsPermission,
        private readonly FeaturePermissionService $featurePermission,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $token = $this->jwtService->extractTokenFromRequest($request);
        $activeCompany = null;
        $companyNit = null;
        $jwtPayload = null;
        $needsProfileCompletion = false;

        if ($token) {
            try {
                $jwtPayload = $this->jwtService->verify($token);

                if (($jwtPayload['exp'] ?? 0) - time() <= 300) {
                    $token = $this->jwtService->reissue($jwtPayload);
                    Cookie::queue($this->jwtService->buildCookie($token));
                } elseif (! $request->cookie(JwtService::COOKIE_NAME)) {
                    // Migración: token válido vino por Bearer/session/query pero no hay cookie.
                    Cookie::queue($this->jwtService->buildCookie($token));
                }

                $activeCompanyNit = $jwtPayload['active_company_nit'] ?? null;
                if ($activeCompanyNit) {
                    $companyNit = $activeCompanyNit;
                    $activeCompany = collect($jwtPayload['companies'] ?? [])
                        ->first(fn (array $c) => $c['nit'] === $activeCompanyNit);
                }

                // Multi-sede: el dashboard NO pasa por branch.access (es web,
                // no API), así que el BranchScope global no recibe la sede activa
                // automáticamente. La inyectamos manualmente desde el JWT.
                //
                // Filtro por query param `?branch=`:
                //  - 'all' (consolidado) o uuid distinto: requiere `metrics.view_all_branches`.
                //  - Sin param o 'active': filtra por la sede activa del JWT.
                $activeBranchId = $jwtPayload['active_branch_id'] ?? null;
                $branchParam = (string) $request->query('branch', '');
                $hasConsolidatedPermission = in_array(
                    'metrics.view_all_branches',
                    $jwtPayload['permissions'] ?? [],
                    true
                ) || ($jwtPayload['role']['is_system'] ?? false) === true;

                if ($branchParam === 'all' && $hasConsolidatedPermission) {
                    // BranchScope ve null y no aplica filtro → KPIs consolidados.
                    $request->attributes->set('active_branch_id', null);
                    $request->attributes->set('consolidated_branches', true);
                } elseif ($branchParam !== '' && $branchParam !== 'active' && $hasConsolidatedPermission) {
                    // Sede específica solicitada (válida si pertenece a la empresa activa).
                    $branchExists = Branch::query()
                        ->where('id', $branchParam)
                        ->where('company_nit', $activeCompanyNit)
                        ->whereNull('archived_at')
                        ->exists();
                    if ($branchExists) {
                        $request->attributes->set('active_branch_id', $branchParam);
                    } elseif ($activeBranchId !== null) {
                        $request->attributes->set('active_branch_id', $activeBranchId);
                    }
                } elseif ($activeBranchId !== null) {
                    $request->attributes->set('active_branch_id', $activeBranchId);
                }

                $request->attributes->set('active_company_nit', $activeCompanyNit);

                $needsProfileCompletion = ($jwtPayload['enrollment_step'] ?? 'complete') !== 'complete';
            } catch (RuntimeException) {
                // Token inválido o expirado — sin contexto de empresa el dashboard
                // no puede operar; redirigir a la landing para re-login.
                $token = null;
                $jwtPayload = null;
            }
        }

        // Sin JWT válido el dashboard no tiene contexto de empresa: los widgets
        // del frontend (useWidgetFetch contra /api/v1/*) chocarían con 401 en
        // bucle. Redirigir al login antes de renderizar Inertia. NO llamamos
        // forgetCookie() acá: la cookie del callback OAuth puede estar en una
        // race con el redirect del browser, y borrarla rompe el flujo de
        // login fresh — la cookie se invalida sola al expirar el TTL.
        if (! $jwtPayload) {
            return redirect()->route('home');
        }

        $period = $this->resolveRequestedPeriod($request->query('period', 'today'));

        return Inertia::render('dashboard', [
            'token' => $token ? '__authenticated__' : null,
            'activeCompany' => $activeCompany,
            'companyStatus' => $request->query('company_status'),
            'needsProfileCompletion' => $needsProfileCompletion,
            'period' => $period,

            // Props diferidas: se resuelven después del render inicial
            'summary' => Inertia::defer(fn () => $this->buildSummary($companyNit, $period, $jwtPayload)),

            'heatmap' => Inertia::defer(fn () => $this->buildHeatmap($companyNit, $period, $jwtPayload)),

            'abandonment' => Inertia::defer(fn () => $this->buildAbandonment($companyNit, $period, $jwtPayload)),

            'deliveries' => Inertia::defer(fn () => $this->buildDeliveries($companyNit, $period, $jwtPayload)),

            'lowStockInventory' => Inertia::defer(fn () => $this->buildLowStockInventory($companyNit, $jwtPayload)),
        ]);
    }

    /**
     * Resumen de insumos bajo mínimo para el banner de alerta del dashboard.
     *
     * Multibodega: low-stock se mide por (ingrediente, bodega) — cada
     * fila puede aparecer una vez por bodega con stock bajo. El banner muestra
     * el nombre del insumo + la bodega afectada para que el operador sepa
     * dónde reabastecer.
     *
     * Se oculta silenciosamente si el usuario no tiene `inventory.read`.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     * @return array{count:int,items:list<array{id:int,name:string,unit:string,warehouse_name:string,quantity:string,min_stock:string}>}|null
     */
    protected function buildLowStockInventory(?string $companyNit, ?array $jwtPayload): ?array
    {
        if (! $companyNit || ! $jwtPayload) {
            return null;
        }

        $hasPermission = $this->featurePermission->hasPermission(
            $this->buildSyntheticRequest($companyNit, $jwtPayload),
            'inventory',
            'read'
        );

        if (! $hasPermission) {
            return null;
        }

        $branchId = $jwtPayload['active_branch_id'] ?? null;

        // (#costeo-multibodega) La bodega es company-scoped; el filtro por sede
        // va vía el pivot branch_warehouses.
        $base = DB::table('ingredient_stocks as s')
            ->join('ingredients as i', 'i.id', '=', 's.ingredient_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('i.company_nit', $companyNit)
            ->whereNull('i.archived_at')
            ->whereNull('w.archived_at')
            ->where('s.min_stock', '>', 0)
            ->whereColumn('s.quantity', '<', 's.min_stock')
            ->when($branchId !== null, fn ($q) => $q->whereExists(function ($sub) use ($branchId) {
                $sub->select(DB::raw(1))
                    ->from('branch_warehouses as bw')
                    ->whereColumn('bw.warehouse_id', 'w.id')
                    ->where('bw.branch_id', $branchId);
            }));

        $count = (clone $base)->count();

        $items = $base
            ->orderBy('i.name')
            ->orderBy('w.name')
            ->limit(5)
            ->select(
                'i.id',
                'i.name',
                'i.unit',
                'w.name as warehouse_name',
                's.quantity',
                's.min_stock',
            )
            ->get()
            ->map(fn ($r) => [
                // ingredients.id es uuid → string, no int.
                'id' => (string) $r->id,
                'name' => $r->name,
                'unit' => $r->unit,
                'warehouse_name' => $r->warehouse_name,
                'quantity' => (string) $r->quantity,
                'min_stock' => (string) $r->min_stock,
            ])
            ->all();

        return [
            'count' => $count,
            'items' => $items,
        ];
    }

    // ── Props diferidas ────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $jwtPayload */
    protected function buildSummary(?string $companyNit, string $period, ?array $jwtPayload): ?array
    {
        if (! $companyNit || ! $this->hasReportsPermission($companyNit, $jwtPayload)) {
            return null;
        }

        // BUG-024: pasar branchId desde el atributo de request (ya resuelto por
        // el bloque de autenticación de __invoke, respetando ?branch=all/uuid).
        $branchId = request()->attributes->get('active_branch_id');
        $result = $this->metricsService->getSummary($companyNit, $period, null, null, $branchId);

        return $result['data'] ?? null;
    }

    /** @param array<string, mixed>|null $jwtPayload */
    protected function buildHeatmap(?string $companyNit, string $period, ?array $jwtPayload): ?array
    {
        if (! $companyNit || ! $this->hasReportsPermission($companyNit, $jwtPayload)) {
            return null;
        }

        $branchId = request()->attributes->get('active_branch_id');
        $result = $this->metricsService->getOrderHeatmap($companyNit, $period, null, null, $branchId);

        return $result['data'] ?? null;
    }

    /** @param array<string, mixed>|null $jwtPayload */
    protected function buildAbandonment(?string $companyNit, string $period, ?array $jwtPayload): ?array
    {
        if (! $companyNit || ! $this->hasReportsPermission($companyNit, $jwtPayload)) {
            return null;
        }

        $branchId = request()->attributes->get('active_branch_id');
        $result = $this->metricsService->getCartAbandonment($companyNit, $period, null, null, $branchId);

        return $result['data'] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $jwtPayload
     * @return array<int, mixed>|null
     */
    protected function buildDeliveries(?string $companyNit, string $period, ?array $jwtPayload): ?array
    {
        if (! $companyNit) {
            return null;
        }

        // Sin permiso → null (panel oculto silenciosamente)
        if (! $this->hasDeliveriesPermission($companyNit, $jwtPayload)) {
            return null;
        }

        [$from, $to] = $this->resolvePeriodDates($period);

        return $this->deliveryService->getCompanyMetrics($companyNit, $from, $to);
    }

    // ── Helpers de permisos ────────────────────────────────────────────────────

    /**
     * Comprueba permiso de reportes inyectando el payload en un Request sintético.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     */
    protected function hasReportsPermission(string $companyNit, ?array $jwtPayload): bool
    {
        if (! $jwtPayload) {
            return false;
        }

        return $this->reportsPermission->hasPermission(
            $this->buildSyntheticRequest($companyNit, $jwtPayload),
            'read'
        );
    }

    /**
     * Comprueba permiso de deliveries inyectando el payload en un Request sintético.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     */
    protected function hasDeliveriesPermission(string $companyNit, ?array $jwtPayload): bool
    {
        if (! $jwtPayload) {
            return false;
        }

        return $this->featurePermission->hasPermission(
            $this->buildSyntheticRequest($companyNit, $jwtPayload),
            'deliveries',
            'read'
        );
    }

    /**
     * Crea un Request sintético con los atributos que esperan los servicios de permisos.
     *
     * @param  array<string, mixed>  $jwtPayload
     */
    protected function buildSyntheticRequest(string $companyNit, array $jwtPayload): Request
    {
        $req = Request::create('/');
        $req->attributes->set('active_company_nit', $companyNit);
        $req->attributes->set('jwt_payload', $jwtPayload);

        return $req;
    }

    // ── Helpers de período ─────────────────────────────────────────────────────

    protected function resolveRequestedPeriod(?string $raw): string
    {
        $allowed = config('metrics.dashboard_periods', ['today', 'week', 'month']);

        return in_array($raw, $allowed, true) ? $raw : 'today';
    }

    /** @return array{Carbon, Carbon} */
    private function resolvePeriodDates(string $period): array
    {
        $now = Carbon::now(config('metrics.timezone', 'UTC'));

        return match ($period) {
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfMonth()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
