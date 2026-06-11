<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Log durable de webhooks entrantes. El receptor lo persiste apenas llega el
 * request — antes de validar firma o procesar — para minimizar perdida.
 *
 * @property int $id
 * @property string $provider
 * @property ?string $event_id
 * @property array $payload
 * @property ?string $signature_header
 * @property bool $signature_valid
 * @property Carbon $received_at
 * @property ?Carbon $processed_at
 * @property ?string $error
 * @property int $attempts
 */
class WebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'event_id',
        'payload',
        'signature_header',
        'signature_valid',
        'received_at',
        'processed_at',
        'error',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
