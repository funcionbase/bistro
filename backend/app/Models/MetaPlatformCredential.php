<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Credenciales de la app de Meta de flexyflow (Tech Provider/BSP).
 *
 * Una sola fila activa por ambiente (qa | production). Almacena los secretos
 * cifrados en reposo via cast `encrypted`. Se usa para iniciar Embedded Signup,
 * intercambiar el code de autorizacion, validar firmas HMAC del webhook y
 * gestionar las WABAs de los clientes en nombre de flexyflow.
 *
 * @property string $app_id
 * @property string $app_secret_encrypted
 * @property string $business_id
 * @property string $system_user_id
 * @property string $system_user_token_encrypted
 * @property string $config_id
 * @property ?string $solution_id
 * @property string $webhook_verify_token_encrypted
 * @property string $graph_api_version
 * @property string $environment
 * @property bool $is_active
 * @property ?Carbon $rotated_at
 */
class MetaPlatformCredential extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'app_secret_encrypted',
        'business_id',
        'system_user_id',
        'system_user_token_encrypted',
        'config_id',
        'solution_id',
        'webhook_verify_token_encrypted',
        'graph_api_version',
        'environment',
        'is_active',
        'rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rotated_at' => 'datetime',
            'app_secret_encrypted' => 'encrypted',
            'system_user_token_encrypted' => 'encrypted',
            'webhook_verify_token_encrypted' => 'encrypted',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    public static function activeForCurrentEnvironment(): ?self
    {
        $env = app()->environment('production') ? 'production' : 'qa';

        return static::query()->active()->forEnvironment($env)->first();
    }

    public function appSecret(): string
    {
        return (string) $this->app_secret_encrypted;
    }

    public function systemUserToken(): string
    {
        return (string) $this->system_user_token_encrypted;
    }

    public function webhookVerifyToken(): string
    {
        return (string) $this->webhook_verify_token_encrypted;
    }
}
