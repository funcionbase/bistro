<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario de la plataforma. Puede pertenecer a múltiples empresas con roles distintos.
 *
 * Sin SoftDeletes: los usuarios no se borran lógicamente; su acceso se revoca por membresía.
 * Estado 'pending_enrollment': usuario autenticado que aún no completó sus datos personales.
 * Relación con empresas: a través de CompanyUser (tabla pivote con campos adicionales).
 * companyMemberships() es alias de companyUsers() para compatibilidad.
 *
 * @property string $id
 * @property string $google_id
 * @property string $name — columna GENERADA (stored): first_name + last_name. Solo lectura.
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $cedula
 * @property string $email
 * @property string $status — active | pending_enrollment | inactive
 */
class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    /**
     * Determina si el usuario tiene datos personales incompletos.
     */
    public function needsProfileCompletion(): bool
    {
        return empty($this->first_name) || empty($this->last_name) || empty($this->cedula);
    }

    /**
     * `name` se omite a propósito: es una columna generada (stored) derivada de
     * first_name + last_name. Asignarla dispararía un error en PostgreSQL.
     *
     * @var list<string>
     */
    protected $fillable = [
        'google_id',
        'first_name',
        'last_name',
        'cedula',
        'email',
        'password',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isPendingEnrollment(): bool
    {
        return $this->status === 'pending_enrollment';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<UserAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(UserAcceptance::class);
    }

    /** @return HasMany<CompanyUser, $this> */
    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function scopeDeliverers(Builder $query, string $companyNit): Builder
    {
        return $query
            ->whereHas('companyUsers', fn ($q) => $q->where('company_nit', $companyNit)
                ->whereHas('role.permissions.feature', fn ($p) => $p->where('slug', 'deliveries.read'))
            );
    }

    /**
     * Relación: compañías a las que pertenece el usuario (hasManyThrough).
     *
     * @return HasManyThrough<Company, CompanyUser>
     */
    public function companies()
    {
        return $this->hasManyThrough(
            Company::class,
            CompanyUser::class,
            'user_id', // Foreign key on CompanyUser
            'nit',     // Foreign key on Company
            'id',      // Local key on User
            'company_nit' // Local key on CompanyUser
        );
    }

    /**
     * Alias legacy para compatibilidad: companyMemberships() == companyUsers().
     *
     * @return HasMany<CompanyUser>
     */
    public function companyMemberships()
    {
        return $this->companyUsers();
    }

    /**
     * Sedes a las que el usuario tiene acceso (a través de branch_users).
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_users', 'user_id', 'branch_id')
            ->withPivot(['granted_by_user_id', 'granted_at'])
            ->withTimestamps();
    }

    /**
     * Sedes accesibles dentro de una empresa específica, excluyendo archivadas.
     *
     * Owners (is_system=true) ven todas; el resto sólo las del pivot. La lógica
     * de owner-bypass vive en los callers (AuthController, HandleInertiaRequests)
     * porque depende de la empresa activa, que aquí no se conoce.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function accessibleBranches(string $companyNit): BelongsToMany
    {
        return $this->branches()
            ->where('branches.company_nit', $companyNit)
            ->whereNull('branches.archived_at');
    }

    /**
     * Verifica si el usuario tiene acceso a la sede dada vía pivot.
     * Para chequeo de owner-bypass, validar `role->is_system` en el caller.
     */
    public function canAccessBranch(string $branchId): bool
    {
        return $this->branches()->whereKey($branchId)->exists();
    }
}
