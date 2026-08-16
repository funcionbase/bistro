<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Nota privada sobre un cliente del restaurante.
 *
 * Identidad canónica: `contact_id` (FK a contacts). `client_phone`
 * queda como denormalizado legacy para queries pre-refactor; nuevas notas
 * deben usar contact_id.
 *
 * Cross-sede por diseño (no usa BelongsToBranch): un cliente es uno solo para
 * toda la empresa. Soft-delete para conservar trazabilidad ante disputas.
 *
 * @property int $id
 * @property string $company_nit
 * @property ?int $contact_id
 * @property ?string $client_phone
 * @property string $note
 * @property ?int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class ClientNote extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'contact_id',
        'client_phone',
        'note',
        'created_by',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeForContact(Builder $query, string $companyNit, string $contactId): Builder
    {
        return $query->where('company_nit', $companyNit)->where('contact_id', $contactId);
    }

    public function scopeForClient(Builder $query, string $companyNit, string $clientPhone): Builder
    {
        return $query->where('company_nit', $companyNit)->where('client_phone', $clientPhone);
    }
}
