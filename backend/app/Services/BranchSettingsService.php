<?php

namespace App\Services;

use App\Models\BranchSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lee y escribe configuración visual K/V por sede con caché por branch_id.
 *
 * Espejo de CompanySettingsService para personalización del menú público.
 * Cache key: branch_settings_{branch_id}; TTL = company_settings.cache_ttl.
 */
class BranchSettingsService
{
    public const ALLOWED_KEYS = [
        'menu_header_image_url',
        'menu_footer_image_url',
        'menu_tagline',
        'menu_card_style',
        'menu_show_branding',
    ];

    private const TYPE_MAP = [
        'menu_header_image_url' => 'string',
        'menu_footer_image_url' => 'string',
        'menu_tagline' => 'string',
        'menu_card_style' => 'string',
        'menu_show_branding' => 'boolean',
    ];

    public function get(string $branchId, string $key, mixed $default = null): mixed
    {
        return $this->all($branchId)[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $branchId): array
    {
        $cacheKey = $this->cacheKey($branchId);
        $ttl = (int) config('company_settings.cache_ttl', 3600);
        $enabled = (bool) config('company_settings.cache_enabled', true);

        $loader = function () use ($branchId): array {
            $defaults = $this->defaults();
            $rows = BranchSetting::forBranch($branchId)->get();

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

    public function set(string $companyNit, string $branchId, string $key, mixed $value): void
    {
        $type = self::TYPE_MAP[$key] ?? 'string';

        BranchSetting::updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            [
                'company_nit' => $companyNit,
                'value' => BranchSetting::castToString($value, $type),
                'type' => $type,
            ],
        );

        $this->invalidateCache($branchId);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setMany(string $companyNit, string $branchId, array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($settings as $key => $value) {
            $type = self::TYPE_MAP[$key] ?? 'string';
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'key' => $key,
                'value' => BranchSetting::castToString($value, $type),
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        BranchSetting::upsert(
            $rows,
            uniqueBy: ['branch_id', 'key'],
            update: ['company_nit', 'value', 'type', 'updated_at'],
        );

        $this->invalidateCache($branchId);
    }

    public function forget(string $branchId, string $key): void
    {
        BranchSetting::forBranch($branchId)->where('key', $key)->delete();
        $this->invalidateCache($branchId);
    }

    public function invalidateCache(string $branchId): void
    {
        Cache::forget($this->cacheKey($branchId));
    }

    private function cacheKey(string $branchId): string
    {
        return "branch_settings_{$branchId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'menu_header_image_url' => null,
            'menu_footer_image_url' => null,
            'menu_tagline' => null,
            'menu_card_style' => 'default',
            'menu_show_branding' => true,
        ];
    }
}
