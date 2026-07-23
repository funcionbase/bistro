<?php

namespace App\Models;

use Database\Factories\CompanyWhatsappAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Canal de WhatsApp de una empresa cliente.
 *
 * El nombre de la tabla (`company_whatsapp_accounts`) quedo impreciso: desde F1
 * una fila puede ser el canal de la empresa (branch_id NULL) o el canal de una
 * sede (branch_id UUID). Se conserva el nombre a proposito — renombrar bajo un
 * deploy con dos versiones vivas obliga a un doble deploy coreografiado.
 *
 * Invariante en BD (indices unicos parciales): maximo un canal de empresa +
 * maximo un canal por sede.
 *
 * Provisioning_mode y las columnas de waba y naas son del camino Meta Cloud API
 * y se podan cuando no queden cuentas Meta activas.
 *
 * @property string $company_nit
 * @property ?string $branch_id — NULL = canal de empresa
 * @property ?string $label
 * @property string $provisioning_mode
 * @property string $status
 * @property ?string $waba_id
 * @property ?string $phone_number_id
 * @property ?string $business_id
 * @property ?string $phone_e164
 * @property ?string $display_name
 * @property ?string $access_token_encrypted
 * @property ?string $webhook_verify_token_encrypted
 * @property ?string $evo_server_url
 * @property ?string $evo_instance
 * @property ?string $evo_token_encrypted
 * @property ?string $inbound_secret_encrypted
 * @property ?Carbon $last_connection_check_at
 * @property ?Carbon $connected_at
 */
class CompanyWhatsappAccount extends Model
{
    /** @use HasFactory<CompanyWhatsappAccountFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'label',
        'provisioning_mode',
        'status',
        'waba_id',
        'phone_number_id',
        'business_id',
        'phone_e164',
        'display_name',
        'display_name_status',
        'quality_rating',
        'messaging_tier',
        'verified_name',
        'is_business_verified',
        'access_token_encrypted',
        'webhook_verify_token_encrypted',
        'webhook_subscribed_at',
        'connected_at',
        'disconnected_at',
        'last_synced_at',
        'last_error',
        'naas_provider',
        'naas_provider_account_ref',
        'naas_contract_id',
        'evo_server_url',
        'evo_instance',
        'evo_token_encrypted',
        'inbound_secret_encrypted',
        'last_connection_check_at',
    ];

    protected function casts(): array
    {
        return [
            'is_business_verified' => 'boolean',
            'webhook_subscribed_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_connection_check_at' => 'datetime',
            'access_token_encrypted' => 'encrypted',
            'webhook_verify_token_encrypted' => 'encrypted',
            'evo_token_encrypted' => 'encrypted',
            'inbound_secret_encrypted' => 'encrypted',
        ];
    }

    /** @var array<string, mixed> */
    protected $hidden = [
        'access_token_encrypted',
        'webhook_verify_token_encrypted',
        'evo_token_encrypted',
        'inbound_secret_encrypted',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Chat, $this> */
    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'whatsapp_account_id');
    }

    /** El canal atiende a toda la empresa (no a una sede puntual). */
    public function isCompanyWide(): bool
    {
        return $this->branch_id === null;
    }

    /** @return HasMany<CompanyWhatsappAccountEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CompanyWhatsappAccountEvent::class)->latest();
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', 'connected');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'verifying']);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function accessToken(): ?string
    {
        return $this->access_token_encrypted;
    }

    /** Token de la instancia de Evolution (mensajeria de ESTE canal). */
    public function evoToken(): ?string
    {
        return $this->evo_token_encrypted;
    }

    /** Secreto del header con el que se autentica el webhook entrante (§6.3). */
    public function inboundSecret(): ?string
    {
        return $this->inbound_secret_encrypted;
    }

    /** El canal esta operativo para enviar por Evolution. */
    public function canSendViaEvolution(): bool
    {
        return $this->isConnected()
            && ! empty($this->evo_instance)
            && ! empty($this->evoToken());
    }
}
