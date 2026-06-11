<?php

namespace App\Models;

use Database\Factories\CompanyRoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol dentro de una empresa. Los roles del sistema no pueden ser modificados ni eliminados desde la API.
 *
 * is_system=true: indica un rol de sistema (owner, admin, employee) creado automáticamente al registrar la empresa.
 * Los roles de sistema omiten la verificación RBAC en EnsureFeaturePermission.
 * createFromTemplate() crea el rol y sus permisos iniciales desde permission_templates según el tipo de rol.
 *
 * @property bool $is_system — si true, bloquea mutación y eliminación desde la API
 * @property string|null $color — color hex para el badge de rol en el frontend
 */
class CompanyRole extends Model
{
    /** @use HasFactory<CompanyRoleFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'description',
        'is_system',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return HasMany<CompanyRolePermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(CompanyRolePermission::class);
    }

    /** @return HasMany<CompanyUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public static function createFromTemplate(string $roleType, string $companyNit, bool $isSystem = true): self
    {
        $templates = PermissionTemplate::where('role_type', $roleType)->get();

        abort_if($templates->isEmpty(), 500, "No existen plantillas para el rol '{$roleType}'.");

        $roleName = config("roles.role_names.{$roleType}", $roleType);
        $roleColor = config("roles.role_colors.{$roleType}");

        $role = static::create([
            'company_nit' => $companyNit,
            'name' => $roleName,
            'description' => "Rol {$roleName} creado automáticamente",
            'is_system' => $isSystem,
            'color' => $roleColor,
        ]);

        $permissionData = $templates->map(fn ($template) => [
            'feature_id' => $template->feature_id,
            'can_create' => $template->can_create,
            'can_read' => $template->can_read,
            'can_update' => $template->can_update,
            'can_delete' => $template->can_delete,
        ])->all();

        $role->permissions()->createMany($permissionData);

        return $role;
    }
}
