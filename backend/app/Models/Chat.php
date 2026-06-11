<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ChatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Conversacion entre un cliente (por client_phone) y la empresa.
 *
 * Un chat agrupa todos los mensajes intercambiados con un mismo telefono dentro
 * de una empresa. El status open|closed lo controla el operador desde el panel.
 *
 * bot_paused detiene las respuestas automaticas; cuando un operador toma la
 * conversacion (o cuando el bot pide handoff via API externa) la conversacion
 * queda en pausa hasta que algun operador la reanude.
 *
 * @property string $company_nit
 * @property string $client_phone
 * @property ?string $client_name
 * @property ?int $contact_id
 * @property string $status — open | closed
 * @property string $source — whatsapp | instagram | facebook | otro
 * @property bool $bot_paused
 * @property ?Carbon $handoff_requested_at
 * @property ?string $handoff_reason
 */
class Chat extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ChatFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'client_phone',
        'client_name',
        'contact_id',
        'status',
        'source',
        'bot_paused',
        'handoff_requested_at',
        'handoff_reason',
        'last_message_at',
        'meta_synced_at',
        'meta_conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'meta_synced_at' => 'datetime',
            'handoff_requested_at' => 'datetime',
            'bot_paused' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('sent_at');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function latestMessage(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->latest('sent_at')->limit(1);
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
