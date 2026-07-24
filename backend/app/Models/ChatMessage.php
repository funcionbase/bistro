<?php

namespace App\Models;

use App\Support\UrlSanitizer;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * meta_message_id guarda el id de mensaje DEL PROVEEDOR y se usa como
 * idempotency key (unique parcial chat_id + meta_message_id) para
 * evitar duplicados al reintentar el push. El nombre quedo atado a Meta
 * por historia; no se renombra (obliga a migrar el indice y tocar 4
 * archivos por un cambio cosmetico).
 *
 * sent_by_user_id es el operador del panel que lo envio. Queda null en
 * mensajes del cliente, del bot, los historicos y los que el dueño manda
 * desde su propio celular — ahi no hay usuario del panel.
 *
 * media_payload guarda lo estructurado que no cabe en media_path:
 * {lat, lng, name, address} de una ubicacion, {contacts: [{name, phones}]}
 * de un contacto, {file_name, size_bytes, duration_s} de documentos y
 * audio. El body mantiene ademas un texto legible ("[ubicacion] Calle 12")
 * para que el buscador de la bandeja funcione sin decodificar JSON.
 *
 * @property int $chat_id
 * @property string $sender — client | bot | operator
 * @property ?string $sent_by_user_id
 * @property bool $from_device — lo mando el dueño desde su celular, no el panel
 * @property ?string $status — sent | delivered | read | failed
 * @property ?string $failure_reason — codigo corto, nunca el texto del proveedor
 * @property string $body
 * @property ?string $meta_message_id
 * @property ?array<string, mixed> $media_payload
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
        'sent_by_user_id',
        'from_device',
        'status',
        'failure_reason',
        'body',
        'meta_message_id',
        'media_type',
        'media_meta_id',
        'media_path',
        'media_mime',
        'media_payload',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'from_device' => 'boolean',
            'media_payload' => 'array',
        ];
    }

    /**
     * Sanea el body en TODA escritura (recibido o enviado, por cualquier canal):
     * neutraliza esquemas de URL peligrosos y bytes de control. Un solo punto
     * cubre inbound de WhatsApp, salientes del operador/bot, SMS espejo y avisos
     * de orden — sin tocar cada caller. Ver App\Support\UrlSanitizer.
     */
    protected function body(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => UrlSanitizer::neutralizeDangerousSchemes($value));
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

    /**
     * Operador del panel que lo envio. Null en mensajes del cliente, del bot,
     * los historicos y los que salieron del celular del dueño.
     *
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
