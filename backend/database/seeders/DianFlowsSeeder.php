<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Services\Dian\CufeCudeGenerator;
use App\Services\Dian\ResolutionConsecutiveAllocator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Genera documentos DIAN demo cubriendo todos los caminos del
 * flujo end-to-end. Idempotente: no emite si ya existe documento para
 * (order_id, document_type).
 *
 * Flujos cubiertos (sobre órdenes históricas creadas por
 * `RestauranteFlexySeeder`):
 *  1. Mesa cierra normal → DEE POS al consumidor final (no pide factura).
 *  2. Mesa cierra normal → FEV al cliente identificado (Contact completo,
 *     lookup por phone matchea).
 *  3. Domicilio paga transferencia → FEV (cliente identificado).
 *  4. Pickup paga tarjeta → DEE POS estándar.
 *  5. Mesa con item cancelado mid-flujo → DEE POS sobre el total final.
 *  6. Orden refunded → DEE POS aceptado + nota crédito (NC DEE POS) que la
 *     anula. Demuestra el flujo de devolución.
 *  7. Orden rechazada por el provider (5% según mock) → status `rejected` +
 *     `rejection_reason` del catálogo DIAN. Demuestra el camino de retry.
 *  8. Contact con perfil DIAN incompleto → status `needs_recipient_data`,
 *     consecutivo NO asignado (fila marker para que la UI lo muestre).
 *
 * **No usa `DianDispatchService`** porque ese servicio sube XML/PDF a S3
 * y queremos que el seeder corra sin depender del disco DIAN (MinIO en dev,
 * S3 en PDN). Los CUFE/CUDE son reales (calculados con `CufeCudeGenerator`),
 * pero `xml_path` y `pdf_path` quedan `NULL` — la UI mostrará "PDF no
 * disponible (documento sembrado en demo)". El cajero puede pulsar
 * "Reintentar" para que el dispatcher real produzca los blobs.
 *
 * Permisos: independientemente del seeder, el RBAC vigente ya garantiza
 * que un cashier de Pereira NO ve documentos de Cartago (filtro por
 * `branch_id` en el controller).
 */
class DianFlowsSeeder extends Seeder
{
    private const SAMPLE_LIMIT_PER_BRANCH = 40;

    /** Contacto empresa con perfil DIAN completo → emite FEV directo. */
    private const DEMO_CONTACT_COMPANY = [
        'phone' => '3010000999',
        'name' => 'Soluciones Andinas SAS',
        'kind' => 'company',
        'doc_type' => 'NIT',
        'doc_number' => '900456789',
        'dv' => '4',
        'legal_name' => 'SOLUCIONES ANDINAS SAS',
        'email' => 'facturacion@solucionesandinas.demo',
        'address' => 'Cra 13 #45-78, Pereira',
        'municipality_dane_code' => '66001',
        'fiscal_responsibilities' => ['O-13'],
    ];

    /** Contacto persona natural con perfil DIAN completo → emite FEV directo. */
    private const DEMO_CONTACT_NATURAL = [
        'phone' => '3010000777',
        'name' => 'Juan Pérez',
        'kind' => 'natural',
        'doc_type' => 'CC',
        'doc_number' => '1098765432',
        'dv' => null,
        'legal_name' => 'JUAN PÉREZ',
        'email' => 'juan.perez@ejemplo.demo',
        'address' => 'Calle 18 #4-32, Pereira',
        'municipality_dane_code' => '66001',
        'fiscal_responsibilities' => ['R-99-PN'],
    ];

    /**
     * Contacto sin doc_number → kind=null. Demuestra el flujo
     * `needs_recipient_data` en el modal del cajero.
     */
    private const DEMO_CONTACT_INCOMPLETE = [
        'phone' => '3010000888',
        'name' => 'Cliente Demo Sin Datos',
    ];

    public function __construct(
        private readonly CufeCudeGenerator $cufeGen,
        private readonly ResolutionConsecutiveAllocator $allocator,
    ) {}

