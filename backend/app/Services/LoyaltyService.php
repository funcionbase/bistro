<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyRedemption;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Núcleo del programa de fidelización.
 *
 * Reglas contables (CLAUDE.md):
 *  - Puntos NO son moneda. Nunca tocan payment_receipts.
 *  - loyalty_movements es append-only: errores se corrigen con un movement
 *    adicional de signo opuesto (type=adjust).
 *  - Todas las mutaciones bajo DB::transaction + lockForUpdate sobre la cuenta.
 *  - Idempotencia del award garantizada por UNIQUE PARCIAL en BD
 *    (reference_type='order' + reference_id + type='earn'). Si dos requests
 *    intentan award del mismo order, la segunda recibe QueryException y
 *    devolvemos el movement existente sin duplicar puntos.
 */
class LoyaltyService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly CompanySettingsService $settings,
    ) {}

    /**
     * Otorga puntos por una orden completed. Idempotente por order_id.
     *
     * Si el cliente no tiene teléfono, no se otorgan puntos (los puntos no
     * pueden materializarse en una cuenta anónima).
     */
    public function award(Order $order, ?User $actor = null): ?LoyaltyMovement
    {
        if (! $this->isEnabledFor($order->company_nit)) {
            return null;
        }

        $phone = CrmService::normalizePhone((string) ($order->client_phone ?? ''));
        if ($phone === '') {
            return null;
        }

        $orderTotal = (float) $order->total;
        if ($orderTotal <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $phone, $orderTotal, $actor): ?LoyaltyMovement {
            $account = $this->lockOrCreateAccount($order->company_nit, $phone);

            $config = $this->configFor($order->company_nit);
            $pointsPerCop = (float) $config['points_per_cop'];
            $tier = $account->tier ?: $this->tierFor(0, $config['tiers']);
            $multiplier = (float) ($config['tiers'][$tier]['earn_multiplier'] ?? 1.0);

            $points = (int) floor($orderTotal * $pointsPerCop * $multiplier);
            if ($points <= 0) {
                return null;
            }

            try {
                $movement = LoyaltyMovement::create([
                    'loyalty_account_id' => $account->id,
                    'company_nit' => $order->company_nit,
                    'type' => LoyaltyMovement::TYPE_EARN,
                    'points' => $points,
                    'reference_type' => 'order',
                    'reference_id' => (string) $order->id,
                    'actor_id' => $actor?->id,
                    'meta' => [
                        'order_total' => $orderTotal,
                        'points_per_cop' => $pointsPerCop,
                        'tier_at_earn' => $tier,
                        'multiplier' => $multiplier,
                    ],
                ]);
            } catch (QueryException $e) {
                // UNIQUE PARCIAL (reference_type='order', reference_id, type='earn'):
                // ya se otorgó. Devolvemos el movement existente sin tocar balance.
                if ($this->isUniqueViolation($e)) {
                    return LoyaltyMovement::where('reference_type', 'order')
                        ->where('reference_id', (string) $order->id)
                        ->where('type', LoyaltyMovement::TYPE_EARN)
                        ->first();
                }
                throw $e;
            }

            $account->balance += $points;
            $account->lifetime_earned += $points;
            $account->tier = $this->tierFor($account->lifetime_earned, $config['tiers']);
            $account->last_activity_at = now();
            $account->save();

            $this->audit->log('loyalty.awarded', $actor, $account, [
                'order_id' => $order->id,
                'points' => $points,
                'tier' => $account->tier,
                'balance_after' => $account->balance,
                'movement_id' => $movement->id,
            ]);

            return $movement;
        });
    }

    /**
     * Reversa los puntos otorgados por una orden devuelta. Idempotente:
     * si ya hay un refund_reverse para esa orden, no crea otro.
     */
    public function refundReverse(Order $order, ?User $actor = null): ?LoyaltyMovement
    {
        if (! $this->isEnabledFor($order->company_nit)) {
            return null;
        }
        if (! (bool) ($this->configFor($order->company_nit)['refund_reverses_points'] ?? true)) {
            return null;
        }

        $earn = LoyaltyMovement::where('reference_type', 'order')
            ->where('reference_id', (string) $order->id)
            ->where('type', LoyaltyMovement::TYPE_EARN)
            ->first();

        if (! $earn) {
            return null;
        }

        $existing = LoyaltyMovement::where('reference_type', 'order')
            ->where('reference_id', (string) $order->id)
            ->where('type', LoyaltyMovement::TYPE_REFUND_REVERSE)
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $earn, $actor): LoyaltyMovement {
            /** @var LoyaltyAccount $account */
            $account = LoyaltyAccount::whereKey($earn->loyalty_account_id)->lockForUpdate()->first();

            // Reversa total de los puntos otorgados. Si el balance actual es
            // menor (porque el cliente ya canjeó), permitimos que vaya negativo;
            // el lifetime_earned también se ajusta. Esto es consistente con la
            // regla contable: la reversa refleja el evento financiero original.
            $points = -1 * (int) $earn->points;

            $movement = LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'company_nit' => $order->company_nit,
                'type' => LoyaltyMovement::TYPE_REFUND_REVERSE,
                'points' => $points,
                'reference_type' => 'order',
                'reference_id' => (string) $order->id,
                'actor_id' => $actor?->id,
                'meta' => [
                    'reverses_movement_id' => $earn->id,
                    'reverses_points' => $earn->points,
                ],
            ]);

            $account->balance += $points;
            $account->lifetime_earned = max(0, $account->lifetime_earned + $points);
            $account->tier = $this->tierFor($account->lifetime_earned, $this->configFor($order->company_nit)['tiers']);
            $account->last_activity_at = now();
            $account->save();

            $this->audit->log('loyalty.refund_reversed', $actor, $account, [
                'order_id' => $order->id,
                'points' => $points,
                'balance_after' => $account->balance,
                'tier' => $account->tier,
                'reverses_movement_id' => $earn->id,
            ]);

            return $movement;
        });
    }

    /**
     * Canjea un reward del catálogo: descuenta puntos y emite un Coupon temporal
     * single-use vinculado al phone del cliente. El cupón vive
     * config('loyalty.redemption_expires_minutes') minutos.
     *
     * @return array{redemption: LoyaltyRedemption, coupon: Coupon, account: LoyaltyAccount}
     */
    public function redeem(string $companyNit, string $clientPhone, string $rewardKey, ?User $actor = null): array
    {
        if (! $this->isEnabledFor($companyNit)) {
            throw ValidationException::withMessages(['loyalty' => 'El programa de fidelización está deshabilitado para esta empresa.']);
        }

        $phone = CrmService::normalizePhone($clientPhone);
        if ($phone === '') {
            throw ValidationException::withMessages(['client_phone' => 'Teléfono inválido.']);
        }

        $config = $this->configFor($companyNit);
        $reward = $config['rewards'][$rewardKey] ?? null;
        if (! $reward) {
            throw ValidationException::withMessages(['reward_key' => 'Recompensa desconocida.']);
        }

        return DB::transaction(function () use ($companyNit, $phone, $rewardKey, $reward, $config, $actor): array {
            $account = $this->lockOrCreateAccount($companyNit, $phone);

            $cost = (int) $reward['points'];
            if ($account->balance < $cost) {
                throw ValidationException::withMessages(['points' => "Saldo insuficiente. Se requieren {$cost} puntos y tienes {$account->balance}."]);
            }

            $expiresAt = now()->addMinutes((int) $config['redemption_expires_minutes']);

            $couponCode = $this->generateUniqueCouponCode($companyNit);

            // El cupón se inscribe en la sede activa del actor (si existe). Para
            // canjes públicos sin sede activa, se inscribe en la primera sede
            // de la empresa por compatibilidad con la FK NOT NULL branch_id.
            $branchId = request()?->attributes->get('active_branch_id')
                ?? DB::table('branches')
                    ->where('company_nit', $companyNit)
                    ->whereNull('archived_at')
                    ->orderByDesc('is_default')
                    ->orderBy('created_at')
                    ->value('id');

            // scope='company' para que el cupón aplique en cualquier sede de la
            // empresa — consistente con la decisión cross-sede del programa.
            $coupon = Coupon::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'scope' => 'company',
                'valid_in_branches' => null,
                'code' => $couponCode,
                'type' => $reward['discount_type'] === 'percentage' ? 'percentage' : 'fixed_amount',
                'value' => (float) $reward['discount_value'],
                'valid_from' => now(),
                'valid_until' => $expiresAt,
                'max_uses' => 1,
                'uses_count' => 0,
                'min_order_amount' => (float) ($reward['min_order_amount'] ?? 0),
                'first_order_only' => false,
                'is_active' => true,
                'status' => 'active',
                'is_single_use' => true,
                'locked_to_phone' => $phone,
                'source' => 'loyalty_redeem',
                'created_by' => $actor?->id ? (string) $actor->id : 'loyalty_redeem',
            ]);

            $movement = LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'company_nit' => $companyNit,
                'type' => LoyaltyMovement::TYPE_REDEEM,
                'points' => -$cost,
                'reference_type' => 'coupon',
                'reference_id' => (string) $coupon->id,
                'actor_id' => $actor?->id,
                'meta' => [
                    'reward_key' => $rewardKey,
                    'reward_label' => $reward['label'] ?? null,
                    'coupon_code' => $couponCode,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);

            $redemption = LoyaltyRedemption::create([
                'loyalty_account_id' => $account->id,
                'loyalty_movement_id' => $movement->id,
                'coupon_id' => $coupon->id,
                'reward_key' => $rewardKey,
                'points' => $cost,
                'status' => LoyaltyRedemption::STATUS_ISSUED,
                'expires_at' => $expiresAt,
            ]);

            $account->balance -= $cost;
            $account->last_activity_at = now();
            $account->save();

            $this->audit->log('loyalty.redeemed', $actor, $account, [
                'reward_key' => $rewardKey,
                'points' => $cost,
                'coupon_id' => $coupon->id,
                'coupon_code' => $couponCode,
                'balance_after' => $account->balance,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return ['redemption' => $redemption, 'coupon' => $coupon, 'account' => $account];
        });
    }

    /**
     * Ajuste manual del staff. Suma o resta puntos. signed.
     */
    public function adjust(string $companyNit, string $clientPhone, int $points, ?string $reason, User $actor): LoyaltyMovement
    {
        if (! $this->isEnabledFor($companyNit)) {
            throw ValidationException::withMessages(['loyalty' => 'El programa de fidelización está deshabilitado para esta empresa.']);
        }

        $phone = CrmService::normalizePhone($clientPhone);
        if ($phone === '') {
            throw ValidationException::withMessages(['client_phone' => 'Teléfono inválido.']);
        }

        $cap = (int) ($this->configFor($companyNit)['max_manual_adjust_per_movement'] ?? 10000);
        if (abs($points) > $cap) {
            throw ValidationException::withMessages(['points' => "El ajuste excede el tope de {$cap} puntos por movimiento."]);
        }
        if ($points === 0) {
            throw ValidationException::withMessages(['points' => 'El ajuste no puede ser cero.']);
        }

        return DB::transaction(function () use ($companyNit, $phone, $points, $reason, $actor): LoyaltyMovement {
            $account = $this->lockOrCreateAccount($companyNit, $phone);

            // Para ajustes negativos no permitimos dejar balance < 0 (regla pragmática:
            // los puntos son del cliente, un ajuste no debe meterlo en "deuda" salvo
            // refund_reverse que sí refleja un evento financiero).
            if ($points < 0 && $account->balance + $points < 0) {
                throw ValidationException::withMessages(['points' => "Saldo insuficiente para ajuste negativo. Balance actual: {$account->balance}."]);
            }

            $movement = LoyaltyMovement::create([
                'loyalty_account_id' => $account->id,
                'company_nit' => $companyNit,
                'type' => LoyaltyMovement::TYPE_ADJUST,
                'points' => $points,
                'reference_type' => 'manual',
                'reference_id' => null,
                'actor_id' => $actor->id,
                'meta' => ['reason' => $reason],
            ]);

            $account->balance += $points;
            // Los ajustes positivos NO suman a lifetime_earned (no son compra real)
            // — esto evita inflar tiers artificialmente. Solo earn legítimo (orden)
            // mueve lifetime_earned.
            $account->last_activity_at = now();
            $account->save();

            $this->audit->log('loyalty.adjusted', $actor, $account, [
                'points' => $points,
                'reason' => $reason,
                'balance_after' => $account->balance,
                'movement_id' => $movement->id,
            ]);

            return $movement;
        });
    }

    /**
     * Expira balance de cuentas sin earn nuevo en más de N meses.
     *
     * @return array{accounts_expired: int, points_expired: int}
     */
    public function expireStale(string $companyNit): array
    {
        $months = (int) ($this->configFor($companyNit)['expire_after_months'] ?? 0);
        if ($months <= 0) {
            return ['accounts_expired' => 0, 'points_expired' => 0];
        }

        $cutoff = CarbonImmutable::now()->subMonths($months);

        $accountsExpired = 0;
        $pointsExpired = 0;

        LoyaltyAccount::forCompany($companyNit)
            ->where('balance', '>', 0)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_activity_at', '<', $cutoff)->orWhereNull('last_activity_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$accountsExpired, &$pointsExpired, $cutoff, $companyNit) {
                foreach ($chunk as $candidate) {
                    DB::transaction(function () use ($candidate, &$accountsExpired, &$pointsExpired, $cutoff, $companyNit) {
                        /** @var LoyaltyAccount $account */
                        $account = LoyaltyAccount::whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $account || $account->balance <= 0) {
                            return;
                        }
                        if ($account->last_activity_at !== null && $account->last_activity_at->gte($cutoff)) {
                            return;
                        }

                        $expiredPoints = $account->balance;

                        LoyaltyMovement::create([
                            'loyalty_account_id' => $account->id,
                            'company_nit' => $companyNit,
                            'type' => LoyaltyMovement::TYPE_EXPIRE,
                            'points' => -$expiredPoints,
                            'reference_type' => 'system',
                            'reference_id' => 'expire-stale',
                            'actor_id' => null,
                            'meta' => [
                                'cutoff' => $cutoff->toIso8601String(),
                                'last_activity_at' => $account->last_activity_at?->toIso8601String(),
                            ],
                        ]);

                        $account->balance = 0;
                        $account->save();

                        $accountsExpired++;
                        $pointsExpired += $expiredPoints;
                    });
                }
            });

        return ['accounts_expired' => $accountsExpired, 'points_expired' => $pointsExpired];
    }

    /**
     * Determina el tier correspondiente a un lifetime dado, recorriendo el
     * arreglo de tiers ascendentemente.
     *
     * @param  array<string, array{min_lifetime: int, earn_multiplier: float}>  $tiers
     */
    public function tierFor(int $lifetimeEarned, array $tiers): string
    {
        $current = 'bronze';
        foreach ($tiers as $key => $cfg) {
            if ($lifetimeEarned >= (int) $cfg['min_lifetime']) {
                $current = $key;
            }
        }

        return $current;
    }

    /**
     * Devuelve la info de tier + progreso para presentación al cliente.
     *
     * @return array{tier: string, multiplier: float, next_tier: ?string, points_to_next: ?int, progress_pct: float}
     */
    public function tierProgress(string $companyNit, int $lifetimeEarned): array
    {
        $tiers = $this->configFor($companyNit)['tiers'];
        $ordered = collect($tiers)->map(fn (array $v, string $k) => $v + ['key' => $k])->values()->sortBy('min_lifetime')->values();

        $currentKey = $this->tierFor($lifetimeEarned, $tiers);
        $currentIdx = $ordered->search(fn (array $t) => $t['key'] === $currentKey);
        $current = $ordered[$currentIdx];
        $next = $ordered[$currentIdx + 1] ?? null;

        if ($next === null) {
            return [
                'tier' => $currentKey,
                'multiplier' => (float) $current['earn_multiplier'],
                'next_tier' => null,
                'points_to_next' => null,
                'progress_pct' => 100.0,
            ];
        }

        $span = (int) $next['min_lifetime'] - (int) $current['min_lifetime'];
        $into = max(0, $lifetimeEarned - (int) $current['min_lifetime']);
        $pct = $span > 0 ? min(100.0, round($into / $span * 100, 2)) : 0.0;

        return [
            'tier' => $currentKey,
            'multiplier' => (float) $current['earn_multiplier'],
            'next_tier' => $next['key'],
            'points_to_next' => max(0, (int) $next['min_lifetime'] - $lifetimeEarned),
            'progress_pct' => $pct,
        ];
    }

    public function isEnabledFor(string $companyNit): bool
    {
        return (bool) ($this->configFor($companyNit)['enabled'] ?? false);
    }

    /**
     * Mezcla defaults globales con overrides de company_settings.
     *
     * @return array<string, mixed>
     */
    public function configFor(string $companyNit): array
    {
        $base = (array) config('loyalty');

        $overrides = [
            'enabled' => $this->settings->get($companyNit, 'loyalty.enabled'),
            'points_per_cop' => $this->settings->get($companyNit, 'loyalty.points_per_cop'),
            'tiers' => $this->settings->get($companyNit, 'loyalty.tiers'),
            'refund_reverses_points' => $this->settings->get($companyNit, 'loyalty.refund_reverses_points'),
            'expire_after_months' => $this->settings->get($companyNit, 'loyalty.expire_after_months'),
        ];

        foreach ($overrides as $k => $v) {
            if ($v !== null && $v !== '') {
                $base[$k] = $v;
            }
        }

        return $base;
    }

    /**
     * @return array<string, array{points: int, label: string, discount_type: string, discount_value: float, min_order_amount: float}>
     */
    public function rewardsFor(string $companyNit): array
    {
        return $this->configFor($companyNit)['rewards'] ?? [];
    }

    public function findAccount(string $companyNit, string $clientPhone): ?LoyaltyAccount
    {
        $phone = CrmService::normalizePhone($clientPhone);
        if ($phone === '') {
            return null;
        }

        return LoyaltyAccount::forClient($companyNit, $phone)->first();
    }

    private function lockOrCreateAccount(string $companyNit, string $phone): LoyaltyAccount
    {
        /** @var ?LoyaltyAccount $account */
        $account = LoyaltyAccount::forClient($companyNit, $phone)->lockForUpdate()->first();

        if ($account) {
            return $account;
        }

        // firstOrCreate evita race con UNIQUE (company_nit, client_phone).
        $account = LoyaltyAccount::firstOrCreate(
            ['company_nit' => $companyNit, 'client_phone' => $phone],
            ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'bronze', 'last_activity_at' => null],
        );

        return LoyaltyAccount::whereKey($account->id)->lockForUpdate()->first();
    }

    private function generateUniqueCouponCode(string $companyNit): string
    {
        for ($i = 0; $i < 5; $i++) {
            $code = 'LYL-'.strtoupper(Str::random(8));
            $exists = Coupon::where('company_nit', $companyNit)->where('code', $code)->exists();
            if (! $exists) {
                return $code;
            }
        }
        throw new \RuntimeException('No se pudo generar un código único de cupón de canje.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres SQLSTATE 23505 (unique_violation). El proyecto usa Postgres.
        return (string) $e->getCode() === '23505' || str_contains((string) $e->getMessage(), 'duplicate key value');
    }
}
