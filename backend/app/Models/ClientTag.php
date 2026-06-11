<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Etiqueta sobre un cliente del restaurante.
 *
 * Identidad canónica desde #235: `contact_id` (FK a contacts). UNIQUE parcial
 * en (company_nit, contact_id, tag) impide duplicados. El UNIQUE legacy por
 * phone subsiste para filas históricas sin contact_id.
 *
 * Append/remove only: hard-delete; el audit_log preserva trazabilidad.
 * No tiene updated_at (no se edita, se borra y se crea).
 *
 * @property int $id
 * @property string $company_nit
 * @property ?int $contact_id
 * @property ?string $client_phone
 * @property string $tag
 * @property ?int $created_by
 * @property Carbon $created_at
 */
class ClientTag extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'contact_id',
        'client_phone',
        'tag',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

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
