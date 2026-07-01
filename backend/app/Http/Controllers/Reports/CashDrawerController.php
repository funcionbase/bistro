<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\PdfExportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de cierre de caja: ingresos, devoluciones y propinas por método de
 * pago en un rango (typically un día). Diseñado para que el cajero/contador
 * concilie la caja física al final del turno.
 *
 * Filtra por `payment_receipts.paid_at` (no por `orders.ordered_at`) en TZ
 * America/Bogota — es la fecha en que el dinero efectivamente entró/salió.
 *
 * Cash neto del día (efectivo en caja) =
 *      SUM(receipts.amount cash) + SUM(orders.tip_amount cash) - SUM(refunds cash)
 */
class CashDrawerController extends Controller
{
    public function __construct(private readonly PdfExportService $pdfExportService) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $tz = config('orders.timezone', 'America/Bogota');
        $today = Carbon::now($tz)->toDateString();
        $from = Carbon::parse($validated['date_from'] ?? $today, $tz)->startOfDay();
        $to = Carbon::parse($validated['date_to'] ?? $today, $tz)->endOfDay();

        $summary = $this->buildSummary($companyNit, $from, $to, $request->attributes->get('active_branch_id'));

        return response()->json([
            'period' => [
                'from' => $from->copy()->setTimezone($tz)->toDateString(),
                'to' => $to->copy()->setTimezone($tz)->toDateString(),
                'timezone' => $tz,
            ],
            'summary' => $summary,
        ]);
    }

    public function exportPdf(Request $request): Response|JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $tz = config('orders.timezone', 'America/Bogota');
        $today = Carbon::now($tz)->toDateString();
        $from = Carbon::parse($validated['date_from'] ?? $today, $tz)->startOfDay();
        $to = Carbon::parse($validated['date_to'] ?? $today, $tz)->endOfDay();

        $summary = $this->buildSummary($companyNit, $from, $to, $request->attributes->get('active_branch_id'));

        return $this->pdfExportService->exportCashDrawer($companyNit, [
            'from' => $from->copy()->setTimezone($tz),
            'to' => $to->copy()->setTimezone($tz),
            'timezone' => $tz,
        ], $summary);
    }

    /**
     * Calcula el cierre por método: ingresos brutos, devoluciones, propinas y
     * cash neto. Todos los SUMs se hacen en SQL (jamás iteramos PHP por receipts).
     *
     * @return array<string, mixed>
     */
    private function buildSummary(string $companyNit, Carbon $from, Carbon $to, ?string $branchId = null): array
    {
        // Los timestamps se persisten en wall-clock del APP_TIMEZONE (Bogotá);
        // los límites del período se convierten a ese tz, NO a UTC (corría la
        // ventana +5h). Los modelos Eloquent (PaymentReceipt/Order) filtran por
        // sede vía BranchScope; los DB::table() de abajo replican ese filtro a
        // mano — sin él, un reporte de sede mezclaba aperturas/egresos/ingresos
        // de TODAS las sedes.
        $appTz = config('app.timezone');
        $fromDb = $from->copy()->setTimezone($appTz);
        $toDb = $to->copy()->setTimezone($appTz);

        // Receipts agrupados por método (branch-scoped vía BranchScope).
        $rows = PaymentReceipt::where('company_nit', $companyNit)
            ->whereBetween('paid_at', [$fromDb, $toDb])
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method,
                SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS gross,
                SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS refunds,
                SUM(amount) AS net,
                COUNT(*) AS receipts_count')
            ->groupBy('payment_method')
            ->get();

        $byMethod = [
            'cash' => ['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'tips' => 0.0, 'count' => 0],
            'card' => ['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'tips' => 0.0, 'count' => 0],
            'transfer' => ['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'tips' => 0.0, 'count' => 0],
            'refund' => ['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'tips' => 0.0, 'count' => 0],
        ];

        foreach ($rows as $row) {
            $key = $row->payment_method;
            $byMethod[$key] = [
                'gross' => (float) $row->gross,
                'refunds' => (float) $row->refunds,
                'net' => (float) $row->net,
                'tips' => 0.0,
                'count' => (int) $row->receipts_count,
            ];
        }

        // Propinas por método: el receipt original (no-refund) lleva la propina
        // en orders.tip_amount; agregamos por payment_method del receipt no-refund
        // de la misma orden cuyo paid_at cae en el rango.
        $tipRows = DB::table('payment_receipts as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->where('pr.company_nit', $companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('pr.branch_id', $branchId))
            ->whereBetween('pr.paid_at', [$fromDb, $toDb])
            ->whereNotNull('pr.payment_method')
            ->where('pr.payment_method', '!=', 'refund')
            ->where('o.tip_amount', '>', 0)
            ->selectRaw('pr.payment_method, SUM(o.tip_amount) AS tips_total')
            ->groupBy('pr.payment_method')
            ->get();

        foreach ($tipRows as $tipRow) {
            if (isset($byMethod[$tipRow->payment_method])) {
                $byMethod[$tipRow->payment_method]['tips'] = (float) $tipRow->tips_total;
            }
        }

        $totalGross = (float) array_sum(array_column($byMethod, 'gross'));
        $totalRefunds = (float) array_sum(array_column($byMethod, 'refunds'));
        $totalTips = (float) array_sum(array_column($byMethod, 'tips'));
        $totalNet = $totalGross - $totalRefunds;

        // BUG-021: sumar saldo inicial de sesiones abiertas en el período y
        // restar egresos en efectivo — igual que CashRegisterService::computeExpectedCash.
        $openingTotal = (float) DB::table('cash_register_sessions')
            ->where('company_nit', $companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('opened_at', [$fromDb, $toDb])
            ->sum('opening_amount');

        $cashExpensesTotal = (float) DB::table('cash_register_expenses')
            ->where('company_nit', $companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('payment_method', 'cash')
            ->whereBetween('created_at', [$fromDb, $toDb])
            ->sum('amount');

        // Entradas de efectivo (no-venta): aportes/préstamos/ajustes. Suman al
        // cajón físico. Desglosadas por categoría para el PDF de cierre.
        $incomeRows = DB::table('cash_register_incomes')
            ->where('company_nit', $companyNit)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('payment_method', 'cash')
            ->whereBetween('created_at', [$fromDb, $toDb])
            ->selectRaw('category, SUM(amount) AS total')
            ->groupBy('category')
            ->get();

        $cashIncomesTotal = (float) $incomeRows->sum('total');
        $cashIncomesByCategory = $incomeRows
            ->mapWithKeys(fn ($r) => [$r->category => round((float) $r->total, 2)])
            ->all();

        // Cash drawer físico: saldo inicial + efectivo cobrado + propinas en efectivo
        //                     + entradas en efectivo - refunds en efectivo - egresos en efectivo.
        $cashDrawer = $openingTotal
            + $byMethod['cash']['gross']
            + $byMethod['cash']['tips']
            + $cashIncomesTotal
            - $byMethod['cash']['refunds']
            - $cashExpensesTotal;

        // Conteo de órdenes operadas en el período (por paid_at).
        $orderCount = (int) Order::where('company_nit', $companyNit)
            ->whereHas('receipts', function ($q) use ($fromDb, $toDb) {
                $q->whereBetween('paid_at', [$fromDb, $toDb])
                    ->where('payment_method', '!=', 'refund');
            })
            ->count();

        return [
            'by_method' => $byMethod,
            'totals' => [
                'gross' => round($totalGross, 2),
                'refunds' => round($totalRefunds, 2),
                'net' => round($totalNet, 2),
                'tips' => round($totalTips, 2),
            ],
            'cash_drawer_expected' => round($cashDrawer, 2),
            'cash_opening_amount' => round($openingTotal, 2),
            'cash_expenses_total' => round($cashExpensesTotal, 2),
            'cash_incomes_total' => round($cashIncomesTotal, 2),
            'cash_incomes_by_category' => $cashIncomesByCategory,
            'orders_count' => $orderCount,
        ];
    }
}
