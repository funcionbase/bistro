<?php

namespace App\Models;

use App\Listeners\AbortIfSuppressed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dirección de correo suprimida de envíos transaccionales.
 *
 * Se pobla desde el webhook SES (SNS bounce/complaint) o manualmente
 * desde admin (por solicitud del usuario via reply al correo). El
 * listener {@see AbortIfSuppressed} consulta esta tabla
 * antes de cada envío.
 *
 * @property int $id
 * @property string $email
 * @property string $reason bounce | complaint | manual
 * @property string|null $subtype hard | soft | transient | abuse | ...
 * @property array|null $metadata Payload SNS original para auditoría
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $created_by_user_id
 */
class EmailSuppression extends Model
{
    use HasUuids;

    public const REASON_BOUNCE = 'bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_MANUAL = 'manual';

    public const REASONS = [
        self::REASON_BOUNCE,
        self::REASON_COMPLAINT,
        self::REASON_MANUAL,
    ];

    /**
     * Subtipos de bounce reportados por SES SNS. Hard bounce = permanente
     * (mailbox doesn't exist); soft/transient = temporal (mailbox full,
     * server down) y pueden expirar; undetermined = no clasificado.
     */
    public const BOUNCE_SUBTYPES = [
        'general',
        'noemail',
        'suppressed',
        'on-account-suppression-list',
        'mailbox-full',
        'message-too-large',
        'content-rejected',
        'attachment-rejected',
    ];

    protected $fillable = [
        'email',
        'reason',
        'subtype',
        'metadata',
        'received_at',
        'expires_at',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'received_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Suppressions activas (sin expirar).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('expires_at');
    }

    /**
     * Suppressions permanentes (hard bounce o complaint sin expiración).
     */
    public function scopePermanent(Builder $query): Builder
    {
        return $query->whereNull('expires_at')
            ->whereIn('reason', [self::REASON_BOUNCE, self::REASON_COMPLAINT]);
    }

    /**
     * Lookup case-insensitive por email. Usa el índice parcial creado en
     * la migration para evitar full scan.
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
    }
}
