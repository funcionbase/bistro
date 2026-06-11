<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento auditable del ciclo de vida de una WhatsApp account.
 *
 * Sirve para soporte: en que paso fallo el cliente, cuando se genero el token,
 * quien aprobo el Display Name, etc. Inmutable: solo created_at.
 *
 * @property int $company_whatsapp_account_id
 * @property string $event_type
 * @property ?array $payload
 * @property ?int $actor_user_id
 */
class CompanyWhatsappAccountEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_whatsapp_account_id',
        'event_type',
        'payload',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CompanyWhatsappAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsappAccount::class, 'company_whatsapp_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
