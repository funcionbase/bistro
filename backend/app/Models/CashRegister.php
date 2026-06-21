<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Caja física de una sede (multi-caja, #117). Entidad persistente con nombre
 * estable ("Caja 1", "Barra", "Domicilios"); los turnos
 * (`CashRegisterSession`) cuelgan de una caja.
 *
 * Contable: NO se borra físicamente (se archiva vía `archived_at`) para
 * preservar la FK desde sesiones/receipts históricos.
 *
 * @property string $company_nit
 * @property string $branch_id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
class CashRegister extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'name',
        'is_active',
        'sort_order',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CashRegisterSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class, 'cash_register_id');
    }

    /** @param Builder<self> $query */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    /** Cajas vigentes (no archivadas). @param Builder<self> $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