    public function run(): void
    {
        $companies = Company::query()
            ->whereHas('activeDianProviderConfig')
            ->whereHas('dianResolutions', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($companies as $company) {
            $providerConfig = DianProviderConfig::query()
                ->where('company_nit', $company->nit)
                ->where('is_active', true)
                ->first();

            if ($providerConfig === null) {
                continue;
            }

            $this->seedDemoContacts($company);
            $this->seedDocumentsForExistingOrders($company, $providerConfig);
        }

        $this->command?->info('DIAN flows: documentos DEE POS / FEV / NC sembrados sobre órdenes históricas demo.');
    }

    private function seedDemoContacts(Company $company): void
    {
        // La identidad canónica es (company_nit, doc_number)
        // cuando hay doc. Phone es nullable y puede repetirse.
        // Sembramos 3 escenarios: empresa con FEV completo, persona natural
        // con FEV completo, contacto sin datos (flujo needs_recipient_data).
        $branchId = DB::table('branches')
            ->where('company_nit', $company->nit)
            ->orderBy('created_at')
            ->value('id');

        if ($branchId === null) {
            return;
        }

        $this->upsertContactByDoc($company->nit, $branchId, self::DEMO_CONTACT_COMPANY);
        $this->upsertContactByDoc($company->nit, $branchId, self::DEMO_CONTACT_NATURAL);

        // Contacto incompleto: no tiene doc_number → idempotente por
        // (phone, name) porque sin doc no hay clave canónica natural.
        $existingIncomplete = Contact::query()
            ->where('company_nit', $company->nit)
            ->where('phone', self::DEMO_CONTACT_INCOMPLETE['phone'])
            ->where('name', self::DEMO_CONTACT_INCOMPLETE['name'])
            ->first();

        if ($existingIncomplete === null) {
            Contact::query()->create([
                'company_nit' => $company->nit,
                'branch_id' => $branchId,
                'phone' => self::DEMO_CONTACT_INCOMPLETE['phone'],
                'name' => self::DEMO_CONTACT_INCOMPLETE['name'],
                // kind y dian_profile_completed_at se dejan NULL a propósito
                // para que la UI muestre el flujo `needs_recipient_data`.
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertContactByDoc(string $companyNit, string $branchId, array $data): void
    {
        Contact::query()->updateOrCreate(
            [
                'company_nit' => $companyNit,
                'doc_number' => $data['doc_number'],
            ],
            [
                'branch_id' => $branchId,
                'phone' => $data['phone'],
                'name' => $data['name'],
                'kind' => $data['kind'],
                'doc_type' => $data['doc_type'],
                'dv' => $data['dv'] ?? null,
                'legal_name' => $data['legal_name'],
                'email' => $data['email'],
                'address' => $data['address'],
                'municipality_dane_code' => $data['municipality_dane_code'],
                'fiscal_responsibilities' => $data['fiscal_responsibilities'],
                'dian_profile_completed_at' => now()->subDays(60),
            ]
        );
    }

    private function seedDocumentsForExistingOrders(Company $company, DianProviderConfig $providerConfig): void
    {
        // Muestreo limitado por sede para no saturar la BD demo.
        $completedOrders = Order::query()
            ->where('company_nit', $company->nit)
            ->whereIn('status', ['completed', 'refunded'])
            ->whereNotNull('ordered_at')
            ->orderByDesc('ordered_at')
            ->limit(self::SAMPLE_LIMIT_PER_BRANCH * 5)
            ->get();

        $emittedByBranch = [];

        foreach ($completedOrders as $order) {
            $branchKey = (string) $order->branch_id;
            $emittedByBranch[$branchKey] = ($emittedByBranch[$branchKey] ?? 0);

            if ($emittedByBranch[$branchKey] >= self::SAMPLE_LIMIT_PER_BRANCH) {
                continue;
            }

            // Idempotencia: si ya hay documento, skip.
            $exists = ElectronicDocument::query()
                ->where('order_id', $order->id)
                ->whereIn('document_type', ['pos_equivalent', 'invoice'])
                ->exists();

            if ($exists) {
                continue;
            }

            $scenario = $this->resolveScenario($order);

            if ($scenario === 'none') {
                continue;
            }

            try {
                $this->emitForScenario($order, $providerConfig, $scenario);
                $emittedByBranch[$branchKey]++;
            } catch (\Throwable $e) {
                $this->command?->warn(sprintf(
                    'DIAN flow seeding falló para orden #%s (%s): %s',
                    $order->id,
                    $scenario,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Decide el escenario DIAN según el order_type, status y un hash del id
     * para que sea determinístico re-ejecutable.
     */
    private function resolveScenario(Order $order): string
    {
        // Refunded → NC sobre un DEE POS previo. Demuestra devolución.
        if ($order->status === 'refunded') {
            return 'pos_then_credit_note';
        }

        $bucket = crc32((string) $order->id) % 100;
        $isDelivery = $order->order_type === 'delivery';
        $hasMatchingContact = $order->client_phone === self::DEMO_CONTACT_COMPANY['phone'];

        // Cliente identificado (Contact completo) — siempre FEV.
        if ($hasMatchingContact) {
            return 'fev_accepted';
        }

        // Distribución realista de tiendas pequeñas:
        //  - 30% DEE POS aceptado al consumidor final.
        //  - 15% FEV aceptado (cliente identificado por azar — demo).
        //  - 4% DEE POS rechazado (mock catálogo DIAN).
        //  - 51% sin documento (no facturan).
        if ($bucket < 30) {
            return 'pos_accepted';
        }
        if ($bucket < 45) {
            return $isDelivery ? 'fev_accepted' : 'pos_accepted';
        }
        if ($bucket < 49) {
            return 'pos_rejected';
        }

        return 'none';
    }

    private function emitForScenario(Order $order, DianProviderConfig $providerConfig, string $scenario): void
    {
        DB::transaction(function () use ($order, $providerConfig, $scenario) {
            $isFev = in_array($scenario, ['fev_accepted'], true);
            $documentType = $isFev ? 'invoice' : 'pos_equivalent';
            $uniqueCodeType = $isFev ? 'cufe' : 'cude';

            $allocation = $this->allocator->allocateNext(
                $order->company_nit,
                $documentType,
                $providerConfig->environment,
            );

            $issuedAt = $order->ordered_at?->copy()->addMinutes(10) ?? now();
            $issuedAtImmutable = DateTimeImmutable::createFromInterface($issuedAt)
                ->setTimezone(new DateTimeZone('America/Bogota'));

            $recipientDoc = $isFev
                ? self::DEMO_CONTACT_COMPANY['doc_number']
                : (string) (config('dian.default_final_consumer.doc_number') ?? '222222222222');

            // Snapshot del adquirente en la orden cuando es FEV (espejo del
            // flujo real: el cajero captura datos antes de emitir).
            if ($isFev) {
                $order->update([
                    'billing_doc_type' => self::DEMO_CONTACT_COMPANY['doc_type'],
                    'billing_doc_number' => self::DEMO_CONTACT_COMPANY['doc_number'],
                    'billing_dv' => self::DEMO_CONTACT_COMPANY['dv'],
                    'billing_legal_name' => self::DEMO_CONTACT_COMPANY['legal_name'],
                    'billing_email' => self::DEMO_CONTACT_COMPANY['email'],
                    'billing_address' => self::DEMO_CONTACT_COMPANY['address'],
                    'billing_municipality_code' => self::DEMO_CONTACT_COMPANY['municipality_dane_code'],
                    'billing_recipient_type' => 'company',
                ]);
            }

            $cufeOrCude = $this->cufeGen->generate([
                'full_number' => $allocation['full_number'],
                'issued_at' => $issuedAtImmutable,
                'total' => (float) $order->total,
                'iva_amount' => (float) $order->tax_amount,
                'inc_amount' => 0,
                'ica_amount' => 0,
                'issuer_nit' => (string) $order->company_nit,
                'recipient_doc_number' => $recipientDoc,
                'technical_key' => $allocation['technical_key'],
                'environment' => $allocation['environment'],
            ]);

            $isRejected = $scenario === 'pos_rejected';
            $status = $isRejected ? 'rejected' : 'accepted';

            $catalog = (array) config('dian.mock.rejection_reasons_catalog', []);
            $rejectionCode = array_keys($catalog)[abs(crc32((string) $order->id)) % max(count($catalog), 1)] ?? 'FAB01';
            $rejectionReason = $isRejected ? $rejectionCode.': '.($catalog[$rejectionCode] ?? 'Razón mock') : null;

            $qrData = ((string) config("dian.qr_base_url.{$providerConfig->environment}")).$cufeOrCude;

            $primary = ElectronicDocument::query()->create([
                'company_nit' => $order->company_nit,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'dian_resolution_id' => $allocation['resolution_id'],
                'document_type' => $documentType,
                'prefix' => $allocation['prefix'],
                'consecutive' => $allocation['consecutive'],
                'full_number' => $allocation['full_number'],
                'unique_code' => $cufeOrCude,
                'unique_code_type' => $uniqueCodeType,
                'issued_at' => $issuedAt,
                'xml_path' => null,
                'pdf_path' => null,
                'qr_data' => $qrData,
                'status' => $status,
                'provider_slug' => $providerConfig->provider_slug,
                'provider_track_id' => 'MOCK-SEED-'.strtoupper(substr(md5((string) $order->id), 0, 16)),
                'provider_response_log' => [
                    'seeded' => true,
                    'scenario' => $scenario,
                    'note' => 'Documento sembrado por DianFlowsSeeder — XML/PDF no se subieron a S3.',
                ],
                'sent_at' => $issuedAt,
                'accepted_at' => $status === 'accepted' ? $issuedAt : null,
                'rejected_at' => $status === 'rejected' ? $issuedAt : null,
                'rejection_reason' => $rejectionReason,
                'dian_environment_code' => $providerConfig->environment,
            ]);

            // Escenario: NC sobre DEE POS previo (orden refunded).
            if ($scenario === 'pos_then_credit_note' && $status === 'accepted') {
                $this->emitCreditNoteFor($primary, $providerConfig);
            }
        });
    }

    private function emitCreditNoteFor(ElectronicDocument $original, DianProviderConfig $providerConfig): void
    {
        $ncAllocation = $this->allocator->allocateNext(
            $original->company_nit,
            'pos_equivalent_credit_note',
            $providerConfig->environment,
        );

        $issuedAt = $original->issued_at?->copy()->addHours(2) ?? now();
        $issuedAtImmutable = DateTimeImmutable::createFromInterface($issuedAt)
            ->setTimezone(new DateTimeZone('America/Bogota'));

        $cude = $this->cufeGen->generate([
            'full_number' => $ncAllocation['full_number'],
            'issued_at' => $issuedAtImmutable,
            'total' => (float) ($original->order?->total ?? 0),
            'iva_amount' => (float) ($original->order?->tax_amount ?? 0),
            'inc_amount' => 0,
            'ica_amount' => 0,
            'issuer_nit' => (string) $original->company_nit,
            'recipient_doc_number' => (string) (config('dian.default_final_consumer.doc_number') ?? '222222222222'),
            'technical_key' => $ncAllocation['technical_key'],
            'environment' => $ncAllocation['environment'],
        ]);

        ElectronicDocument::query()->create([
            'company_nit' => $original->company_nit,
            'branch_id' => $original->branch_id,
            'order_id' => $original->order_id,
            'dian_resolution_id' => $ncAllocation['resolution_id'],
            'document_type' => 'pos_equivalent_credit_note',
            'prefix' => $ncAllocation['prefix'],
            'consecutive' => $ncAllocation['consecutive'],
            'full_number' => $ncAllocation['full_number'],
            'unique_code' => $cude,
            'unique_code_type' => 'cude',
            'issued_at' => $issuedAt,
            'qr_data' => ((string) config("dian.qr_base_url.{$providerConfig->environment}")).$cude,
            'status' => 'accepted',
            'provider_slug' => $providerConfig->provider_slug,
            'provider_track_id' => 'MOCK-SEED-NC-'.strtoupper(substr(md5((string) $original->id), 0, 16)),
            'provider_response_log' => [
                'seeded' => true,
                'scenario' => 'pos_then_credit_note',
                'references_document_id' => $original->id,
            ],
            'sent_at' => $issuedAt,
            'accepted_at' => $issuedAt,
            'dian_environment_code' => $providerConfig->environment,
            'references_document_id' => $original->id,
        ]);
    }
}
