<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Define los permisos por defecto para cada role_type del sistema (owner, admin, staff).
 *
 * Sirve como catálogo de plantillas; CompanyRole::createFromTemplate() los lee para
 * crear los CompanyRolePermissions iniciales al crear una empresa.
 * Los registros se siembran via seeder y no se modifican en runtime.
 */
class PermissionTemplate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'role_type',
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

    /** @return BelongsTo<Feature, $this> */
    public function feature()
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }
}
