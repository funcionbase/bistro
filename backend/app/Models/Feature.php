<?php

namespace App\Models;

use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de funcionalidades del sistema sobre las que se controla acceso (RBAC).
 *
 * Cada Feature tiene un slug único (ej: 'menu', 'reports', 'deliveries') que se usa
 * como clave en el middleware EnsureFeaturePermission y en los servicios de permisos.
 * Los features se siembran via seeder; no se crean ni modifican en runtime.
 */
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'group',
        'is_owner_only',
    ];

    protected function casts(): array
    {
        return [
            'is_owner_only' => 'boolean',
        ];
    }

    /** @return HasMany<CompanyRolePermission, $this> */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(CompanyRolePermission::class);
    }

    /** @return HasMany<PermissionTemplate, $this> */
    public function permissionTemplates(): HasMany
    {
        return $this->hasMany(PermissionTemplate::class);
    }
}
