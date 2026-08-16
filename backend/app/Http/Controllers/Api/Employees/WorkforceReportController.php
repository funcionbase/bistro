<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\FeaturePermissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes consolidados de horas y costo estimado.
 *
 * Cálculo en SQL — nunca iterar en PHP por reglas contables. costo_estimado
 * normaliza pay_rate × horas_ejecutadas según pay_type del empleado:
 *  - hora:      pay_rate × horas_ejecutadas
 *  - diario:    pay_rate × (horas / 8)
 *  - semanal:   pay_rate × (horas / 48)
 *  - quincenal: pay_rate × (horas / 96)
 *  - mensual:   pay_rate × (horas / 192)
 *
 * NOTA: aproximación operativa, NO equivale a nómina formal. La nómina con
 * prestaciones/parafiscales queda fuera del MVP.
 */
class WorkforceReportController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly FeaturePermissionService $permissionService) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'workforce', 'read');
        $nit = $this->activeCompanyNit($request);
        $filters = $this->resolveFilters($request);

        $rows = $this->aggregate($nit, $filters);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presentRow($r))->values()->all(),
            'totals' => $this->presentTotals($rows),
            'filters' => $filters,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->permissionService->assertPermission($request, 'workforce', 'read');
        $nit = $this->activeCompanyNit($request);
        $filters = $this->resolveFilters($request);
        $rows = $this->aggregate($nit, $filters);

        $filename = 'colaboradores_'.Carbon::today()->format('Y-m-d').'.csv';

        $response = new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 — Excel/LibreOffice respetan acentos.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Colaborador',
                'Documento',
                'Cargo',
                'Sede principal',
                'Horas asignadas',
                'Horas ejecutadas',
                'Horas canceladas',
                'Cancelaciones por enfermedad',
                'Cancelaciones por estado',
                'Cancelaciones otras',
                'Costo estimado (COP)',
            ]);

            foreach ($rows as $row) {
                $presented = $this->presentRow($row);
                fputcsv($handle, [
                    $presented['full_name'],
                    $presented['doc_number'],
                    $presented['position'],
                    $presented['primary_branch'],
                    $presented['scheduled_hours'],
                    $presented['executed_hours'],
                    $presented['cancelled_hours'],
                    $presented['cancellations']['sick'],
                    $presented['cancellations']['vinculation_state'],
                    $presented['cancellations']['other'],
                    number_format($presented['estimated_cost'], 2, '.', ''),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    public function exportPdf(Request $request): Response
    {
        $this->permissionService->assertPermission($request, 'workforce', 'read');
        $nit = $this->activeCompanyNit($request);
        $filters = $this->resolveFilters($request);
        $rows = $this->aggregate($nit, $filters);

        $presented = $rows->map(fn ($r) => $this->presentRow($r))->values()->all();

        $data = [
            'rows' => $presented,
            'totals' => $this->presentTotals($rows),
            'filters' => $filters,
            'generatedAt' => Carbon::now('America/Bogota')->format('d/m/Y H:i'),
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            'companyName' => $nit,
        ];

        $pdf = Pdf::loadView('pdf.workforce-report', $data);
        $pdf->setPaper(config('pdf.paper_size', 'A4'), 'landscape');

        return $pdf->stream('colaboradores_'.Carbon::today()->format('Y-m-d').'.pdf');
    }

    /** @return array<string, mixed> */
    private function resolveFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
        ]);

        return [
            'from' => Carbon::parse($data['from'])->toDateString(),
            'to' => Carbon::parse($data['to'])->toDateString(),
            'branch_id' => $data['branch_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }

    /**
     * Agrega horas por colaborador. Ejecuta SQL crudo para que la división
     * entre estados se haga en una sola pasada. Las horas se calculan como
     * EXTRACT(EPOCH FROM ends_at - starts_at) / 3600 con CAST a decimal.
     *
     * @param  array<string, mixed>  $filters
     */
    private function aggregate(string $companyNit, array $filters): Collection
    {
        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->endOfDay();

        $driver = DB::connection()->getDriverName();
        $hoursExpr = $driver === 'pgsql'
            ? '(EXTRACT(EPOCH FROM (es.ends_at - es.starts_at)) / 3600.0)'
            : '((julianday(es.ends_at) - julianday(es.starts_at)) * 24)';

        $query = Employee::query()
            ->select('employees.*')
            ->leftJoin('employee_positions as ep', 'ep.id', '=', 'employees.position_id')
            ->leftJoin('branches as br', 'br.id', '=', 'employees.primary_branch_id')
            ->where('employees.company_nit', $companyNit)
            ->whereNull('employees.archived_at')
            ->when($filters['branch_id'], fn ($q, $bid) => $q->where('employees.primary_branch_id', $bid))
            ->when($filters['employee_id'], fn ($q, $eid) => $q->where('employees.id', $eid))
            ->when($filters['status'], fn ($q, $s) => $q->where('employees.vinculation_status', $s))
            ->selectRaw('ep.label AS position_label, ep.color AS position_color, br.name AS branch_name')
            ->selectSub(function ($q) use ($hoursExpr, $from, $to) {
                $q->fromRaw('employee_shifts es')
                    ->whereColumn('es.employee_id', 'employees.id')
                    ->where('es.starts_at', '<', $to)
                    ->where('es.ends_at', '>', $from)
                    ->selectRaw("COALESCE(SUM($hoursExpr), 0)");
            }, 'scheduled_hours_raw')
            ->selectSub(function ($q) use ($hoursExpr, $from, $to) {
                $q->fromRaw('employee_shifts es')
                    ->whereColumn('es.employee_id', 'employees.id')
                    ->where('es.starts_at', '<', $to)
                    ->where('es.ends_at', '>', $from)
                    ->where('es.status', 'scheduled')
                    ->selectRaw("COALESCE(SUM($hoursExpr), 0)");
            }, 'executed_hours_raw')
            ->selectSub(function ($q) use ($hoursExpr, $from, $to) {
                $q->fromRaw('employee_shifts es')
                    ->whereColumn('es.employee_id', 'employees.id')
                    ->where('es.starts_at', '<', $to)
                    ->where('es.ends_at', '>', $from)
                    ->where('es.status', 'cancelled')
                    ->selectRaw("COALESCE(SUM($hoursExpr), 0)");
            }, 'cancelled_hours_raw')
            ->selectSub(function ($q) use ($hoursExpr, $from, $to) {
                $q->fromRaw('employee_shifts es')
                    ->whereColumn('es.employee_id', 'employees.id')
                    ->where('es.starts_at', '<', $to)
                    ->where('es.ends_at', '>', $from)
                    ->where('es.status', 'cancelled')
                    ->where('es.cancellation_reason', 'sick')
                    ->selectRaw("COALESCE(SUM($hoursExpr), 0)");
            }, 'cancelled_sick_raw')
            ->selectSub(function ($q) use ($hoursExpr, $from, $to) {
                $q->fromRaw('employee_shifts es')
                    ->whereColumn('es.employee_id', 'employees.id')
                    ->where('es.starts_at', '<', $to)
                    ->where('es.ends_at', '>', $from)
                    ->where('es.status', 'cancelled')
                    ->where('es.cancellation_reason', 'vinculation_state')
                    ->selectRaw("COALESCE(SUM($hoursExpr), 0)");
            }, 'cancelled_state_raw');

        return $query->get();
    }

    /** @return array<string, mixed> */
    private function presentRow($employee): array
    {
        $scheduled = (float) ($employee->scheduled_hours_raw ?? 0);
        $executed = (float) ($employee->executed_hours_raw ?? 0);
        $cancelled = (float) ($employee->cancelled_hours_raw ?? 0);
        $sick = (float) ($employee->cancelled_sick_raw ?? 0);
        $state = (float) ($employee->cancelled_state_raw ?? 0);
        $other = max(0, $cancelled - $sick - $state);

        return [
            'employee_id' => $employee->id,
            'full_name' => trim("{$employee->first_name} {$employee->last_name}"),
            'doc_number' => $employee->doc_number,
            'position' => $employee->position_label ?? '—',
            'primary_branch' => $employee->branch_name ?? '—',
            'scheduled_hours' => round($scheduled, 2),
            'executed_hours' => round($executed, 2),
            'cancelled_hours' => round($cancelled, 2),
            'cancellations' => [
                'sick' => round($sick, 2),
                'vinculation_state' => round($state, 2),
                'other' => round($other, 2),
            ],
            'estimated_cost' => $this->estimateCost($executed, $employee->pay_type, $this->effectivePayRate($employee)),
            'pay_type' => $employee->pay_type,
            'vinculation_status' => $employee->vinculation_status,
        ];
    }

    /**
     * Devuelve la tarifa efectiva para estimaciones. Si pay_rate es 0 (sin configurar)
     * y existe base_salary, lo usa como fallback para evitar costos $0 silenciosos.
     */
    private function effectivePayRate(mixed $employee): float
    {
        $rate = (float) ($employee->pay_rate ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($employee->base_salary ?? 0);
        }

        return $rate;
    }

    private function estimateCost(float $executedHours, string $payType, float $payRate): float
    {
        $denominator = match ($payType) {
            'hora' => 1.0,
            'diario' => 8.0,
            'semanal' => 48.0,
            'quincenal' => 96.0,
            'mensual' => 192.0,
            default => 1.0,
        };

        return round($payRate * ($executedHours / $denominator), 2);
    }

    /** @return array<string, mixed> */
    private function presentTotals(Collection $rows): array
    {
        return [
            'scheduled_hours' => round((float) $rows->sum(fn ($r) => (float) ($r->scheduled_hours_raw ?? 0)), 2),
            'executed_hours' => round((float) $rows->sum(fn ($r) => (float) ($r->executed_hours_raw ?? 0)), 2),
            'cancelled_hours' => round((float) $rows->sum(fn ($r) => (float) ($r->cancelled_hours_raw ?? 0)), 2),
            'estimated_cost' => round($rows->reduce(function ($carry, $r) {
                return $carry + $this->estimateCost(
                    (float) ($r->executed_hours_raw ?? 0),
                    $r->pay_type,
                    $this->effectivePayRate($r),
                );
            }, 0.0), 2),
        ];
    }
}
