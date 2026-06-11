<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * #257 — Presenta el plan de una suscripcion en lenguaje amigable para
 * correos transaccionales billing (CompanyRegistrationApproved, InvoiceGenerated).
 *
 * Fuente de datos (orden de prioridad):
 *   1. Subscription snapshots (plan_name_snapshot, plan_price_snapshot,
 *      plan_features_snapshot, plan_tax_regime_snapshot, plan_tax_rate_snapshot).
 *      Inmutables — alineado §13 CLAUDE.md.
 *   2. BillingPlan vivo via $subscription->plan, SOLO para campos NO snapshotados:
 *      description, currency, billing_cycle, price_includes_tax.
 *   3. Fallback legacy: si Subscription no tiene snapshots (sub viejas), cae
 *      a BillingPlan vivo y loggea billing.subscription_snapshot_missing.
 *
 * Labels de features y regimenes tributarios viven en config/billing_plan_features.php.
 * Slugs sin label registrado se omiten silenciosamente (NO se muestra el slug crudo)
 * y se loggea billing.plan_feature_label_missing para detectar drift.
 */
class BillingPlanPresenter
{
    /**
     * @return array{
     *   name: string,
     *   description: ?string,
     *   price_formatted: string,
     *   currency: string,
     *   cycle_label: string,
     *   features: list<string>,
     *   tax_notice: ?string
     * }
     */
    public static function forSubscription(Subscription $sub): array
    {
        $sub->loadMissing('plan');
        $plan = $sub->plan;

        $name = (string) ($sub->plan_name_snapshot ?? $plan?->name ?? 'Plan');
        $price = (float) ($sub->plan_price_snapshot ?? $plan?->price ?? 0);
        $taxRegime = (string) ($sub->plan_tax_regime_snapshot ?? $plan?->tax_regime ?? 'iva_19');
        $taxRate = (float) ($sub->plan_tax_rate_snapshot ?? $plan?->tax_rate ?? 19.00);
        $featuresRaw = (array) ($sub->plan_features_snapshot ?? $plan?->features ?? []);

        // Snapshot ausente — marker para detectar drift en suscripciones viejas pre-#246.
        if ($sub->plan_name_snapshot === null) {
            Log::warning('billing.subscription_snapshot_missing', [
                'subscription_id' => $sub->id,
                'company_nit' => $sub->company_nit,
            ]);
        }

        $currency = (string) ($plan?->currency ?? 'COP');
        $billingCycle = (string) ($plan?->billing_cycle ?? 'monthly');
        $priceIncludesTax = (bool) ($plan?->price_includes_tax ?? true);
        $description = $plan?->description;

        $priceFormatted = '$'.number_format($price, 0, ',', '.').' '.$currency;

        $cycleLabels = (array) config('billing_plan_features.billing_cycle_labels', []);
        $cycleLabel = (string) ($cycleLabels[$billingCycle] ?? 'cada mes');

        return [
            'name' => $name,
            'description' => $description !== null && $description !== '' ? $description : null,
            'price_formatted' => $priceFormatted,
            'currency' => $currency,
            'cycle_label' => $cycleLabel,
            'features' => self::resolveFeatureLabels($featuresRaw),
            'tax_notice' => self::resolveTaxNotice($priceIncludesTax, $taxRegime, $taxRate),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $featuresRaw
     * @return list<string>
     */
    private static function resolveFeatureLabels(array $featuresRaw): array
    {
        $labels = (array) config('billing_plan_features.features', []);
        $resolved = [];

        foreach ($featuresRaw as $entry) {
            $slug = is_string($entry) ? $entry : (string) ($entry['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (! array_key_exists($slug, $labels)) {
                Log::warning('billing.plan_feature_label_missing', ['slug' => $slug]);

                continue;
            }

            $resolved[] = (string) $labels[$slug];
        }

        return $resolved;
    }

    private static function resolveTaxNotice(bool $priceIncludesTax, string $taxRegime, float $taxRate): ?string
    {
        if ($priceIncludesTax) {
            return 'Precio con impuestos incluidos.';
        }

        $labels = (array) config('billing_plan_features.tax_regime_labels', []);
        if (array_key_exists($taxRegime, $labels)) {
            return '+ '.$labels[$taxRegime];
        }

        // Fallback generico si el regimen no esta mapeado: usar tax_rate cruda.
        if ($taxRate > 0) {
            return '+ '.rtrim(rtrim(number_format($taxRate, 2, ',', '.'), '0'), ',').'% de impuestos';
        }

        return null;
    }
}
