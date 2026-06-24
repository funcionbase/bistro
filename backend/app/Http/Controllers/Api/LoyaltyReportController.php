<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Services\FeaturePermissionService;
use App\Services\LoyaltyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del programa de fidelización (#122).
 *
 * Todas las agregaciones se hacen en SQL (CLAUDE.md). El período se interpreta
 * sobre America/Bogota; los filtros del cliente llegan como ISO date strings.
 * Permiso: loyalty.read.
 */
class LoyaltyReportController extends Controller
{
    use ResolvesJwtActor;

    private const TZ = 'America/Bogota';

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly LoyaltyService $loyaltyService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'loyalty', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->resolveRange($validated);

        return response()->json([
            'data' => [
                'enabled' => $this->loyaltyService->isEnabledFor($companyNit),
                'range' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],
                'totals' => $this->totals($companyNit, $from, $to),
                'top_clients' => $this->topClients($companyNit, $from, $to),
                'redemption_rate' => $this->redemptionRate($companyNit, $from, $to),
                'arpu_by_tier' => $this->arpuByTier($companyNit, $from, $to),
                'expirations' => $this->expirationsSummary($companyNit, $from, $to),
                'tiers_distribution' => $this->tiersDistribution($companyNit),
            ],
        ]);
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     * @return array<int, CarbonImmutable>
     */
    private function resolveRange(array $validated): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], self::TZ)->startOfDay()
            : $now->copy()->subDays(30)->startOfDay();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'], self::TZ)->endOfDay()
            : $now->endOfDay();

        return [$from, $to];
    }

    /** @return array<string, mixed> */
    private function totals(string $nit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'earn'   THEN points ELSE 0 END), 0)::int           AS points_earned,
                COALESCE(SUM(CASE WHEN type = 'redeem' THEN -points ELSE 0 END), 0)::int          AS points_redeemed,
                COALESCE(SUM(CASE WHEN type = 'expire' THEN -points ELSE 0 END), 0)::int          AS points_expired,
                COALESCE(SUM(CASE WHEN type = 'refund_reverse' THEN -points ELSE 0 END), 0)::int  AS points_reversed,
                COUNT(*) FILTER (WHERE type = 'earn')::int                                        AS earn_events,
                COUNT(*) FILTER (WHERE type = 'redeem')::int                                      AS redeem_events,
                COUNT(DISTINCT loyalty_account_id) FILTER (WHERE type = 'earn')::int              AS active_earners
            FROM loyalty_movements
            WHERE company_nit = ?
              AND created_at BETWEEN ? AND ?",
            [$nit, $from->toDateTimeString(), $to->toDateTimeString()]
        );

        return (array) $row;
    }

    /** @return list<array<string, mixed>> */
    private function topClients(string $nit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::select(
            "SELECT
                a.client_phone,
                a.tier,
                a.balance::int          AS balance,
                a.lifetime_earned::int  AS lifetime_earned,
                COALESCE(SUM(CASE WHEN m.type = 'earn' THEN m.points ELSE 0 END), 0)::int AS points_earned_period,
                COALESCE(SUM(CASE WHEN m.type = 'redeem' THEN -m.points ELSE 0 END), 0)::int AS points_redeemed_period
            FROM loyalty_accounts a
            LEFT JOIN loyalty_movements m
              ON m.loyalty_account_id = a.id
             AND m.created_at BETWEEN ? AND ?
            WHERE a.company_nit = ?
            GROUP BY a.id
            ORDER BY a.lifetime_earned DESC
            LIMIT 20",
            [$from->toDateTimeString(), $to->toDateTimeString(), $nit]
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /** @return array<string, mixed> */
    private function redemptionRate(string $nit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) FILTER (WHERE status = ?)::int                            AS issued,
                COUNT(*) FILTER (WHERE status = ?)::int                            AS applied,
                COUNT(*) FILTER (WHERE status = ?)::int                            AS expired,
                COUNT(*) FILTER (WHERE status = ?)::int                            AS cancelled,
                COUNT(*)::int                                                      AS total
            FROM loyalty_redemptions r
            JOIN loyalty_accounts a ON a.id = r.loyalty_account_id
            WHERE a.company_nit = ?
              AND r.created_at BETWEEN ? AND ?',
            ['issued', 'applied', 'expired', 'cancelled', $nit, $from->toDateTimeString(), $to->toDateTimeString()]
        );

        $data = (array) $row;
        $data['rate'] = $data['total'] > 0
            ? round(($data['applied'] / $data['total']) * 100, 2)
            : 0.0;

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function arpuByTier(string $nit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // BUG-018: normalizar el teléfono del pedido al mismo formato que
        // loyalty_accounts (57XXXXXXXXXX) para que el JOIN no falle cuando
        // orders.client_phone está en formato local (10 dígitos sin prefijo).
        $rows = DB::select(
            "SELECT
                a.tier,
                COUNT(DISTINCT a.id)::int                                                AS clients,
                COALESCE(SUM(o.total), 0)::numeric(14,2)                                 AS revenue,
                CASE WHEN COUNT(DISTINCT a.id) > 0
                     THEN ROUND(COALESCE(SUM(o.total), 0)::numeric / COUNT(DISTINCT a.id), 2)
                     ELSE 0 END                                                          AS arpu
            FROM loyalty_accounts a
            LEFT JOIN orders o
              ON o.company_nit = a.company_nit
             AND (
                o.client_phone = a.client_phone
                OR CASE WHEN LENGTH(REGEXP_REPLACE(COALESCE(o.client_phone,''),'[^0-9]','','g')) = 10
                        THEN '57' || REGEXP_REPLACE(o.client_phone,'[^0-9]','','g')
                        ELSE REGEXP_REPLACE(COALESCE(o.client_phone,''),'[^0-9]','','g')
                   END = a.client_phone
             )
             AND o.status = 'completed'
             AND o.ordered_at BETWEEN ? AND ?
            WHERE a.company_nit = ?
            GROUP BY a.tier",
            [$from->toDateTimeString(), $to->toDateTimeString(), $nit]
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /** @return array<string, mixed> */
    private function expirationsSummary(string $nit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) FILTER (WHERE type = 'expire')::int                       AS events,
                COALESCE(SUM(CASE WHEN type = 'expire' THEN -points END), 0)::int  AS points_expired,
                COUNT(DISTINCT loyalty_account_id) FILTER (WHERE type = 'expire')::int AS accounts_expired
            FROM loyalty_movements
            WHERE company_nit = ?
              AND created_at BETWEEN ? AND ?",
            [$nit, $from->toDateTimeString(), $to->toDateTimeString()]
        );

        return (array) $row;
    }

    /** @return list<array<string, mixed>> */
    private function tiersDistribution(string $nit): array
    {
        $rows = DB::select(
            'SELECT tier, COUNT(*)::int AS clients, COALESCE(SUM(balance), 0)::int AS total_balance
             FROM loyalty_accounts
             WHERE company_nit = ?
             GROUP BY tier
             ORDER BY total_balance DESC',
            [$nit]
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }
}
