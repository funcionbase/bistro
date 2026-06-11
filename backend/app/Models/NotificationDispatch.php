<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * #257 — Registro append-only de notificaciones billing despachadas.
 *
 * UNIQUE(notification_class, idempotency_key, user_id) garantiza que un
 * mismo evento jamas se envie dos veces al mismo user. Permite consultar
 * "que correos recibio el user X" o "que correos se enviaron a la empresa Y".
 *
 * Borrado: SoftDeletes (politica global del proyecto — nunca hard delete). El
 * soft delete mantiene la fila y por ende el UNIQUE sigue bloqueando reenvios;
 * para forzar un reenvio manual, ops borra fisicamente la fila o cambia la
 * idempotency_key. La PK es UUID v7 (HasUuids → Str::uuid7()).
 *
 * @property string $id UUID v7 (PK).
 * @property string $notification_class FQN de la Notification disparada.
 * @property string $idempotency_key Estable por instancia logica del evento.
 * @property string $user_id Receptor (uuid, FK a users.id).
 * @property ?string $company_nit Empresa asociada (denormalizada).
 * @property string $target_email Snapshot del email al momento del envio.
 * @property CarbonImmutable $sent_at
 * @property ?array<string, mixed> $metadata
 */
class NotificationDispatch extends Model
{
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'notification_class',
        'idempotency_key',
        'user_id',
        'company_nit',
        'target_email',
        'sent_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

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

    /** @param Builder<NotificationDispatch> $query */
    public function scopeForCompany(Builder $query, string $companyNit): void
    {
        $query->where('company_nit', $companyNit);
    }

    /** @param Builder<NotificationDispatch> $query */
    public function scopeOfClass(Builder $query, string $notificationClass): void
    {
        $query->where('notification_class', $notificationClass);
    }
}
