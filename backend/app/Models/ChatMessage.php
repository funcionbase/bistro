<?php

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mensaje individual dentro de un Chat.
 *
 * Los mensajes son inmutables: solo guardamos sent_at y omitimos
 * created_at/updated_at para reducir disco y costo de escritura. La
 * direccion se deriva de sender (client → inbound, bot/operator →
 * outbound), por eso no almacenamos una columna aparte.
 *
 * meta_message_id permite cachear mensajes ya recibidos desde la API
 * de Meta y se usa como idempotency key (unique parcial chat_id +
 * meta_message_id) para evitar duplicados al reintentar el push.
 *
 * @property int $chat_id
 * @property string $sender — client | bot | operator
 * @property ?string $status — sent | delivered | read | failed
 * @property string $body
 * @property ?string $meta_message_id
 * @property Carbon $sent_at
 */
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'chat_id',
        'sender',
        'status',
        'body',
        'meta_message_id',
        'media_type',
        'media_meta_id',
        'media_path',
        'media_mime',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** Direccion derivada del sender — no es columna en BD. */
    public function getDirectionAttribute(): string
    {
        return $this->sender === 'client' ? 'inbound' : 'outbound';
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
