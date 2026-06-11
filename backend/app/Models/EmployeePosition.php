<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cargo del colaborador. Catálogo cerrado del sistema (is_system=true,
 * company_nit=null) + cargos custom por empresa.
 *
 * @property string $id
 * @property ?string $company_nit
 * @property string $slug
 * @property string $label
 * @property bool $is_system
 * @property ?string $color
 */
class EmployeePosition extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'slug',
        'label',
        'is_system',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
