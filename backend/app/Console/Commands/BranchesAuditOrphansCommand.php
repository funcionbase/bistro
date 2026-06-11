<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de huérfanos por sede (#192 — branches:audit-orphans).
 *
 * Recorre todas las tablas con columna `branch_id` (introspección dinámica
 * sobre `information_schema.columns`) y reporta cuántas filas tienen
 * `branch_id IS NULL`. La migración fundacional declara `branch_id NOT NULL`
 * en todas las tablas operativas, por lo que el reporte esperado es `0`
 * huérfanos en QA y PDN. El comando existe como red de seguridad: detecta
 * regresiones donde una columna se haga nullable por error o donde un
 * backfill manual deje filas sin asignación.
 *
 * Flags:
 *  --json: emite reporte JSON en stdout (útil para CI / monitoreo).
 *  --fix-default: dentro de `DB::transaction`, reasigna huérfanos a la sede
 *    `is_default = true` de cada empresa. Si la empresa no tiene sede
 *    default, aborta sin tocar datos. Cada reasignación queda auditada con
 *    `branch.orphan_backfilled`. NUNCA se ejecuta desde scheduler — solo
 *    operación manual previa aprobación.
 *
 * Tablas exentas (no se auditan):
 *  branches — la sede es la entidad raíz, no tiene sentido auditarla
 *  contra sí misma.
 *
 * N-instance safety: comando de lectura por default. Si se programa, se
 * programa con `onOneServer()`. La forma `--fix-default` no debe ir al
 * scheduler — solo manual.
 */
class BranchesAuditOrphansCommand extends Command
{
    /** @var string */
    protected $signature = 'branches:audit-orphans
        {--fix-default : Reasigna huérfanos a la sede is_default de cada empresa (operación manual, audita cada fila).}
        {--json : Emite reporte JSON en stdout.}';

    /** @var string */
    protected $description = 'Audita filas con branch_id NULL en todas las tablas operativas (issue #192).';

    private const EXCLUDED_TABLES = ['branches'];

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tables = $this->discoverTablesWithBranchId();

        if ($tables === []) {
            $this->warn('No se encontraron tablas con columna branch_id. ¿La base de datos está vacía?');

            return self::SUCCESS;
        }

        $report = $this->buildReport($tables);

        if ($this->option('fix-default')) {
            $this->backfillOrphans($report);
            $report = $this->buildReport($tables);
        }

        $this->emit($report);

        $hasOrphans = collect($report)->some(fn (array $row): bool => $row['orphan_count'] > 0);

        return $hasOrphans ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function discoverTablesWithBranchId(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND column_name = 'branch_id'
            ORDER BY table_name
        SQL);

        return collect($rows)
            ->pluck('table_name')
            ->reject(fn (string $name): bool => in_array($name, self::EXCLUDED_TABLES, true))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{table: string, orphan_count: int, by_company: array<string, int>}>
     */
    private function buildReport(array $tables): array
    {
        $report = [];

        foreach ($tables as $table) {
            $columns = DB::select(<<<'SQL'
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
            SQL, [$table]);

            $columnNames = collect($columns)->pluck('column_name')->all();
            $hasCompanyNit = in_array('company_nit', $columnNames, true);

            $orphanCount = (int) DB::table($table)->whereNull('branch_id')->count();

            $byCompany = [];
            if ($orphanCount > 0 && $hasCompanyNit) {
                $rows = DB::table($table)
                    ->select('company_nit', DB::raw('COUNT(*) as cnt'))
                    ->whereNull('branch_id')
                    ->groupBy('company_nit')
                    ->get();

                foreach ($rows as $row) {
                    $byCompany[(string) $row->company_nit] = (int) $row->cnt;
                }
            }

            $report[] = [
                'table' => $table,
                'orphan_count' => $orphanCount,
                'by_company' => $byCompany,
            ];
        }

        return $report;
    }

    /**
     * @param  list<array{table: string, orphan_count: int, by_company: array<string, int>}>  $report
     */
    private function backfillOrphans(array $report): void
    {
        $touched = collect($report)->filter(fn (array $row): bool => $row['orphan_count'] > 0);

        if ($touched->isEmpty()) {
            $this->info('--fix-default: no hay huérfanos que reasignar.');

            return;
        }

        $this->warn('--fix-default: iniciando reasignación a sede is_default por empresa.');

        DB::transaction(function () use ($touched): void {
            foreach ($touched as $row) {
                $table = $row['table'];

                foreach ($row['by_company'] as $companyNit => $cnt) {
                    $defaultBranch = Branch::query()
                        ->where('company_nit', $companyNit)
                        ->where('is_default', true)
                        ->whereNull('archived_at')
                        ->first();

                    if ($defaultBranch === null) {
                        throw new \RuntimeException(sprintf(
                            'Empresa %s no tiene sede is_default activa. Aborta sin tocar datos. Crea la sede default antes de re-ejecutar.',
                            $companyNit,
                        ));
                    }

                    $affected = DB::table($table)
                        ->where('company_nit', $companyNit)
                        ->whereNull('branch_id')
                        ->update(['branch_id' => $defaultBranch->id]);

                    $this->auditService->log(
                        'branch.orphan_backfilled',
                        null,
                        null,
                        [
                            'table' => $table,
                            'company_nit' => $companyNit,
                            'branch_id' => $defaultBranch->id,
                            'affected_rows' => $affected,
                            'reason' => 'fix-default backfill via branches:audit-orphans',
                        ],
                    );

                    $this->info(sprintf(
                        '  %s (nit %s) → %s · %d filas reasignadas.',
                        $table, $companyNit, $defaultBranch->id, $affected,
                    ));
                }
            }
        });

        $this->info('--fix-default: reasignación completada en transacción.');
    }

    /**
     * @param  list<array{table: string, orphan_count: int, by_company: array<string, int>}>  $report
     */
    private function emit(array $report): void
    {
        $totalOrphans = collect($report)->sum('orphan_count');
        $companyCount = Company::query()->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'companies' => $companyCount,
                'tables_audited' => count($report),
                'total_orphans' => $totalOrphans,
                'details' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info(sprintf(
            'Auditoría de huérfanos por sede — %d empresa(s), %d tabla(s) inspeccionadas.',
            $companyCount, count($report),
        ));

        $rows = collect($report)
            ->map(fn (array $row): array => [
                $row['table'],
                $row['orphan_count'],
                $row['orphan_count'] === 0 ? 'ok' : 'requiere backfill',
                $row['by_company'] === [] ? '—' : collect($row['by_company'])
                    ->map(fn (int $cnt, string $nit): string => sprintf('%s: %d', $nit, $cnt))
                    ->implode(' · '),
            ])
            ->all();

        $this->table(['Tabla', 'Huérfanos', 'Estado', 'Por empresa'], $rows);

        if ($totalOrphans === 0) {
            $this->info('Sin huerfanos. Aislamiento por sede consistente.');
        } else {
            $this->error(sprintf(
                '%d filas con branch_id NULL detectadas. Re-ejecuta con --fix-default tras validar la sede destino.',
                $totalOrphans,
            ));
        }
    }
}
