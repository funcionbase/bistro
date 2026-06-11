<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración dinámica por empresa almacenada como pares key/value tipados.
 *
 * El campo 'type' determina cómo se convierte el valor string: boolean, integer, json, string.
 * getCastedValue() retorna el valor en el tipo correcto; castToString() convierte de vuelta para persistir.
 * Los valores se cachean a nivel de CompanySettingsService; no leer directamente en queries frecuentes.
 */
class CompanySetting extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'key',
        'value',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @param Builder<static> $query */
    public function scopeForCompany(Builder $query, string $companyNit): void
    {
        $query->where('company_nit', $companyNit);
    }

    public function getCastedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function castToString(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}
