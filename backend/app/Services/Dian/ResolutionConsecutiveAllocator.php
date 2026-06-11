<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\DianResolution;
use App\Services\Dian\Exceptions\ResolutionExhaustedException;
use App\Services\Dian\Exceptions\ResolutionNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Asignación atómica del siguiente consecutivo DIAN.
 *
 * Add-on N-instance §2 (el punto más crítico): cuando dos instancias EC2
 * intentan emitir simultáneamente contra la misma resolución, sin lock se
 * llevarían el mismo `current_number + 1` y DIAN rechazaría el segundo
 * documento como duplicado (`FAJ24a: NumFac duplicado`) — quedando un hueco
 * en el rango autorizado.
 *
 * Defensa primaria: `SELECT ... FOR UPDATE` dentro de `DB::transaction`.
 * Postgres bloquea la fila para el resto de transacciones hasta el commit;
 * dos workers verán números secuenciales sin colisión.
 *
 * Defensas en profundidad (otros archivos):
 *  - UNIQUE compuesta `(nit, type, prefix, consecutive)` en
 *    `electronic_documents` — atrapa el caso patológico si el lock fallara.
 *  - UNIQUE en `unique_code` — el CUFE/CUDE es determinístico, mismo input
 *    → mismo hash → la UNIQUE atrapa el doble insert.
 *
 * NO usar `Cache::lock` para esto: el lock nativo de Postgres es atómico
 * por construcción con el UPDATE; agregar otra capa solo suma queries.
 */
class ResolutionConsecutiveAllocator
{
    /**
     * @return array{resolution_id: string, prefix: string, consecutive: int, full_number: string, technical_key: string, environment: string}
     *
     * @throws ResolutionNotFoundException
     * @throws ResolutionExhaustedException
     */
    public function allocateNext(string $companyNit, string $documentType, string $environment): array
    {
        return DB::transaction(function () use ($companyNit, $documentType, $environment) {
            /** @var DianResolution|null $resolution */
            $resolution = DianResolution::query()
                ->where('company_nit', $companyNit)
                ->where('document_type', $documentType)
                ->where('environment', $environment)
                ->where('is_active', true)
                ->whereDate('valid_until', '>=', now())
                ->lockForUpdate()
                ->first();

            if ($resolution === null) {
                throw new ResolutionNotFoundException($companyNit, $documentType, $environment);
            }

            $next = $resolution->current_number + 1;

            if ($next > $resolution->range_to) {
                throw new ResolutionExhaustedException($resolution);
            }

            $resolution->current_number = $next;
            $resolution->save();

            return [
                // dian_resolutions.id es uuid. Antes había un `(int)` cast
                // residuo de cuando se asumió bigIncrements — en uuid devolvía
                // PHP_INT_MAX silenciosamente y reventaba el INSERT en
                // electronic_documents.dian_resolution_id (uuid).
                'resolution_id' => (string) $resolution->getKey(),
                'prefix' => $resolution->prefix,
                'consecutive' => $next,
                'full_number' => $resolution->prefix.$next,
                'technical_key' => $resolution->technical_key,
                'environment' => $resolution->environment,
            ];
        });
    }
}
