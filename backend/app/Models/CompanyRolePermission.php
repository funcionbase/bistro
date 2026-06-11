<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permiso RBAC de un rol sobre una feature específica.
 *
 * Estructura: can_create / can_read / can_update / can_delete (boolean por acción).
 * Los roles is_system=true omiten esta verificación y tienen acceso completo.
 * Cada fila vincula company_role_id + feature_id con sus 4 flags de acción.
 */
class CompanyRolePermission extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_role_id',
        'feature_id',
        'can_create',
        'can_read',
        'can_update',
        'can_delete',
    ];

    protected function casts(): array
    {
        return [
            'can_create' => 'boolean',
            'can_read' => 'boolean',
            'can_update' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    /** @return BelongsTo<CompanyRole, $this> */
    public function role()
    {
        return $this->belongsTo(CompanyRole::class, 'company_role_id');
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature()
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }
}
