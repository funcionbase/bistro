<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Proveedor por empresa. `archived_at` soft-archive — sus PO históricas
 * permanecen accesibles aunque ya no aparezca en listados activos.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $name
 * @property string|null $document_type
 * @property string|null $document_number
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property int $payment_terms_days
 * @property string|null $notes
 * @property Carbon|null $archived_at
 */
class Supplier extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'document_type',
        'document_number',
        'contact_name',
        'email',
        'phone',
        'address',
        'payment_terms_days',
        'notes',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return BelongsToMany<Ingredient, $this> */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'supplier_ingredients')
            ->withPivot(['last_unit_cost', 'last_purchased_at'])
            ->withTimestamps();
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->whereNotNull('archived_at');
    }
}
