<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias legacy de CompanyUser. Representa la membresía básica de un usuario en una empresa.
 *
 * Usado en contextos donde solo se necesita user_id, company_nit y role sin los campos adicionales
 * de CompanyUser. Preferir CompanyUser para nuevas implementaciones.
 */
class CompanyMember extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'company_nit',
        'role',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }
}
