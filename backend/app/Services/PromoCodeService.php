<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Billing\CompanyAlreadyHasActivePromoException;
use App\Exceptions\Billing\PromoCodeMaxCompaniesReachedException;
use App\Exceptions\Billing\PromoCodeNotApplicableException;
use App\Models\Company;
use App\Models\CompanyPromoCode;
use App\Models\PromoCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aplicación de promo codes a empresas — #246.
 *
 * Responsabilidades:
 *  - Validar slug (`validateBySlug`): existe, está activo, dentro de vigencia,
 *    no agotado.
 *  - Aplicar a empresa (`applyToCompany`): crear `CompanyPromoCode` con snapshot
 *    + incrementar `usage_count` atómicamente con `lockForUpdate`.
 *  - Cancelar (`cancelForCompany`): marcar fila activa como `cancelled` + log
 *    de auditoría.
 *  - Expirar (`expireOverdue`): cron job, marca filas vencidas como `expired`.
 *
 * Reglas de aplicación (`starts_at`):
 *  - `enrollment`: `companies.created_at` (registro). Si hay
 *    `paid_billing_starts_at > now()`, se difiere a esa fecha (decisión #246 #3).
 *  - `github_action` / `self_service`: primer día del próximo mes
 *    `America/Bogota`. Si hay trial activo, se difiere igual.
 *  - Override explícito: el caller puede pasar `$startsAt` para fijar la fecha
 *    de inicio (p.ej. el trial gratuito que arranca el mes de activación).
 *
 * Constraints enforced en DB:
 *  - UNIQUE parcial (company_nit) WHERE status='active' — solo 1 promo activo
 *    por empresa.
 *  - CHECK discount_percent ∈ [1, 100], months_duration ∈ [1, 120].
 *  - CHECK starts_at < ends_at.
 */
class PromoCodeService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Valida un slug y devuelve el PromoCode aplicable, o tira si no aplica.
     *
     * @throws PromoCodeNotApplicableException
     */
    public function validateBySlug(string $slug, ?CarbonInterface $at = null): PromoCode
    {
        $now = $at ?? CarbonImmutable::now();
        $code = PromoCode::query()->where('code', $this->normalize($slug))->first();

        if ($code === null) {
            throw PromoCodeNotApplicableException::notFound($slug);
        }

        if ($code->status !== 'active') {
            throw PromoCodeNotApplicableException::inactive($slug);
        }

        if ($code->starts_at !== null && $code->starts_at->gt($now)) {
            throw PromoCodeNotApplicableException::notYetActive($slug);
        }

        if ($code->ends_at !== null && $code->ends_at->lt($now)) {
            throw PromoCodeNotApplicableException::expired($slug);
        }

        if (! $code->hasRemainingCapacity()) {
            throw PromoCodeMaxCompaniesReachedException::for($slug);
        }

        return $code;
    }

    /**
     * Aplica el promo a la empresa. Crea `CompanyPromoCode` con snapshot
     * inmutable + incrementa `usage_count` atómicamente.
     *
     * @param  ?CarbonInterface  $startsAt  Override del inicio. Si se pasa, se
     *                                      usa tal cual (ignora la resolución por
     *                                      vector y el diferimiento por trial).
     *
     * @throws CompanyAlreadyHasActivePromoException
     * @throws PromoCodeMaxCompaniesReachedException
     */
    public function applyToCompany(
        Company $company,
        PromoCode $promoCode,
        string $appliedVia,
        ?string $appliedByUserId = null,
        ?CarbonInterface $at = null,
        ?CarbonInterface $startsAt = null,
    ): CompanyPromoCode {
        $now = $at ?? CarbonImmutable::now();

        return DB::transaction(function () use ($company, $promoCode, $appliedVia, $appliedByUserId, $now, $startsAt): CompanyPromoCode {
            // Lock the promo row to check capacity atomically.
            /** @var PromoCode $lockedCode */
            $lockedCode = PromoCode::query()
                ->where('id', $promoCode->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCode->status !== 'active') {
                throw PromoCodeNotApplicableException::inactive($lockedCode->code);
            }

            if (! $lockedCode->hasRemainingCapacity()) {
                throw PromoCodeMaxCompaniesReachedException::for($lockedCode->code);
            }

            // Verify the company doesn't already have an active promo.
            $existingActive = CompanyPromoCode::query()
                ->where('company_nit', $company->nit)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                throw CompanyAlreadyHasActivePromoException::for($company->nit, $existingActive->id);
            }

            $startsAt = $startsAt !== null
                ? CarbonImmutable::instance($startsAt)
                : $this->resolveStartsAt($company, $appliedVia, $now);
            $endsAt = $startsAt->copy()->addMonths($lockedCode->months_duration);

            $application = CompanyPromoCode::query()->create([
                'company_nit' => $company->nit,
                'promo_code_id' => $lockedCode->id,
                'discount_percent' => $lockedCode->discount_percent,
                'months_duration' => $lockedCode->months_duration,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
                'applied_via' => $appliedVia,
                'applied_by' => $appliedByUserId,
            ]);

            $lockedCode->forceFill(['usage_count' => $lockedCode->usage_count + 1])->save();

            $this->auditService->log('promo_code.applied', null, $application, [
                'company_nit' => $company->nit,
                'promo_code_id' => $lockedCode->id,
                'code' => $lockedCode->code,
                'discount_percent' => $lockedCode->discount_percent,
                'months_duration' => $lockedCode->months_duration,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'applied_via' => $appliedVia,
                'applied_by' => $appliedByUserId,
            ]);

            return $application;
        });
    }

    /**
     * Cancela el promo activo de una empresa. No restaura `usage_count`.
     * No-op si la empresa no tiene promo activo (idempotente).
     */
    public function cancelForCompany(
        Company $company,
        ?string $cancelledByUserId = null,
        ?CarbonInterface $at = null,
    ): ?CompanyPromoCode {
        $now = $at ?? CarbonImmutable::now();

        return DB::transaction(function () use ($company, $cancelledByUserId, $now): ?CompanyPromoCode {
            /** @var CompanyPromoCode|null $active */
            $active = CompanyPromoCode::query()
                ->where('company_nit', $company->nit)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($active === null) {
                return null;
            }

            $active->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by' => $cancelledByUserId,
            ])->save();

            $this->auditService->log('promo_code.cancelled', null, $active, [
                'company_nit' => $company->nit,
                'promo_code_id' => $active->promo_code_id,
                'cancelled_by' => $cancelledByUserId,
            ]);

            return $active;
        });
    }

    /**
     * Marca como `expired` toda fila activa cuya `ends_at < now`.
     * Idempotente. Llamado por scheduler (1×/día). Devuelve cantidad afectada.
     */
    public function expireOverdue(?CarbonInterface $at = null): int
    {
        $now = $at ?? CarbonImmutable::now();

        // Toma los IDs primero — permite auditar uno por uno sin lock global.
        $ids = CompanyPromoCode::query()
            ->where('status', 'active')
            ->where('ends_at', '<', $now)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $affected = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$affected): void {
                $row = CompanyPromoCode::query()
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if ($row === null || $row->status !== 'active') {
                    return;
                }

                $row->forceFill(['status' => 'expired'])->save();
                $this->auditService->log('promo_code.expired', null, $row, [
                    'company_nit' => $row->company_nit,
                    'promo_code_id' => $row->promo_code_id,
                    'ends_at' => $row->ends_at?->toIso8601String(),
                ]);
                $affected++;
            });
        }

        return $affected;
    }

    /**
     * Resuelve `starts_at` según vector y trial activo:
     *  - enrollment: ahora (created_at), salvo trial → paid_billing_starts_at.
     *  - github_action / self_service: primer día del próximo mes Bogota,
     *    salvo trial → paid_billing_starts_at.
     */
    private function resolveStartsAt(Company $company, string $appliedVia, CarbonInterface $now): CarbonImmutable
    {
        $bogotaNow = $now instanceof CarbonImmutable
            ? $now->setTimezone('America/Bogota')
            : CarbonImmutable::instance($now)->setTimezone('America/Bogota');

        $base = match ($appliedVia) {
            'enrollment' => CarbonImmutable::instance($company->created_at ?? $bogotaNow),
            'github_action', 'self_service' => $bogotaNow->copy()->addMonthNoOverflow()->startOfMonth(),
            default => $bogotaNow,
        };

        // Si la empresa tiene trial activo (paid_billing_starts_at futuro),
        // diferir el inicio del promo a esa fecha (#246 decisión #3).
        $paidStart = $company->paid_billing_starts_at;
        if ($paidStart !== null) {
            $paidStartImmutable = CarbonImmutable::instance($paidStart);
            if ($paidStartImmutable->gt($base)) {
                return $paidStartImmutable;
            }
        }

        return $base;
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
