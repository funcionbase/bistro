<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lee y escribe configuración clave-valor de empresa con caché por NIT.
 *
 * Solo se permiten las claves definidas en ALLOWED_KEYS (validadas en el FormRequest correspondiente).
 * Los valores se almacenan tipados (string/bool/int/array) usando CompanySetting::castToString().
 * Los valores por defecto provienen de config/company_defaults.php; la BD los sobreescribe.
 * Cache key: company_settings_{nit}; TTL configurable en config company_settings.cache_ttl (default: 3600s).
 * seedDefaults() se llama al crear una empresa nueva para inicializar los valores predeterminados.
 */
class CompanySettingsService
{
    public const ALLOWED_KEYS = [
        'timezone',
        'currency',
        'currency_symbol',
        'language',
        'order_auto_confirm',
        'order_notify_customer_email',
        'bot_welcome_message',
        'bot_away_message',
        'delivery_area_km',
        'min_order_amount',
        'payment_methods',
        'payment_method_accounts',
        'menu_primary_color',
        'whatsapp_read_receipts',
        'printing.receipt_width',
        'printing.header_lines',
        'printing.footer_message',
        'printing.show_qr_menu',
        'printing.copies',
        'food_cost_alert_threshold',
        // Fidelización (loyalty config por empresa). LoyaltyService::award
        // lee estas claves al cerrar pago; si `loyalty.enabled` está en false
        // el award es no-op aunque haya celular registrado.
        'loyalty.enabled',
        'loyalty.points_per_cop',
    ];

    public function get(string $nit, string $key, mixed $default = null): mixed
    {
        $all = $this->all($nit);

        return $all[$key] ?? $default;
    }

    /**
     * Returns all settings merged with defaults (DB values override defaults).
     *
     * @return array<string, mixed>
     */
    public function all(string $nit): array
    {
        $cacheKey = $this->cacheKey($nit);
        $ttl = (int) config('company_settings.cache_ttl', 3600);
        $enabled = (bool) config('company_settings.cache_enabled', true);

        $loader = function () use ($nit): array {
            $defaults = $this->resolveDefaults();
            $rows = CompanySetting::forCompany($nit)->get();

            foreach ($rows as $row) {
                $defaults[$row->key] = $row->getCastedValue();
            }

            return $defaults;
        };

        if (! $enabled) {
            return $loader();
        }

        return Cache::remember($cacheKey, $ttl, $loader);
    }

    public function set(string $nit, string $key, mixed $value): void
    {
        $type = $this->typeFor($key);

        CompanySetting::updateOrCreate(
            ['company_nit' => $nit, 'key' => $key],
            ['value' => CompanySetting::castToString($value, $type), 'type' => $type],
        );

        $this->invalidateCache($nit);
    }

    /**
     * Persiste múltiples settings en una sola query usando `upsert`.
     *
     * Antes hacía `updateOrCreate` por cada setting (1 SELECT + 1 UPDATE/INSERT
     * cada uno = 2N queries para N settings). Ahora es 1 query total gracias
     * al UNIQUE(company_nit, key) ya existente en el schema.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setMany(string $nit, array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($settings as $key => $value) {
            $type = $this->typeFor($key);
            $rows[] = [
                'company_nit' => $nit,
                'key' => $key,
                'value' => CompanySetting::castToString($value, $type),
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CompanySetting::upsert(
            $rows,
            uniqueBy: ['company_nit', 'key'],
            update: ['value', 'type', 'updated_at'],
        );

        $this->invalidateCache($nit);
    }

    public function seedDefaults(string $nit): void
    {
        $defaults = config('company_defaults', []);

        $rows = array_map(fn (string $key, array $def) => [
            // PKs siempre UUID v7 (consistente con HasUuids trait).
            'id' => (string) Str::uuid7(),
            'company_nit' => $nit,
            'key' => $key,
            'value' => CompanySetting::castToString($def['value'], $def['type']),
            'type' => $def['type'],
            'created_at' => now(),
            'updated_at' => now(),
        ], array_keys($defaults), array_values($defaults));

        CompanySetting::insertOrIgnore($rows);
    }

    public function invalidateCache(string $nit): void
    {
        Cache::forget($this->cacheKey($nit));
    }

    private function cacheKey(string $nit): string
    {
        return "company_settings_{$nit}";
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaults(): array
    {
        $defaults = config('company_defaults', []);
        $result = [];

        foreach ($defaults as $key => $def) {
            $result[$key] = $def['value'];
        }

        return $result;
    }

    private function typeFor(string $key): string
    {
        $defaults = config('company_defaults', []);

        return $defaults[$key]['type'] ?? 'string';
    }
}
