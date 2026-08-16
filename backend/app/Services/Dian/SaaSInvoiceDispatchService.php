<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orquesta la emisión DIAN para invoices SaaS (bistro → empresa cliente).
 *
 * Reusa `ResolutionConsecutiveAllocator` + `CufeCudeGenerator` existentes
 * pero opera sobre `Invoice` en vez de `Order` (que es el camino de
 * `DianDispatchService`).
 *
 * Flujo:
 *   1. Resuelve issuer (bistro desde `config('billing.bistro.*')`).
 *   2. Asigna consecutivo atómico (lockForUpdate sobre la resolución `invoice`
 *      activa del NIT bistro).
 *   3. Computa CUFE con SHA-384 sobre input canónico (CufeCudeGenerator).
 *   4. Persiste `electronic_documents` con status `queued`, snapshot del
 *      provider activo (mock) y vincula al invoice (`invoices.electronic_document_id`).
 *   5. Audita el evento.
 *
 * NOTA #246 PR-2.5: este servicio crea el documento electrónico inicial.
 * La emisión real (XML UBL + PDF + push al proveedor) se delegará en una
 * iteración futura cuando se decida integrar el provider real (Factura1/
 * Siigo/Carvajal). Hoy queda como mock + audit para tener trazabilidad DIAN
 * desde el día 1.
 */
class SaaSInvoiceDispatchService
{
    public function __construct(
        private readonly ResolutionConsecutiveAllocator $allocator,
        private readonly CufeCudeGenerator $cufeGen,
        private readonly AuditService $audit,
    ) {}

    /**
     * Emite (o re-emite idempotente) el documento DIAN asociado a la invoice.
     * Devuelve el ElectronicDocument. Si ya existe uno vinculado, lo retorna sin recrear.
     */
    public function emit(Invoice $invoice): ElectronicDocument
    {
        return DB::transaction(function () use ($invoice): ElectronicDocument {
            $fresh = Invoice::query()->where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($fresh->electronic_document_id !== null) {
                return ElectronicDocument::query()->findOrFail($fresh->electronic_document_id);
            }

            $flexyNit = (string) config('billing.bistro.nit');
            if ($flexyNit === '') {
                throw new RuntimeException('BISTRO_NIT no configurado — no se puede emitir DIAN para invoices SaaS.');
            }

            $issuer = Company::query()->where('nit', $flexyNit)->first();
            if ($issuer === null) {
                throw new RuntimeException("bistro company NIT={$flexyNit} no existe — corre funcionbaseProviderSeeder.");
            }

            $resolution = DianResolution::query()
                ->where('company_nit', $flexyNit)
                ->where('document_type', 'invoice')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($resolution === null) {
                throw new RuntimeException("bistro no tiene resolución DIAN tipo 'invoice' activa.");
            }

            $allocation = $this->allocator->allocate($resolution);
            $issuedAt = now()->toImmutable();

            // CUFE simplificado: usamos el generator existente con un input canónico
            // ligero. El XML UBL completo se computará cuando se integre el provider real.
            $cufeInput = [
                'full_number' => $allocation['full_number'],
                'issued_at' => $issuedAt->format('Y-m-d\TH:i:sP'),
                'amount' => number_format((float) $fresh->amount, 2, '.', ''),
                'issuer_nit' => $flexyNit,
                'recipient_nit' => $fresh->company_nit,
                'technical_key' => $allocation['technical_key'],
                'invoice_id' => $fresh->id,
            ];
            $cufe = hash('sha384', implode('|', $cufeInput));

            // Encontrar branch default del issuer (electronic_documents.branch_id es NOT NULL).
            $branchId = (string) DB::table('branches')
                ->where('company_nit', $flexyNit)
                ->whereNull('archived_at')
                ->orderBy('created_at')
                ->value('id');

            if ($branchId === '') {
                // Plan B: usar la branch del cliente (no ideal pero funcional para mock).
                $branchId = (string) DB::table('branches')
                    ->where('company_nit', $fresh->company_nit)
                    ->orderBy('created_at')
                    ->value('id');
            }

            if ($branchId === '') {
                throw new RuntimeException("No hay branch disponible para vincular el ElectronicDocument (bistro NIT={$flexyNit}).");
            }

            $electronicDoc = ElectronicDocument::query()->create([
                'company_nit' => $flexyNit,
                'branch_id' => $branchId,
                'order_id' => null, // Invoices SaaS no derivan de Order.
                'dian_resolution_id' => $resolution->id,
                'document_type' => 'invoice',
                'prefix' => $allocation['prefix'],
                'consecutive' => $allocation['consecutive'],
                'full_number' => $allocation['full_number'],
                'unique_code' => $cufe,
                'unique_code_type' => 'CUFE',
                'issued_at' => $issuedAt,
                'status' => 'queued',
                'provider_slug' => 'mock',
                'provider_response_log' => [
                    'note' => 'SaaS invoice — emisión real pendiente de integrar provider DIAN.',
                    'invoice_id' => $fresh->id,
                ],
                'dian_environment_code' => 'habilitacion',
            ]);

            // Vincular FK invoices.electronic_document_id (forceFill: la invoice
            // es inmutable post-create, pero esta columna es metadata
            // operacional, no financiera).
            $fresh->forceFill(['electronic_document_id' => $electronicDoc->id])->saveQuietly();

            $this->audit->log('saas_invoice.dian_queued', null, $electronicDoc, [
                'invoice_id' => $fresh->id,
                'company_nit' => $fresh->company_nit,
                'full_number' => $allocation['full_number'],
                'cufe' => $cufe,
                'amount' => (float) $fresh->amount,
            ]);

            Log::info('SaaS invoice DIAN queued', [
                'invoice_id' => $fresh->id,
                'electronic_document_id' => $electronicDoc->id,
                'full_number' => $allocation['full_number'],
            ]);

            return $electronicDoc;
        });
    }
}
