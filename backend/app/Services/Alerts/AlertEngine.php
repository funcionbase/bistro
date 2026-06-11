<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Company;
use App\Services\Alerts\Evaluators\CostIncreaseEvaluator;
use App\Services\Alerts\Evaluators\Evaluator;
use App\Services\Alerts\Evaluators\ItemLowVolumeEvaluator;
use App\Services\Alerts\Evaluators\LowStockEvaluator;
use App\Services\Alerts\Evaluators\MarginBelowEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador de evaluación de reglas de alerta (#124).
 *
 *  - Para cada empresa, recorre sus AlertRule habilitadas y dispatcha al
 *    evaluator correspondiente (strategy pattern).
 *  - Persiste los drafts en alert_events. Si ya existe un evento del día con
 *    el mismo (rule_id, target_type, target_id) — restricción UNIQUE PARCIAL
 *    por DATE(triggered_at) — actualiza payload/severity en lugar de crear
 *    uno nuevo. Esto evita ruido en el feed cuando el cron corre varias veces.
 *  - Cada empresa va en su propia transacción para que un error en una no
 *    bloquee el resto del lote.
 */
final class AlertEngine
{
    /** @var array<string, class-string<Evaluator>> */
    private const EVALUATORS = [
        AlertRule::TYPE_MARGIN_BELOW => MarginBelowEvaluator::class,
        AlertRule::TYPE_COST_INCREASE => CostIncreaseEvaluator::class,
        AlertRule::TYPE_ITEM_LOW_VOLUME => ItemLowVolumeEvaluator::class,
        AlertRule::TYPE_LOW_STOCK => LowStockEvaluator::class,
    ];

    public function __construct(
        private readonly AlertSeedService $seedService,
    ) {}

    /**
     * Evalúa todas las reglas habilitadas de todas las empresas.
     *
     * @return array{companies: int, drafts: int, persisted: int}
     */
    public function runAll(): array
    {
        $stats = ['companies' => 0, 'drafts' => 0, 'persisted' => 0];

        Company::query()
            ->select('nit')
            ->cursor()
            ->each(function (Company $company) use (&$stats): void {
                $stats['companies']++;
                $companyStats = $this->runForCompany($company->nit);
                $stats['drafts'] += $companyStats['drafts'];
                $stats['persisted'] += $companyStats['persisted'];
            });

        return $stats;
    }

    /**
     * @return array{drafts: int, persisted: int}
     */
    public function runForCompany(string $companyNit): array
    {
        $this->seedService->ensureDefaults($companyNit);

        $drafts = 0;
        $persisted = 0;

        try {
            $rules = AlertRule::query()
                ->forCompany($companyNit)
                ->enabled()
                ->get();

            foreach ($rules as $rule) {
                $evaluatorClass = self::EVALUATORS[$rule->type] ?? null;
                if ($evaluatorClass === null) {
                    continue;
                }

                /** @var Evaluator $evaluator */
                $evaluator = app($evaluatorClass);

                try {
                    $results = $evaluator->evaluate($rule);
                } catch (\Throwable $e) {
                    Log::error('AlertEngine evaluator failed', [
                        'company_nit' => $companyNit,
                        'type' => $rule->type,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $drafts += count($results);
                foreach ($results as $draft) {
                    if ($this->persist($rule, $draft)) {
                        $persisted++;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('AlertEngine company run failed', [
                'company_nit' => $companyNit,
                'error' => $e->getMessage(),
            ]);
        }

        return ['drafts' => $drafts, 'persisted' => $persisted];
    }

    /**
     * Inserta el evento o actualiza el del día si ya existe (dedup index).
     * Mantiene dismissed/actioned si el usuario ya manejó el evento del día.
     */
    private function persist(AlertRule $rule, AlertEventDraft $draft): bool
    {
        return DB::transaction(function () use ($rule, $draft): bool {
            $existing = AlertEvent::query()
                ->where('alert_rule_id', $rule->id)
                ->where('target_type', $draft->targetType)
                ->where(function ($q) use ($draft) {
                    if ($draft->targetId === null) {
                        $q->whereNull('target_id');
                    } else {
                        $q->where('target_id', $draft->targetId);
                    }
                })
                ->whereRaw('DATE(triggered_at) = CURRENT_DATE')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->payload = $draft->payload;
                $existing->severity = $draft->severity;
                $existing->save();

                return false;
            }

            AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'company_nit' => $rule->company_nit,
                'type' => $draft->type,
                'severity' => $draft->severity,
                'target_type' => $draft->targetType,
                'target_id' => $draft->targetId,
                'payload' => $draft->payload,
                'triggered_at' => now(),
            ]);

            return true;
        });
    }
}
