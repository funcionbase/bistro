<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de recuperación de cuenta por cambio de correo (ver migración).
 *
 * @property string $target_user_id — cuenta dueña de la cédula, a mover
 * @property string|null $requested_by_user_id — user huérfano con el correo nuevo
 * @property string $new_email
 * @property string|null $new_google_id
 * @property string $token_hash — SHA-256 del token crudo
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class AccountEmailChangeRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'target_user_id',
        'requested_by_user_id',
        'new_email',
        'new_google_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
