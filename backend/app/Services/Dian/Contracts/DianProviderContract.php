<?php

declare(strict_types=1);

namespace App\Services\Dian\Contracts;

use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Services\Dian\DTOs\DocumentDto;
use App\Services\Dian\DTOs\ProviderResponse;

/**
 * Contrato neutral del proveedor DIAN.
 *
 * Cualquier impl (mock, factura1, siigo, carvajal, mypc, dispapeles, etc.)
 * debe respetar esta interfaz. El `DianDispatchService` y los jobs solo
 * conocen este contrato → cambiar de proveedor es bindear otra clase a
 * `provider_slug` y rotar credenciales en `dian_provider_configs`.
 *
 * El reemplazo es transparente para APIs/UI/audit (regla del refinamiento
 * §10.7): documentos previos quedan como historial; nuevos se emiten con
 * el provider activo.
 */
interface DianProviderContract
{
    /**
     * Envía el documento al servicio DIAN del proveedor.
     *
     * Idempotencia: el caller (DianDispatchService) ya hizo lock + snapshot.
     * El provider debe asumir que cada `send` es una emisión nueva — si
     * recibe el mismo `DocumentDto` dos veces, debe responder consistente
     * (mismo trackId si el provider lo soporta, distinto si no — la
     * defensa de duplicados vive en BD vía UNIQUE).
     */
    public function send(DocumentDto $dto, DianProviderConfig $config): ProviderResponse;

    /**
     * Reintenta un documento que está en `error`/`rejected`. El provider
     * decide si vuelve a enviar el mismo XML o computa uno nuevo.
     */
    public function retry(ElectronicDocument $document, DianProviderConfig $config): ProviderResponse;

    /**
     * Devuelve el slug canónico del provider (`mock`, `factura1`, etc.).
     * Se usa para snapshot en `electronic_documents.provider_slug`.
     */
    public function slug(): string;
}
