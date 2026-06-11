<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro inmutable de acciones auditables del sistema.
 *
 * Los registros de auditoría no deben borrarse ni modificarse: representan el historial de cambios.
 * El campo auditable es polimórfico (auditable_type + auditable_id).
 *
 * @property array{before?: array<string, mixed>, after?: array<string, mixed>, actor_id?: int}|null $data
 *                                                                                                         Contexto adicional del evento: estado previo, estado posterior y actor responsable.
 * @property string $action — identificador del evento (e.g. 'delivery.assigned', 'invoice.overdue')
 */
class AuditLog extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'data',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
