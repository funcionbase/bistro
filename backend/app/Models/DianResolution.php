<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Resolución DIAN activa por (company_nit, document_type, environment).
 *
 * Vehículo del consecutivo legal. La asignación atómica vive en
 * `App\Services\Dian\ResolutionConsecutiveAllocator` con `SELECT ... FOR UPDATE`
 * dentro de `DB::transaction` (regla §2 add-on N-instance).
 *
 * @property string $company_nit
 * @property string $document_type
 * @property string $prefix
 * @property int $range_from
 * @property int $range_to
 * @property int $current_number
 * @property string $resolution_number
 * @property Carbon $valid_from
 * @property Carbon $valid_until
 * @property string $technical_key — encrypted at rest
 * @property string $environment — habilitacion|produccion
 * @property bool $is_active
 */
class DianResolution extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'document_type',
        'prefix',
        'range_from',
        'range_to',
        'current_number',
        'resolution_number',
        'valid_from',
        'valid_until',
        'technical_key',
        'environment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'range_from' => 'integer',
            'range_to' => 'integer',
            'current_number' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'technical_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<ElectronicDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopeForEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    public function isExhausted(): bool
    {
        return $this->current_number >= $this->range_to;
    }

    public function isExpiringSoon(int $daysAhead = 30): bool
    {
        return $this->valid_until->lessThanOrEqualTo(now()->addDays($daysAhead));
    }
}
