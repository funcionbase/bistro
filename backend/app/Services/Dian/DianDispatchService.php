<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\Dian\Exceptions\NeedsRecipientDataException;
use App\Services\Dian\Providers\MockDianProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Orquestador de la emisión DIAN.
 *
 * Flujo (todo dentro de `DB::transaction` con `lockForUpdate` sobre orden,
 * resolución y provider config — add-on N-instance §2, §3, §7):
 *
 *   1. Asigna consecutivo atómico (ResolutionConsecutiveAllocator).
 *   2. Construye DocumentDto (DianDocumentBuilder + RecipientResolver).
 *      - Si el adquirente es Contact incompleto → status `needs_recipient_data`.
 *   3. Computa CUFE/CUDE (CufeCudeGenerator).
 *   4. Persiste fila `electronic_documents` con status `queued` + snapshot
 *      del provider activo (slug + environment).
 *   5. Renderiza XML (DianXmlBuilder) y PDF (DianRepresentationPdfBuilder),
 *      sube a S3 (paths `companies/{nit}/dian/{yyyy}/{mm}/{full_number}.{ext}`).
 *   6. Invoca al provider activo via DianProviderFactory.
 *   7. Actualiza status + provider_track_id + provider_response_log.
 *   8. Si status='sent' (async) y provider es mock → encola
 *      MockDianWebhookEmitter con delay aleatorio.
 *   9. Audita acción.
 *
 * El retorno es siempre la `ElectronicDocument` resultante (cualquiera sea
 * el status). El caller decide qué hacer con el status (aceptado, pendiente,
 * rechazado, error).
 */
