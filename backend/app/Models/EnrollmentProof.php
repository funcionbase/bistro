<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Referencia al documento de propiedad subido durante el enrolamiento de la
 * empresa (issue #154). El archivo vive en S3 (`disk` + `s3_key`); esta fila
 * sólo guarda metadatos para localizarlo y auditarlo.
 *
 * Inmutable después de crearse: la app no expone endpoints de mutación. El
 * único flujo válido para "reemplazar" la evidencia es un re-upload (otro
 * issue) que crea una nueva fila tras soft-deletear la actual.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $disk — disk name de filesystems.php (default: s3_documents)
 * @property string $s3_key — key completa dentro del bucket
 * @property string $mime_type — MIME real validado por contenido
 * @property int $file_size — bytes
 * @property string $original_filename
 * @property int $uploaded_by_user_id
 * @property Carbon $uploaded_at
 */
class EnrollmentProof extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'disk',
        's3_key',
        'mime_type',
        'file_size',
        'original_filename',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
