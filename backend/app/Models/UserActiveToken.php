<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastrea los tokens JWT activos de un usuario para permitir invalidación de sesiones por dispositivo.
 *
 * La columna 'signature' almacena la firma del JWT (último segmento); se usa para invalidar
 * selectivamente sin blacklist global. Se limpia al expirar via JwtService o logout.
 */
class UserActiveToken extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'signature',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