class DianDispatchService
{
    public function __construct(
        private readonly ResolutionConsecutiveAllocator $allocator,
        private readonly DianDocumentBuilder $builder,
        private readonly CufeCudeGenerator $cufeGen,
        private readonly DianXmlBuilder $xmlBuilder,
        private readonly DianRepresentationPdfBuilder $pdfBuilder,
        private readonly DianProviderFactory $providerFactory,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{
     *   document_type: string,
     *   references_document_id?: int|null,
     *   is_auto_emit?: bool,
     * }  $payload
     */
    public function emit(Order $order, array $payload): ElectronicDocument
    {
        return DB::transaction(function () use ($order, $payload) {
            $order->refresh();
            $order->lockForUpdate();

            $company = Company::query()->where('nit', $order->company_nit)->lockForUpdate()->firstOrFail();

            $providerConfig = DianProviderConfig::query()
                ->where('company_nit', $company->nit)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $documentType = (string) $payload['document_type'];
            $references = isset($payload['references_document_id'])
                ? ElectronicDocument::query()->find($payload['references_document_id'])
                : null;

            $uniqueCodeType = $this->cudeOrCufe($documentType);

            $allocation = $this->allocator->allocateNext(
                $company->nit,
                $documentType,
                $providerConfig->environment,
            );

            try {
                $dto = $this->builder->build(
                    order: $order,
                    documentType: $documentType,
                    uniqueCodeType: $uniqueCodeType,
                    allocation: $allocation,
                    references: $references,
                    isAutoEmit: (bool) ($payload['is_auto_emit'] ?? false),
                );
            } catch (NeedsRecipientDataException $exception) {
                // No quemamos el consecutivo: ya fue asignado. La fila queda
                // marcada needs_recipient_data; cuando se completen los datos
                // se emite con un consecutivo nuevo (esta emisión queda como
                // borrador interno). El owner puede luego "Convertir a FEV".
                $document = $this->persistNeedsRecipient($order, $providerConfig, $allocation, $documentType, $uniqueCodeType);

                $this->audit->log('dian.document.needs_recipient_data', null, $document, [
                    'order_id' => $order->getKey(),
                    'phone' => $order->client_phone,
                    'contact_id' => $exception->contact->getKey(),
                ]);

                return $document;
            }

            $cufeOrCude = $this->cufeGen->generate([
                'full_number' => $dto->fullNumber,
                'issued_at' => $dto->issuedAt,
                'total' => $dto->total,
                'iva_amount' => $dto->ivaAmount,
                'inc_amount' => $dto->incAmount,
                'ica_amount' => $dto->icaAmount,
                'issuer_nit' => $dto->issuerNit,
                'recipient_doc_number' => $dto->recipient->docNumber,
                'technical_key' => $dto->technicalKey,
                'environment' => $dto->environment,
            ]);

            $document = ElectronicDocument::query()->create([
                'company_nit' => $company->nit,
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'dian_resolution_id' => $allocation['resolution_id'],
                'document_type' => $documentType,
                'prefix' => $allocation['prefix'],
                'consecutive' => $allocation['consecutive'],
                'full_number' => $allocation['full_number'],
                'unique_code' => $cufeOrCude,
                'unique_code_type' => $uniqueCodeType,
                'issued_at' => $dto->issuedAt,
                'status' => 'queued',
                'provider_slug' => $providerConfig->provider_slug,
                'dian_environment_code' => $providerConfig->environment,
                'references_document_id' => $references?->getKey(),
            ]);

            $xml = $this->xmlBuilder->build($dto, $cufeOrCude);
            $pdf = $this->pdfBuilder->build($dto, $cufeOrCude);

            $disk = (string) config('dian.storage_disk', 's3');
            $prefix = sprintf('companies/%s/dian/%s/%s', $company->nit, $dto->issuedAt->format('Y'), $dto->issuedAt->format('m'));
            $xmlPath = "{$prefix}/{$allocation['full_number']}.xml";
            $pdfPath = "{$prefix}/{$allocation['full_number']}.pdf";

            Storage::disk($disk)->put($xmlPath, $xml, ['visibility' => 'private']);
            Storage::disk($disk)->put($pdfPath, $pdf, ['visibility' => 'private']);

            $document->update([
                'xml_path' => $xmlPath,
                'pdf_path' => $pdfPath,
                'qr_data' => (config("dian.qr_base_url.{$dto->environment}") ?? '').$cufeOrCude,
                'status' => 'pending',
            ]);

            $provider = $this->providerFactory->make($providerConfig);
            $response = $provider->send($dto, $providerConfig);

            $document->update([
                'status' => $response->status,
                'provider_track_id' => $response->trackId,
                'provider_response_log' => $response->log,
                'sent_at' => now(),
                'accepted_at' => $response->status === 'accepted' ? now() : null,
                'rejected_at' => $response->status === 'rejected' ? now() : null,
                'rejection_reason' => $response->rejectionReason,
            ]);

            // Mock async: encola webhook simulado.
            if ($response->status === 'sent' && $provider instanceof MockDianProvider) {
                $provider->scheduleAsyncWebhook($document);
            }

            $this->audit->log('dian.document.emitted', null, $document, [
                'order_id' => $order->getKey(),
                'document_type' => $documentType,
                'full_number' => $allocation['full_number'],
                'unique_code' => $cufeOrCude,
                'provider_slug' => $providerConfig->provider_slug,
                'environment' => $providerConfig->environment,
                'status' => $response->status,
                'track_id' => $response->trackId,
            ]);

            return $document;
        });
    }

    public function retry(ElectronicDocument $document): ElectronicDocument
    {
        return DB::transaction(function () use ($document) {
            $document->refresh();
            $document->lockForUpdate();

            if (! $document->canBeRetried()) {
                throw new RuntimeException("Documento id={$document->id} no es reintentar-able (status={$document->status}).");
            }

            $providerConfig = DianProviderConfig::query()
                ->where('company_nit', $document->company_nit)
                ->where('is_active', true)
                ->firstOrFail();

            $provider = $this->providerFactory->make($providerConfig);
            $response = $provider->retry($document, $providerConfig);

            $document->update([
                'status' => $response->status,
                'provider_track_id' => $response->trackId,
                'provider_response_log' => array_merge($document->provider_response_log ?? [], ['retry' => $response->log]),
                'retry_count' => $document->retry_count + 1,
                'last_retry_at' => now(),
                'accepted_at' => $response->status === 'accepted' ? now() : $document->accepted_at,
                'rejected_at' => $response->status === 'rejected' ? now() : $document->rejected_at,
                'rejection_reason' => $response->rejectionReason,
            ]);

            $this->audit->log('dian.document.retry', null, $document, [
                'retry_count' => $document->retry_count,
                'previous_status' => $document->getOriginal('status'),
                'new_status' => $response->status,
            ]);

            return $document;
        });
    }

    public function cudeOrCufe(string $documentType): string
    {
        return in_array($documentType, ['pos_equivalent', 'pos_equivalent_credit_note'], true) ? 'cude' : 'cufe';
    }

    /**
     * @param  array{resolution_id: int, prefix: string, consecutive: int, full_number: string, technical_key: string, environment: string}  $allocation
     */
    private function persistNeedsRecipient(
        Order $order,
        DianProviderConfig $providerConfig,
        array $allocation,
        string $documentType,
        string $uniqueCodeType,
    ): ElectronicDocument {
        return ElectronicDocument::query()->create([
            'company_nit' => $order->company_nit,
            'branch_id' => $order->branch_id,
            'order_id' => $order->getKey(),
            'dian_resolution_id' => $allocation['resolution_id'],
            'document_type' => $documentType,
            'prefix' => $allocation['prefix'],
            'consecutive' => $allocation['consecutive'],
            'full_number' => $allocation['full_number'],
            // Hash placeholder no significativo — la fila no es válida hasta
            // que se complete recipient y se re-emita. Por unicidad usamos
            // un código derivado del order_id (uuid hex, sin guiones) + 40
            // bytes aleatorios que jamás colisionará con un CUFE/CUDE real
            // (96 chars hex).
            'unique_code' => substr(str_replace('-', '', (string) $order->getKey()).bin2hex(random_bytes(40)), 0, 96),
            'unique_code_type' => $uniqueCodeType,
            'issued_at' => now(),
            'status' => 'needs_recipient_data',
            'provider_slug' => $providerConfig->provider_slug,
            'dian_environment_code' => $providerConfig->environment,
        ]);
    }
}
