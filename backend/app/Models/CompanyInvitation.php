<?php

namespace App\Models;

use Database\Factories\CompanyInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitación de un usuario a unirse a una empresa con un rol específico.
 *
 * El token es único y se invalida tras la aceptación (status → 'accepted').
 * Una invitación expirada no puede ser aceptada; el controlador lo verifica con isExpired().
 * No se puede re-invitar a un usuario que ya tiene membresía activa en la empresa.
 * La expiración es configurable en config/roles.php.
 *
 * @property string $token — token único de invitación (invalidado tras aceptación)
 * @property string $status — pending | accepted | expired
 */
class CompanyInvitation extends Model
{
    /** @use HasFactory<CompanyInvitationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'email',
        'role',
        'company_role_id',
        'token',
        'status',
        'expires_at',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<CompanyRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(CompanyRole::class, 'company_role_id');
    }
}
