<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Http\Requests\Reports\OrderReportRequest;
use App\Jobs\GenerateReportPdf;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes paginados de pedidos por período y exportación asíncrona a PDF.
 *
 * index(): soporta paginación cursor (cursor_based=true) y offset estándar; incluye resumen de KPIs.
 * export(): despacha GenerateReportPdf en cola y retorna un token de descarga con TTL configurable.
 * download(): sirve el archivo generado por el job; consume el token (lo elimina del caché al descargar).
 * Períodos disponibles: daily, weekly, monthly, custom (requiere date_from y date_to).
 */
class OrderReportController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(OrderReportRequest $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $activeBranchId = $request->attributes->get('active_branch_id');
        $isConsolidated = $request->attributes->get('consolidated_branches') === true;
        $validated = $request->validated();
        [$dateFrom, $dateTo] = $this->resolvePeriod($validated);

        $status = $validated['status'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 25);

        $baseQuery = Order::where('company_nit', $companyNit)
            ->whereBetween('ordered_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()]);

        $summary = $this->buildSummary(clone $baseQuery);

        // Scope explícito en la respuesta para que el frontend sepa si
        // está mirando un reporte de una sede ('branch'), de todas las sedes
        // consolidadas ('consolidated'), o de una sede ajena ('branch'
        // override vía ?branch=<uuid>). El shape consolidado agrega
        // `per_branch[]` con desglose por sede para tablas comparativas.
        $scopePayload = $isConsolidated
            ? [
                'scope' => 'consolidated',
                'per_branch' => $this->buildPerBranchBreakdown(clone $baseQuery, $companyNit),
            ]
            : [
                'scope' => 'branch',
                'branch_id' => $activeBranchId,
            ];

        $ordersQuery = clone $baseQuery;

        if ($status !== 'all') {
            $ordersQuery->where('status', $status);
        }

        // Eager-load mínimo para que la vista "Ventas del día" pueda mostrar el
        // repartidor sin un fetch adicional por orden. Las relaciones grandes
        // (receipts, payment_data) se cargan solo en el detalle (`/orders/{id}`).
        $ordersQuery->with(['delivery.deliverer:id,name']);

        $search = $validated['search'] ?? null;
        if ($search !== null && $search !== '') {
            $ordersQuery->where(function (Builder $q) use ($search): void {
                $q->where('client_phone', 'like', "%{$search}%")
                    ->orWhere('billing_legal_name', 'like', "%{$search}%")
                    ->orWhere('billing_doc_number', 'like', "%{$search}%");
            });
        }

        if (isset($validated['min_amount'])) {
            $ordersQuery->where('total', '>=', $validated['min_amount']);
        }

        if (isset($validated['max_amount'])) {
            $ordersQuery->where('total', '<=', $validated['max_amount']);
        }

        $useCursor = filter_var($validated['cursor_based'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $orderedQuery = $ordersQuery->orderByDesc('ordered_at')->orderByDesc('id');

        if ($useCursor) {
            $perPage = min(
                (int) ($validated['per_page'] ?? config('mobile.api_default_page_size', 20)),
                (int) config('mobile.api_max_page_size', 100),
            );
            $paginated = $orderedQuery->cursorPaginate($perPage, ['*'], 'cursor', $validated['cursor'] ?? null);

            return response()->json(array_merge([
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'summary' => $summary,
                'orders' => $paginated->items(),
                'pagination' => [
                    'per_page' => $paginated->perPage(),
                    'next_cursor' => $paginated->nextCursor()?->encode(),
                    'prev_cursor' => $paginated->previousCursor()?->encode(),
                    'has_more' => $paginated->hasMorePages(),
                ],
            ], $scopePayload));
        }

        $paginated = $orderedQuery->paginate($perPage);

        return response()->json(array_merge([
            'period' => [
                'from' => $dateFrom->format('Y-m-d'),
                'to' => $dateTo->format('Y-m-d'),
            ],
            'summary' => $summary,
            'orders' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ], $scopePayload));
    }

    public function export(ExportReportRequest $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $user = User::findOrFail($request->attributes->get('jwt_payload')['sub']);
        [$dateFrom, $dateTo] = $this->resolvePeriod($request->validated());
        $status = $request->validated()['status'] ?? 'all';

        $token = Str::uuid()->toString();

        GenerateReportPdf::dispatch(
            $companyNit,
            $dateFrom,
            $dateTo,
            $status,
            $token,
        );

        $this->auditService->log('report.exported', $user, null, [
            'company_nit' => $companyNit,
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
            'status' => $status,
        ], $request);

        return response()->json([
            'token' => $token,
            'download_url' => route('api.reports.download', ['token' => $token]),
            'expires_in_minutes' => (int) config('reports.download_ttl', 30),
        ], 202);
    }

    public function download(Request $request, string $token): StreamedResponse|JsonResponse
    {
        $meta = Cache::get("report_download:{$token}");

        if ($meta === null) {
            return response()->json(['message' => 'El enlace de descarga ha expirado o no existe.'], 404);
        }

        $companyNit = $request->attributes->get('active_company_nit');

        if ($meta['company_nit'] !== $companyNit) {
            return response()->json(['message' => 'No tienes acceso a este informe.'], 403);
        }

        $disk = $meta['disk'];
        $path = $meta['path'];

        if (! Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'El archivo de informe no está disponible aún.'], 404);
        }

        Cache::forget("report_download:{$token}");

        return Storage::disk($disk)->download($path, "informe-pedidos-{$token}.html", [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(array $validated): array
    {
        return match ($validated['period']) {
            'daily' => [Carbon::today(), Carbon::today()],
            'weekly' => [Carbon::today()->subDays(6), Carbon::today()],
            'monthly' => [Carbon::today()->subDays(29), Carbon::today()],
            'custom' => [
                Carbon::parse($validated['date_from']),
                Carbon::parse($validated['date_to']),
            ],
        };
    }

    /** @return array<string, mixed> */
    private function buildSummary(Builder $query): array
    {
        // Tomar la lista de IDs ANTES de mutar el query con GROUP BY, para que el
        // total devuelto se calcule contra todas las órdenes del período.
        $orderIds = (clone $query)->select('id')->pluck('id');

        // Conteos y total bruto por estado de orden.
        $orders = $query->selectRaw('status, SUM(total) as total_sum, COUNT(*) as cnt')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $completed = $orders->get('completed');
        $failed = $orders->get('failed');
        $cancelled = $orders->get('cancelled');
        $refunded = $orders->get('refunded');
        $abandoned = $orders->get('abandoned');

        // Total devuelto: SUM(-amount) de payment_receipts del período.
        // Lo expresamos como número positivo; net = revenue - refunds.
        $totalRefunded = 0.0;
        if ($orderIds->isNotEmpty()) {
            $totalRefunded = (float) PaymentReceipt::whereIn('order_id', $orderIds)
                ->where('payment_method', 'refund')
                ->sum(DB::raw('-amount'));
        }

        $grossRevenue = (float) ($completed?->total_sum ?? 0);

        return [
            'total_orders' => $orders->sum('cnt'),
            'completed' => (int) ($completed?->cnt ?? 0),
            'failed' => (int) ($failed?->cnt ?? 0),
            'cancelled' => (int) ($cancelled?->cnt ?? 0),
            'refunded' => (int) ($refunded?->cnt ?? 0),
            'abandoned' => (int) ($abandoned?->cnt ?? 0),
            'total_revenue' => $grossRevenue,
            'total_refunded' => $totalRefunded,
            'net_revenue' => $grossRevenue - $totalRefunded,
        ];
    }

    /**
     * @return list<array{branch_id: string, branch_name: ?string, totals: array<string, int|float>}>
     */
    private function buildPerBranchBreakdown(Builder $query, string $companyNit): array
    {
        $byBranchOrders = (clone $query)
            ->selectRaw('branch_id, status, SUM(total) as total_sum, COUNT(*) as cnt')
            ->groupBy('branch_id', 'status')
            ->get();

        $refundsByBranch = PaymentReceipt::query()
            ->joinSub(
                (clone $query)->select('id', 'branch_id'),
                'period_orders',
                'period_orders.id',
                '=',
                'payment_receipts.order_id',
            )
            ->where('payment_receipts.payment_method', 'refund')
            ->selectRaw('period_orders.branch_id, SUM(-payment_receipts.amount) as refunded')
            ->groupBy('period_orders.branch_id')
            ->pluck('refunded', 'period_orders.branch_id');

        $branchNames = Branch::query()
            ->where('company_nit', $companyNit)
            ->pluck('name', 'id');

        $out = [];
        foreach ($byBranchOrders->groupBy('branch_id') as $branchId => $rows) {
            $rowsByStatus = $rows->keyBy('status');
            $completed = $rowsByStatus->get('completed');
            $grossRevenue = (float) ($completed?->total_sum ?? 0);
            $refunded = (float) ($refundsByBranch[$branchId] ?? 0);

            $out[] = [
                'branch_id' => (string) $branchId,
                'branch_name' => $branchNames[$branchId] ?? null,
                'totals' => [
                    'total_orders' => (int) $rows->sum('cnt'),
                    'completed' => (int) ($completed?->cnt ?? 0),
                    'failed' => (int) ($rowsByStatus->get('failed')?->cnt ?? 0),
                    'cancelled' => (int) ($rowsByStatus->get('cancelled')?->cnt ?? 0),
                    'refunded' => (int) ($rowsByStatus->get('refunded')?->cnt ?? 0),
                    'abandoned' => (int) ($rowsByStatus->get('abandoned')?->cnt ?? 0),
                    'total_revenue' => $grossRevenue,
                    'total_refunded' => $refunded,
                    'net_revenue' => $grossRevenue - $refunded,
                ],
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['totals']['net_revenue'] <=> $a['totals']['net_revenue']);

        return $out;
    }
}
