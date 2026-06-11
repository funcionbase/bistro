<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health checks consumidos por el ALB y por dashboards de operación.
 *
 *  - /health/live   → liveness simple. Sin DB ni S3. 200 si el proceso responde.
 *  - /health/ready  → readiness completo. Valida DB (Supabase) + S3 (assets).
 *                     503 si alguna dependencia critica falla. Es el endpoint
 *                     que el ALB Target Group consulta (ver
 *                     aws/iac/cloudformation/parameters/{qa,pdn}.json
 *                     HealthCheckPath=/health/ready).
 *
 * Ver issue #43 (T4).
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDatabase(),
            's3' => $this->checkS3(),
        ];

        $healthy = collect($checks)->every(fn (array $result): bool => $result['ok'] === true);

        return response()->json(
            ['status' => $healthy ? 'ok' : 'fail'] + $checks,
            $healthy ? 200 : 503
        );
    }

    /** @return array{ok: bool, error?: string} */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            Log::warning('health.db.fail', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'db_unreachable'];
        }
    }

    /** @return array{ok: bool, error?: string} */
    private function checkS3(): array
    {
        try {
            $disk = (string) config('filesystems.default');
            $exists = Storage::disk($disk)->exists('.health');

            return $exists ? ['ok' => true] : ['ok' => false, 'error' => 's3_marker_missing'];
        } catch (Throwable $e) {
            Log::warning('health.s3.fail', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 's3_unreachable'];
        }
    }
}
