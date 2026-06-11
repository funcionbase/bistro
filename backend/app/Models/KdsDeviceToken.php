<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Token persistente de dispositivo KDS (#115).
 *
 * Asocia una tableta física a una estación de cocina específica. La columna
 * `token_hash` guarda SHA-256 del valor en claro — el claro se devuelve UNA
 * sola vez al generar (copy-once). No persiste el claro en ningún log ni
 * tabla.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $branch_id — uuid
 * @property int $station_id
 * @property string $token_hash — SHA-256 hex (64 chars)
 * @property ?string $label
 * @property ?Carbon $last_seen_at
 * @property ?string $last_ip
 * @property ?Carbon $revoked_at
 */
class KdsDeviceToken extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'station_id',
        'token_hash',
        'label',
        'last_seen_at',
        'last_ip',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KdsStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(KdsStation::class, 'station_id', 'id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @param  Builder<KdsDeviceToken>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Calcula el hash SHA-256 del valor en claro. Usar tanto en generación
     * como en verificación para que ambos lados sean simétricos.
     */
    public static function hashToken(string $clearToken): string
    {
        return hash('sha256', $clearToken);
    }
}
