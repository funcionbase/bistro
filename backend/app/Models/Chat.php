<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\PhoneNumber;
use Database\Factories\ChatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * whatsapp_account_id es el canal por el que entro la conversacion. Se responde
 * SIEMPRE por ese canal: reasignar el chat a otra sede cambia quien lo atiende,
 * no el numero desde el que sale la respuesta. Queda null en chats creados por
 * el bot externo en empresas sin canal conectado (chats legacy).
 *
 * pending_reply_since marca desde cuando el cliente espera respuesta. Es
 * distinto de last_message_at, que mezcla "el cliente escribio hace 20 min" con
 * "le respondimos hace 20 min".
 *
 * @property string $company_nit
 * @property ?string $whatsapp_account_id
 * @property string $client_phone
 * @property ?string $client_name
 * @property ?int $contact_id
 * @property string $status — open | closed
 * @property string $source — whatsapp | instagram | facebook | otro
 * @property bool $bot_paused
 * @property ?Carbon $handoff_requested_at
 * @property ?string $handoff_reason
 * @property ?Carbon $pending_reply_since
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
        'whatsapp_account_id',
        'client_phone',
        'client_name',
        'contact_id',
        'status',
        'source',
        'bot_paused',
        'handoff_requested_at',
        'handoff_reason',
        'last_message_at',
        'pending_reply_since',
        'meta_synced_at',
        'meta_conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'pending_reply_since' => 'datetime',
            'meta_synced_at' => 'datetime',
            'handoff_requested_at' => 'datetime',
            'bot_paused' => 'boolean',
        ];
    }

    /**
     * `client_phone` se guarda en el canónico `57XXXXXXXXXX` (con indicativo, sin
     * `+`), igual que Contact/Order: el webhook de WhatsApp entrega E.164
     * (`+57...`) y sin normalizar creaba un hilo/contacto distinto del de SMS/CRM.
     * Las búsquedas por teléfono (firstOrNew/where) deben normalizar antes.
     */
    protected function clientPhone(): Attribute
    {
        return Attribute::set(fn (?string $value): string => PhoneNumber::toColombianCanonical($value));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<CompanyWhatsappAccount, $this> */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsappAccount::class, 'whatsapp_account_id');
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

    /**
     * Canal por el que se habla con este cliente.
     *
     * Se responde SIEMPRE por el canal que originó la conversación, nunca por
     * el de la sede que la atiende: reasignar un chat de sede cambia quién
     * responde, no el número desde el que sale la respuesta.
     *
     * Fallback al canal de empresa para los chats legacy (creados por el bot
     * externo antes de que hubiera canal conectado).
     */
    public function resolveWhatsappChannel(): ?CompanyWhatsappAccount
    {
        if ($this->whatsapp_account_id !== null) {
            return $this->whatsappAccount()->first();
        }

        return CompanyWhatsappAccount::query()
            ->where('company_nit', $this->company_nit)
            ->whereNull('branch_id')
            ->first();
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
