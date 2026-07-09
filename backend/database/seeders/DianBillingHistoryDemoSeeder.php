<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\Dian\CufeCudeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SOLO QA — siembra historia real de facturación electrónica DIAN sobre
 * SuperPapas (NIT `1`, la empresa demo de `RestauranteFlexySeeder`), para
 * probar el cargo por uso (#facturación-dian): conteo por resolución, panel
 * `company/settings → Facturación`, y el link "Ver orden" en el detalle de
 * cada documento (`/company/dian` → Facturas).
 *
 * A diferencia de una primera versión de este seeder (que creaba una empresa
 * sintética separada), esta genera un `ElectronicDocument` por cada orden
 * `completed` REAL de SuperPapas — así `order_id` siempre apunta a una orden
 * existente y el link "Ver orden" del frontend (ya implementado en
 * `documents-explorer.tsx`) funciona para todos los documentos sembrados. La
 * fecha de emisión sale de `order.ordered_at`, no de un calendario sintético
 * — el rango real de meses lo determina el histórico de órdenes de
 * `RestauranteFlexySeeder` (`HISTORY_DAYS`), no un valor fijo acá.
 *
 * SuperPapas está en Plan Básico ($0, sin módulo DIAN) desde el split de
 * planes 2026-07 — este seeder la pasa a Plan Plus (cancela la subscription
 * activa + crea una nueva, mismo patrón que `billing:change-plan`) para que
 * el panel de uso DIAN y el cargo por factura sean visibles.
 *
 * NUNCA corre en `production` — aborta con guard explícito (igual que
 * `PastDueDemoCompanySeeder`, `MetaPlatformCredentialsSeeder`). No está
 * encadenado en `QaSeeder`: se invoca a mano cuando se necesita este dataset.
 *
 *   php artisan db:seed --class=DianBillingHistoryDemoSeeder --force
 *
 * Idempotente:
 *  - Subscription Plan Plus: no-op si ya está en ese plan.
 *  - `electronic_documents`: marcador propio (`provider_track_id LIKE
 *    'DEMOHIST-%'`) — si ya se sembró, no vuelve a generar (evita duplicar
 *    documentos sobre las mismas órdenes en cada re-run). Además excluye
 *    órdenes que YA tengan cualquier documento vinculado (`order_id` no
 *    nulo), sea de este seeder o de `DianFlowsSeeder`.
 *  - Invoices mensuales: solo genera para meses SIN invoice previo de
 *    NINGUNA subscription — SuperPapas ya tiene invoices legacy (marzo-mayo,
 *    plan $100.000 pre-split) que este seeder NO toca ni duplica.
 */
class DianBillingHistoryDemoSeeder extends Seeder
{
    private const COMPANY_NIT = '1';

    /** Solo tipos con una orden real y `completed` detrás — garantiza order_id siempre poblado. */
    private const DOCUMENT_TYPE_MIX = [
        'pos_equivalent' => 0.85,
        'invoice' => 0.15,
    ];

    /** @var array<string, float> */
    private const STATUS_MIX = [
        'accepted' => 0.90,
        'sent' => 0.04,
        'queued' => 0.03,
        'rejected' => 0.02,
        'error' => 0.01,
    ];

    private const REJECTION_REASONS = [
        'FAJ24a' => 'NumFac duplicado',
        'FAB01' => 'Estructura UBL inválida',
        'FAB07' => 'CUFE/CUDE no coincide con campos canónicos',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DianBillingHistoryDemoSeeder: NUNCA corre en production. Abortando.');

            return;
        }

        $company = Company::query()->where('nit', self::COMPANY_NIT)->first();
        if ($company === null) {
            $this->command?->warn('DianBillingHistoryDemoSeeder: SuperPapas (NIT 1) no existe. Corré RestauranteFlexySeeder primero.');

            return;
        }

        $this->ensurePlusSubscription($company->nit);

        // Perfil fiscal + resoluciones (PO/FE/NCFE/NCPO) + MockDianProvider
        // ya existen para SuperPapas (QaSeeder corre DianDemoSeeder) — llamada
        // defensiva e idempotente por si se corre este seeder solo.
        $this->call(DianDemoSeeder::class);

        $created = $this->seedElectronicDocumentsFromRealOrders($company->nit);
        $generatedInvoices = $this->generateMissingInvoices($company->nit, app(BillingService::class));

        $this->command?->info(sprintf(
            'DianBillingHistoryDemoSeeder: SuperPapas (NIT %s) — %d documentos DIAN %s, %d invoice(s) mensuales nueva(s).',
            $company->nit,
            ElectronicDocument::where('company_nit', $company->nit)->whereNotNull('order_id')->count(),
            $created ? 'vinculados a órdenes reales en esta corrida' : 'ya existían (skip)',
            count($generatedInvoices),
        ));
    }

    /**
     * Cancela la subscription activa (si no es ya Plan Plus) y crea una
     * nueva en Plan Plus — mismo patrón que `ChangeCompanyPlanCommand`
     * (`billing:change-plan`), reimplementado acá porque ese comando no es
     * llamable como servicio.
     */
    private function ensurePlusSubscription(string $companyNit): Subscription
    {
        $plusPlan = BillingPlan::where('slug', 'plus')->firstOrFail();

        $current = Subscription::query()->where('company_nit', $companyNit)->where('status', 'active')->first();

        if ($current !== null && $current->billing_plan_id === $plusPlan->id) {
            return $current;
        }

        return DB::transaction(function () use ($companyNit, $plusPlan, $current) {
            if ($current !== null) {
                $current->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'ends_at' => now()->toDateString(),
                ])->save();
            }

            return Subscription::create([
                'company_nit' => $companyNit,
                'billing_plan_id' => $plusPlan->id,
                'status' => 'active',
                'starts_at' => now()->toDateString(),
                'plan_name_snapshot' => $plusPlan->name,
                'plan_price_snapshot' => $plusPlan->price,
                'plan_features_snapshot' => $plusPlan->features,
                'plan_tax_regime_snapshot' => $plusPlan->tax_regime,
                'plan_tax_rate_snapshot' => $plusPlan->tax_rate,
                'plan_snapshot_at' => now(),
            ]);
        });
    }

    /**
     * Genera un `ElectronicDocument` por cada orden `completed` de SuperPapas
     * que todavía no tenga ninguno vinculado. `issued_at` sale de
     * `order.ordered_at` — el documento nace "poco después" de que la orden
     * cerró, como en la vida real.
     *
     * ponytail: no arma la cadena nota-crédito→documento-original (como sí
     * hace `DianFlowsSeeder` para su muestra curada) — acá el objetivo es
     * volumen realista para el cargo por uso, no cubrir cada camino DIAN.
     *
     * @return bool true si sembró (false si ya existía y se saltó).
     */
    private function seedElectronicDocumentsFromRealOrders(string $companyNit): bool
    {
        if (ElectronicDocument::where('company_nit', $companyNit)->where('provider_track_id', 'like', 'DEMOHIST-%')->exists()) {
            $this->command?->info('DianBillingHistoryDemoSeeder: ya hay documentos DEMOHIST para SuperPapas — skip (idempotente).');

            return false;
        }

        $resolutions = DianResolution::query()
            ->where('company_nit', $companyNit)
            ->where('is_active', true)
            ->get()
            ->keyBy('document_type');

        if ($resolutions->isEmpty()) {
            $this->command?->warn('DianBillingHistoryDemoSeeder: sin resoluciones DIAN activas — corré DianDemoSeeder primero.');

            return false;
        }

        $alreadyLinkedOrderIds = ElectronicDocument::where('company_nit', $companyNit)
            ->whereNotNull('order_id')
            ->pluck('order_id');

        $orders = Order::query()
            ->where('company_nit', $companyNit)
            ->where('status', 'completed')
            ->whereNotNull('ordered_at')
            ->whereNotIn('id', $alreadyLinkedOrderIds)
            ->orderBy('ordered_at')
            ->get(['id', 'branch_id', 'ordered_at']);

        if ($orders->isEmpty()) {
            $this->command?->warn('DianBillingHistoryDemoSeeder: sin órdenes completed disponibles para vincular.');

            return false;
        }

        $counters = $resolutions->map(fn (DianResolution $r) => $r->current_number)->all();
        $cufeGen = app(CufeCudeGenerator::class);

        $rows = [];
        foreach ($orders as $order) {
            $documentType = $this->weightedPick(self::DOCUMENT_TYPE_MIX);
            $resolution = $resolutions->get($documentType);
            if ($resolution === null) {
                continue;
            }

            $counters[$documentType]++;
            $consecutive = $counters[$documentType];
            $fullNumber = $resolution->prefix.$consecutive;

            // Emitido poco después de que la orden cierra — no instantáneo.
            $issuedAt = Carbon::parse($order->ordered_at)->addMinutes(random_int(1, 20));

            $status = $this->weightedPick(self::STATUS_MIX);
            $isFev = $documentType === 'invoice';
            $recipientDoc = $isFev ? '900456789' : '222222222222';
            $total = random_int(15000, 185000);
            $ivaAmount = $isFev ? round($total - ($total / 1.19), 2) : 0.0;

            $cufe = $cufeGen->generate([
                'full_number' => $fullNumber,
                'issued_at' => $issuedAt,
                'total' => $total,
                'iva_amount' => $ivaAmount,
                'issuer_nit' => $companyNit,
                'recipient_doc_number' => $recipientDoc,
                'technical_key' => $resolution->technical_key,
                'environment' => 'habilitacion',
            ]);

            $accepted = $status === 'accepted' || $status === 'rejected';
            $rejectionCode = $status === 'rejected' ? array_rand(self::REJECTION_REASONS) : null;

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'company_nit' => $companyNit,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'dian_resolution_id' => $resolution->id,
                'document_type' => $documentType,
                'prefix' => $resolution->prefix,
                'consecutive' => $consecutive,
                'full_number' => $fullNumber,
                'unique_code' => $cufe,
                'unique_code_type' => $isFev ? 'cufe' : 'cude',
                'issued_at' => $issuedAt,
                'xml_path' => null,
                'pdf_path' => null,
                'qr_data' => null,
                'status' => $status,
                'provider_slug' => 'mock',
                'provider_track_id' => 'DEMOHIST-'.Str::ulid(),
                'provider_response_log' => json_encode(['note' => 'DianBillingHistoryDemoSeeder — documento sembrado sobre orden real, sin XML/PDF real.']),
                'sent_at' => $issuedAt,
                'accepted_at' => $status === 'accepted' ? $issuedAt->copy()->addSeconds(random_int(2, 40)) : null,
                'rejected_at' => $status === 'rejected' ? $issuedAt->copy()->addSeconds(random_int(2, 40)) : null,
                'rejection_reason' => $rejectionCode !== null ? "{$rejectionCode}: ".self::REJECTION_REASONS[$rejectionCode] : null,
                'retry_count' => $status === 'error' ? random_int(1, 3) : 0,
                'last_retry_at' => null,
                'dian_environment_code' => 'habilitacion',
                'references_document_id' => null,
                'created_at' => $issuedAt,
                'updated_at' => $accepted ? $issuedAt->copy()->addSeconds(40) : $issuedAt,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('electronic_documents')->insert($chunk);
        }

        foreach ($counters as $documentType => $finalNumber) {
            $resolutions[$documentType]->update(['current_number' => $finalNumber]);
        }

        $this->command?->info(sprintf('DianBillingHistoryDemoSeeder: %d documentos DIAN sembrados sobre órdenes reales.', count($rows)));

        return true;
    }

    /**
     * @param  array<string, float>  $weights
     */
    private function weightedPick(array $weights): string
    {
        $roll = random_int(0, 99999) / 100000;
        $cumulative = 0.0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * Genera invoices mensuales SOLO para meses cerrados que todavía no
     * tengan NINGÚN invoice (de cualquier subscription) — SuperPapas ya
     * tiene invoices legacy (marzo-mayo, plan pre-split $100.000) que no se
     * tocan. En la práctica esto termina generando el mes cerrado más
     * reciente con la subscription Plan Plus nueva.
     *
     * @return list<int>
     */
    private function generateMissingInvoices(string $companyNit, BillingService $service): array
    {
        $created = [];

        for ($monthsAgo = 3; $monthsAgo >= 1; $monthsAgo--) {
            $forMonth = Carbon::now()->subMonthsNoOverflow($monthsAgo);
            $periodFrom = $forMonth->copy()->startOfMonth()->toDateString();

            $alreadyBilled = DB::table('invoices')
                ->where('company_nit', $companyNit)
                ->where('period_from', $periodFrom)
                ->where('status', '!=', 'voided')
                ->exists();

            if ($alreadyBilled) {
                continue;
            }

            $created = array_merge($created, $service->generateMonthlyInvoices($forMonth));
        }

        return $created;
    }
}
