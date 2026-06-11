<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenciales y configuración del proveedor DIAN activo por empresa.
 *
 * Todos los secretos van con cast `encrypted` — nunca se sirven en GET por
 * el endpoint público (la API los enmascara). Para rotar tokens existe una
 * acción dedicada que escribe el nuevo valor y registra audit
 * (`dian.provider.token_rotated`).
 *
 * UNIQUE parcial en BD: una sola fila con `is_active=true` por empresa.
 *
 * @property string $company_nit
 * @property string $provider_slug — mock|factura1|siigo|...
 * @property ?string $api_base_url
 * @property ?string $api_token_encrypted — encrypted at rest
 * @property ?string $software_id
 * @property ?string $software_pin_encrypted — encrypted at rest
 * @property ?string $test_set_id
 * @property string $environment — habilitacion|produccion
 * @property ?string $webhook_secret_encrypted — encrypted at rest
 * @property bool $is_active
 */
class DianProviderConfig extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'provider_slug',
        'api_base_url',
        'api_token_encrypted',
        'software_id',
        'software_pin_encrypted',
        'test_set_id',
        'environment',
        'webhook_secret_encrypted',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_token_encrypted' => 'encrypted',
            'software_pin_encrypted' => 'encrypted',
            'webhook_secret_encrypted' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function isMock(): bool
    {
        return $this->provider_slug === 'mock';
    }

    public function isProduction(): bool
    {
        return $this->environment === 'produccion';
    }
}
