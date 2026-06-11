<?php

namespace App\Models;

use Database\Factories\CompanyUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membresía de un usuario en una empresa. Tabla pivote con campos adicionales (alias CompanyMember).
 *
 * Un usuario puede pertenecer a múltiples empresas con roles distintos en cada una.
 * La FK company_nit referencia companies.nit (string), no el id entero.
 * Estado: active | inactive. Solo los miembros activos pueden operar en la empresa.
 *
 * @property string $company_nit — FK a companies.nit
 * @property int $user_id
 * @property int $company_role_id
 * @property string $status — active | inactive
 */
class CompanyUser extends Model
{
    /** @use HasFactory<CompanyUserFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'user_id',
        'company_role_id',
        'status',
    ];

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Company, $this> */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CompanyRole, $this> */
    public function role()
    {
        return $this->belongsTo(CompanyRole::class, 'company_role_id');
    }
}
