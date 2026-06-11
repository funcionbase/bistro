<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Convierte un string de período en un par [Carbon $start, Carbon $end].
 *
 * Períodos válidos: today, week (últimos 7 días), month (mes calendario actual), custom.
 * El período custom requiere date_from y date_to en formato Y-m-d; si faltan, lanza InvalidArgumentException.
 * Un período inválido lanza InvalidArgumentException — el controlador debe convertirlo en 422.
 * La zona horaria proviene de config metrics.timezone.
 */
class PeriodResolver
{
    /**
     * Resolve a period string and optional custom dates to a [start, end] Carbon pair.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolve(
        string $period = 'today',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $timezone = config('metrics.timezone', 'UTC');
        $now = Carbon::now($timezone);

        return match ($period) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'week' => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'month' => [
                $now->copy()->startOfMonth()->startOfDay(),
                $now->copy()->endOfMonth()->endOfDay(),
            ],
            'custom' => [
                Carbon::createFromFormat('Y-m-d', $dateFrom, $timezone)->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $dateTo, $timezone)->endOfDay(),
            ],
            default => throw new InvalidArgumentException("Invalid period: {$period}"),
        };
    }

    public static function cacheKey(string $prefix, string $companyNit, string $period, ?string $dateFrom = null, ?string $dateTo = null): string
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            $hash = substr(md5("{$dateFrom}_{$dateTo}"), 0, 8);

            return "metrics:{$companyNit}:{$prefix}:custom:{$hash}";
        }

        return "metrics:{$companyNit}:{$prefix}:{$period}";
    }
}
